<?php

/**
 * @package JcbInOut
 */

namespace Yepr\Component\Jcbinout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Component\Jcbinout\Administrator\Blueprint\ImportPlanner;
use Yepr\Component\Jcbinout\Administrator\Blueprint\Payload;
use Yepr\Component\Jcbinout\Administrator\Jcb\Schema;

/**
 * What importing a blueprint into JCB decides to do.
 *
 * The planner is the whole of the initialize-versus-reset difference and the
 * whole of the encoding, so it is where those are pinned. It needs no database
 * - what is already present is an argument - which is exactly why the decisions
 * were put here rather than in the writer.
 */
final class ImportPlannerTest extends TestCase
{
    private Schema $schema;
    private ImportPlanner $planner;

    protected function setUp(): void
    {
        $this->schema  = new Schema($this->metamodel());
        $this->planner = new ImportPlanner($this->schema);
    }

    /**
     * A metamodel small enough to reason about, shaped like the real one:
     * a definition, something that references it, and something it owns.
     */
    private function metamodel(): array
    {
        return [
            'joomlaColumns' => ['id', 'created', 'created_by', 'published', 'params'],
            'entities' => [
                'field' => [
                    'name'      => 'field',
                    'portable'  => true,
                    'transport' => ['guidField' => 'guid', 'children' => []],
                    'properties' => [
                        'guid'     => ['kind' => 'property', 'portable' => true, 'store' => null],
                        'name'     => ['kind' => 'property', 'portable' => true, 'store' => null],
                        'xml'      => ['kind' => 'property', 'portable' => true, 'store' => 'base64'],
                        'settings' => ['kind' => 'property', 'portable' => true, 'store' => 'json'],
                        'catid'    => ['kind' => 'property', 'portable' => false, 'store' => null],
                        'password' => ['kind' => 'secret', 'portable' => false, 'store' => 'basic_encryption'],
                    ],
                ],
                'admin_view' => [
                    'name'      => 'admin_view',
                    'portable'  => true,
                    'transport' => ['guidField' => 'guid', 'children' => ['admin_fields']],
                    'properties' => [
                        'guid' => ['kind' => 'property', 'portable' => true, 'store' => null],
                    ],
                ],
                // Owned by an admin_view, identified by it, and referencing fields.
                'admin_fields' => [
                    'name'      => 'admin_fields',
                    'portable'  => true,
                    'transport' => ['guidField' => 'admin_view', 'children' => []],
                    'properties' => [
                        'admin_view' => ['kind' => 'reference', 'portable' => true,
                            'target' => 'admin_view', 'store' => null],
                        'addfields'  => ['kind' => 'containment', 'portable' => true, 'store' => 'json',
                            'subform' => ['rowConcept' => 'Row', 'fields' => [
                                'field' => ['kind' => 'reference', 'target' => 'field'],
                            ]]],
                    ],
                ],
            ],
        ];
    }

    private function payload(string $entity, string $owner, array $data, bool $child = false): Payload
    {
        return new Payload($entity, $owner, "src/{$entity}/{$owner}/item.json", $data, $child);
    }

    // -- initialize vs reset -------------------------------------------------

    public function testInitializeInsertsWhatIsMissing(): void
    {
        $plan = $this->planner->plan(
            [$this->payload('field', 'g1', ['name' => 'Greeting'])],
            ['field' => []],
            ImportPlanner::INITIALIZE
        );

        $this->assertSame(1, $plan['counts'][ImportPlanner::INSERT]);
        $this->assertSame(ImportPlanner::INSERT, $plan['operations'][0]['action']);
    }

    /**
     * The point of initialize: a definition already here is left alone, so
     * importing a blueprint does not discard local edits.
     */
    public function testInitializeKeepsWhatIsAlreadyThere(): void
    {
        $plan = $this->planner->plan(
            [$this->payload('field', 'g1', ['name' => 'Greeting'])],
            ['field' => ['g1']],
            ImportPlanner::INITIALIZE
        );

        $this->assertSame(1, $plan['counts'][ImportPlanner::SKIP]);
        $this->assertSame(ImportPlanner::SKIP, $plan['operations'][0]['action']);
        $this->assertSame(
            [],
            $plan['operations'][0]['columns'],
            'a skipped row carries no values to write'
        );
    }

    public function testResetOverwritesWhatIsAlreadyThere(): void
    {
        $plan = $this->planner->plan(
            [$this->payload('field', 'g1', ['name' => 'Greeting'])],
            ['field' => ['g1']],
            ImportPlanner::RESET
        );

        $this->assertSame(1, $plan['counts'][ImportPlanner::UPDATE]);
        $this->assertSame(ImportPlanner::UPDATE, $plan['operations'][0]['action']);
        $this->assertArrayHasKey('name', $plan['operations'][0]['columns']);
    }

    public function testResetStillInsertsWhatIsMissing(): void
    {
        $plan = $this->planner->plan(
            [$this->payload('field', 'g1', ['name' => 'Greeting'])],
            ['field' => []],
            ImportPlanner::RESET
        );

        $this->assertSame(ImportPlanner::INSERT, $plan['operations'][0]['action']);
    }

    public function testAnUnknownModeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->planner->plan([], [], 'overwrite-everything');
    }

    // -- what gets written ---------------------------------------------------

    public function testInstallationLocalColumnsAreNotWritten(): void
    {
        $plan = $this->planner->plan(
            [$this->payload('field', 'g1', [
                'name' => 'Greeting', 'catid' => '7', 'password' => 'secret',
            ])],
            ['field' => []]
        );

        $columns = $plan['operations'][0]['columns'];

        $this->assertArrayHasKey('name', $columns);
        $this->assertArrayNotHasKey('catid', $columns, 'catid is on the ignore list');
        $this->assertArrayNotHasKey('password', $columns, 'an encrypted column is never portable');
    }

    /**
     * Joomla owns these; the writer sets them on an insert and leaves them on
     * an update, so a blueprint carrying one must not reach the row.
     */
    public function testJoomlaColumnsAreLeftToTheWriter(): void
    {
        $plan = $this->planner->plan(
            [$this->payload('field', 'g1', [
                'name' => 'Greeting', 'id' => '42', 'created_by' => '1', 'published' => '0',
            ])],
            ['field' => []]
        );

        $columns = $plan['operations'][0]['columns'];

        foreach (['id', 'created_by', 'published'] as $column) {
            $this->assertArrayNotHasKey($column, $columns);
        }
    }

    public function testValuesAreEncodedTheWayJcbStoresThem(): void
    {
        $plan = $this->planner->plan(
            [$this->payload('field', 'g1', [
                'name'     => 'Greeting',
                'xml'      => '<field type="text"/>',
                'settings' => ['a' => 1],
            ])],
            ['field' => []]
        );

        $columns = $plan['operations'][0]['columns'];

        $this->assertSame('Greeting', $columns['name'], 'a plain column is untouched');
        $this->assertSame(base64_encode('<field type="text"/>'), $columns['xml']);
        $this->assertSame('{"a":1}', $columns['settings']);
    }

    // -- identity ------------------------------------------------------------

    /**
     * An owned record has no guid of its own: JCB addresses admin_fields by the
     * admin_view that owns it.
     */
    public function testAnOwnedRecordIsIdentifiedByItsParent(): void
    {
        $plan = $this->planner->plan(
            [$this->payload('admin_fields', 'view-1', ['admin_view' => 'view-1'], true)],
            ['admin_fields' => []]
        );

        $this->assertSame('admin_view', $plan['operations'][0]['identifier']);
        $this->assertSame('view-1', $plan['operations'][0]['value']);
    }

    public function testTheTableFollowsTheEntity(): void
    {
        $plan = $this->planner->plan(
            [$this->payload('field', 'g1', ['name' => 'x'])],
            ['field' => []]
        );

        $this->assertSame('#__componentbuilder_field', $plan['operations'][0]['table']);
    }

    public function testAnEntityThisJcbDoesNotHaveIsReportedNotWritten(): void
    {
        $plan = $this->planner->plan(
            [$this->payload('invented_entity', 'g1', ['name' => 'x'])],
            []
        );

        $this->assertSame([], $plan['operations']);

        $codes = array_column($this->planner->diagnostics(), 'code');
        $this->assertContains('ENTITY_UNKNOWN', $codes);
    }

    // -- order ---------------------------------------------------------------

    /**
     * A row that points at a definition is written after it, so a half-applied
     * import does not leave references into rows that are not there yet.
     */
    public function testReferencedDefinitionsAreWrittenFirst(): void
    {
        $plan = $this->planner->plan(
            [
                $this->payload('admin_fields', 'view-1', ['admin_view' => 'view-1'], true),
                $this->payload('field', 'g1', ['name' => 'Greeting']),
                $this->payload('admin_view', 'view-1', ['guid' => 'view-1']),
            ],
            []
        );

        $order = array_column($plan['operations'], 'entity');

        $this->assertLessThan(
            array_search('admin_fields', $order, true),
            array_search('field', $order, true),
            'fields are written before the rows that reference them'
        );
        $this->assertLessThan(
            array_search('admin_fields', $order, true),
            array_search('admin_view', $order, true),
            'a view is written before the record it owns'
        );
    }

    public function testThePlanIsDeterministic(): void
    {
        $payloads = [
            $this->payload('field', 'g2', ['name' => 'B']),
            $this->payload('field', 'g1', ['name' => 'A']),
            $this->payload('admin_view', 'v1', ['guid' => 'v1']),
        ];

        $first  = $this->planner->plan($payloads, []);
        $second = (new ImportPlanner($this->schema))->plan(array_reverse($payloads), []);

        $this->assertSame(
            array_column($first['operations'], 'value'),
            array_column($second['operations'], 'value'),
            'the same blueprint plans the same way whatever order it is read in'
        );
    }

    public function testCountsAddUpToTheOperations(): void
    {
        $plan = $this->planner->plan(
            [
                $this->payload('field', 'g1', ['name' => 'A']),
                $this->payload('field', 'g2', ['name' => 'B']),
                $this->payload('admin_view', 'v1', ['guid' => 'v1']),
            ],
            ['field' => ['g1']],
            ImportPlanner::INITIALIZE
        );

        $this->assertSame(3, \count($plan['operations']));
        $this->assertSame(2, $plan['counts'][ImportPlanner::INSERT]);
        $this->assertSame(1, $plan['counts'][ImportPlanner::SKIP]);
        $this->assertSame(0, $plan['counts'][ImportPlanner::UPDATE]);
    }
}
