<?php

/**
 * @package JcbInOut
 */

namespace Yepr\Component\Jcbinout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Component\Jcbinout\Administrator\Blueprint\ImportRun;

/**
 * An import in progress: how far it has got, and what it tells the next request.
 *
 * All the arithmetic that decides where a resumed import starts writing is
 * here, which is why it is here and not in the writer. Get the cursor wrong in
 * one direction and rows are written twice; wrong in the other and they are
 * never written at all, and neither is visible from a green test of the writer.
 */
final class ImportRunTest extends TestCase
{
    private function plan(int $operations, string $mode = 'initialize'): array
    {
        $list = [];

        for ($i = 0; $i < $operations; $i++) {
            $list[] = [
                'entity' => 'field',
                'value'  => 'guid-' . $i,
                'action' => 'insert',
            ];
        }

        return ['mode' => $mode, 'operations' => $list, 'counts' => []];
    }

    // -- where it has got to -------------------------------------------------

    public function testAFreshRunHasDoneNothing(): void
    {
        $run = new ImportRun($this->plan(10));

        $this->assertSame(10, $run->total());
        $this->assertSame(0, $run->position());
        $this->assertSame(10, $run->remaining());
        $this->assertFalse($run->isFinished());
        $this->assertSame(0, $run->percentage());
    }

    /**
     * A plan with nothing in it is finished, not stuck at nowhere.
     *
     * Otherwise the bar sits at zero looking like work that never starts, and
     * the run never clears itself.
     */
    public function testAnEmptyPlanIsAlreadyFinished(): void
    {
        $run = new ImportRun($this->plan(0));

        $this->assertTrue($run->isFinished());
        $this->assertSame(100, $run->percentage());
    }

    public function testTheSliceIsWhatIsLeft(): void
    {
        $run = new ImportRun($this->plan(10));

        $run->record(['applied' => 4, 'consumed' => 4]);

        $slice = $run->slice();

        $this->assertCount(6, $slice['operations']);
        $this->assertSame('guid-4', $slice['operations'][0]['value']);
        $this->assertSame('initialize', $slice['mode'], 'a slice is a plan, so it carries the mode');
    }

    public function testRecordingSlicesWalksToTheEnd(): void
    {
        $run = new ImportRun($this->plan(10));

        $run->record(['applied' => 4, 'consumed' => 4]);
        $run->record(['applied' => 4, 'consumed' => 4]);

        $this->assertSame(80, $run->percentage());
        $this->assertFalse($run->isFinished());

        $run->record(['applied' => 2, 'consumed' => 2]);

        $this->assertTrue($run->isFinished());
        $this->assertSame([], $run->slice()['operations']);
        $this->assertSame(3, $run->slices());
    }

    // -- what moves the cursor -----------------------------------------------

    /**
     * The cursor follows what was consumed, not what was written.
     *
     * A row that was skipped or that failed has still been dealt with. Advancing
     * by `applied` alone would offer a failing row again on every slice, and the
     * import would never end.
     */
    public function testARowThatFailedIsStillARowThatIsDoneWith(): void
    {
        $run = new ImportRun($this->plan(3));

        $run->record([
            'applied'  => 1,
            'failed'   => 1,
            'skipped'  => 1,
            'consumed' => 3,
            'results'  => [['entity' => 'field', 'value' => 'guid-1', 'error' => 'no such column']],
        ]);

        $this->assertTrue($run->isFinished());
        $this->assertSame(['applied' => 1, 'failed' => 1, 'skipped' => 1], $run->counts());
    }

    /**
     * A writer that stopped early said so, and the run believes it.
     *
     * This is the whole of slicing: the deadline cut the slice at four of ten,
     * and the next request has to start at four - not at ten because the plan
     * held ten, and not at one because only one was written.
     */
    public function testAShortSliceLeavesTheRestForNextTime(): void
    {
        $run = new ImportRun($this->plan(10));

        $run->record(['applied' => 3, 'skipped' => 1, 'consumed' => 4]);

        $this->assertSame(4, $run->position());
        $this->assertSame(6, $run->remaining());
    }

    /**
     * An older writer that reported no `consumed` is read from its counts.
     */
    public function testACountedResultStillAdvancesTheCursor(): void
    {
        $run = new ImportRun($this->plan(10));

        $run->record(['applied' => 2, 'failed' => 1, 'skipped' => 1]);

        $this->assertSame(4, $run->position());
    }

    public function testTheCursorNeverRunsPastThePlan(): void
    {
        $run = new ImportRun($this->plan(3));

        $run->record(['applied' => 99, 'consumed' => 99]);

        $this->assertSame(3, $run->position());
        $this->assertTrue($run->isFinished());
        $this->assertSame(100, $run->percentage());
    }

    // -- a ceiling on a slice ------------------------------------------------

    /**
     * A limit caps a slice however much time there is.
     *
     * The deadline is about the request surviving; this is about the host
     * surviving, and a shared one is short of more than execution time.
     */
    public function testALimitCapsTheSlice(): void
    {
        $run = new ImportRun($this->plan(33), limit: 10);

        $this->assertCount(10, $run->slice()['operations']);
        $this->assertSame('guid-0', $run->slice()['operations'][0]['value']);

        $run->record(['applied' => 10, 'consumed' => 10]);

        $slice = $run->slice();

        $this->assertCount(10, $slice['operations']);
        $this->assertSame('guid-10', $slice['operations'][0]['value']);
    }

    public function testTheLastSliceIsWhateverIsLeftOfIt(): void
    {
        $run = new ImportRun($this->plan(33), limit: 10);

        $run->record(['applied' => 30, 'consumed' => 30]);

        $this->assertCount(3, $run->slice()['operations']);
    }

    /**
     * And it is the operator's choice for this import, so it survives the trip
     * through the session that every slice after the first makes.
     */
    public function testTheLimitTravelsWithTheRun(): void
    {
        $run = ImportRun::fromArray((new ImportRun($this->plan(33), limit: 5))->toArray());

        $this->assertNotNull($run);
        $this->assertSame(5, $run->limit());
        $this->assertCount(5, $run->slice()['operations']);
    }

    public function testNoLimitMeansTheWholeOfWhatIsLeft(): void
    {
        $run = new ImportRun($this->plan(33));

        $this->assertSame(0, $run->limit());
        $this->assertCount(33, $run->slice()['operations']);
    }

    // -- the checkpoint ------------------------------------------------------

    /**
     * A request killed mid-slice leaves a checkpoint ahead of the cursor.
     *
     * It never got to report, so the counts are lost - but the rows are in JCB,
     * and starting again from the cursor would write them a second time.
     */
    public function testACheckpointCarriesTheCursorForward(): void
    {
        $run = new ImportRun($this->plan(10));

        $run->checkpoint(6);

        $this->assertSame(6, $run->position());
        $this->assertSame(
            ['applied' => 0, 'failed' => 0, 'skipped' => 0],
            $run->counts(),
            'a checkpoint says where, not what: the counts of a dead slice are gone'
        );
    }

    /**
     * And never backwards. A stale checkpoint from an earlier slice would
     * otherwise rewind a run that has since got further.
     */
    public function testAStaleCheckpointIsIgnored(): void
    {
        $run = new ImportRun($this->plan(10));

        $run->record(['applied' => 8, 'consumed' => 8]);
        $run->checkpoint(3);

        $this->assertSame(8, $run->position());
    }

    // -- failures ------------------------------------------------------------

    /**
     * Failures are kept, but not without limit: the run travels in the session.
     */
    public function testFailuresAreKeptUpToALimitAndThenCounted(): void
    {
        $run      = new ImportRun($this->plan(200));
        $failures = [];

        for ($i = 0; $i < 60; $i++) {
            $failures[] = ['entity' => 'field', 'value' => 'guid-' . $i, 'error' => 'nope'];
        }

        $run->record(['failed' => 60, 'consumed' => 60, 'results' => $failures]);

        $this->assertCount(50, $run->failures());
        $this->assertSame(10, $run->failuresDropped());
        $this->assertSame(60, $run->counts()['failed'], 'all of them are still counted');
    }

    // -- travelling between requests -----------------------------------------

    public function testARunSurvivesBeingStoredAndReadBack(): void
    {
        $run = new ImportRun($this->plan(10, 'reset'));

        $run->record([
            'applied'  => 3,
            'failed'   => 1,
            'consumed' => 4,
            'results'  => [['entity' => 'field', 'value' => 'guid-3', 'error' => 'nope']],
        ]);

        $again = ImportRun::fromArray($run->toArray());

        $this->assertNotNull($again);
        $this->assertSame('reset', $again->mode());
        $this->assertSame(4, $again->position());
        $this->assertSame(10, $again->total());
        $this->assertSame($run->counts(), $again->counts());
        $this->assertSame($run->failures(), $again->failures());
        $this->assertSame(1, $again->slices());
        $this->assertSame('guid-4', $again->slice()['operations'][0]['value']);
    }

    /**
     * Session state is not a file format. Anything unreadable is a reason to
     * start over, not to break the page it is shown on.
     */
    public function testSomethingThatIsNotARunReadsAsNoRun(): void
    {
        $this->assertNull(ImportRun::fromArray(null));
        $this->assertNull(ImportRun::fromArray('run'));
        $this->assertNull(ImportRun::fromArray([]));
        $this->assertNull(ImportRun::fromArray(['position' => 4]));
    }

    public function testAHalfWrittenStateKeepsWhatItCan(): void
    {
        $run = ImportRun::fromArray(['plan' => $this->plan(5), 'position' => 2]);

        $this->assertNotNull($run);
        $this->assertSame(2, $run->position());
        $this->assertSame(['applied' => 0, 'failed' => 0, 'skipped' => 0], $run->counts());
    }
}
