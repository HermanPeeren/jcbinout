<?php

/**
 * @package JcbInOut
 */

namespace Yepr\Component\Jcbinout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Component\Jcbinout\Administrator\Blueprint\Payload;
use Yepr\Component\Jcbinout\Administrator\Blueprint\RepositorySource;
use Yepr\Component\Jcbinout\Administrator\Blueprint\RepositoryWriter;
use Yepr\Component\Jcbinout\Administrator\Jcb\Schema;

/**
 * Writing payloads back out as a repository.
 *
 * The inverse of `RepositorySource`, so the test that counts is that the two
 * agree: payloads written to a directory and read back out of it are the
 * payloads that went in. Everything else here is about *where* a record goes,
 * which the transport config declares and which is not all derivable - most
 * entities sit at `src/<entity>` and some do not, and guessing is right for the
 * common case and silently wrong for the rest.
 */
final class RepositoryWriterTest extends TestCase
{
    private string $scratch = '';

    protected function tearDown(): void
    {
        if ($this->scratch !== '' && is_dir($this->scratch)) {
            $this->removeTree($this->scratch);
        }
    }

    private function removeTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;

            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }

        @rmdir($path);
    }

    private function schema(): Schema
    {
        return new Schema([
            'joomlaColumns' => ['id', 'published'],
            'entities' => [
                'admin_view' => [
                    'name'      => 'admin_view',
                    'portable'  => true,
                    'transport' => [
                        'guidField'    => 'guid',
                        'children'     => ['admin_fields'],
                        'srcPath'      => 'src/admin_view',
                        'settingsName' => 'item.json',
                        'indexPath'    => 'index/admin-view.json',
                        'titleName'    => 'system_name',
                    ],
                    'properties' => [
                        'guid'              => ['kind' => 'property', 'portable' => true],
                        'system_name'       => ['kind' => 'property', 'portable' => true],
                        'short_description' => ['kind' => 'property', 'portable' => true],
                    ],
                ],
                'admin_fields' => [
                    'name'      => 'admin_fields',
                    'portable'  => true,
                    'transport' => [
                        'guidField'    => 'admin_view',
                        'children'     => [],
                        'srcPath'      => 'src/admin_view/children',
                        'settingsName' => 'admin-fields.json',
                        'indexPath'    => 'index/admin-fields.json',
                    ],
                    'properties' => [
                        'admin_view' => ['kind' => 'reference', 'portable' => true],
                        'addfields'  => ['kind' => 'containment', 'portable' => true],
                    ],
                ],
                // The awkward one, and the reason paths are asked for rather
                // than built: it sits at the root of src and its payload is not
                // called item.json.
                'power' => [
                    'name'      => 'power',
                    'portable'  => true,
                    'transport' => [
                        'guidField'    => 'guid',
                        'children'     => [],
                        'srcPath'      => 'src',
                        'settingsName' => 'settings.json',
                        'indexPath'    => 'super-powers.json',
                        'titleName'    => 'system_name',
                    ],
                    'properties' => [
                        'guid'        => ['kind' => 'property', 'portable' => true],
                        'system_name' => ['kind' => 'property', 'portable' => true],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return list<Payload>
     */
    private function payloads(): array
    {
        $schema = $this->schema();

        $make = static fn (string $entity, string $owner, array $data, bool $child = false): Payload
            => new Payload($entity, $owner, $schema->payloadPath($entity, $owner), $data, $child);

        return [
            $make('admin_view', 'view-1', [
                'guid'              => 'view-1',
                'system_name'       => 'Greetings (system name)',
                'short_description' => 'Greetings',
            ]),
            $make('admin_fields', 'view-1', [
                'admin_view' => 'view-1',
                'addfields'  => ['addfields0' => ['field' => 'field-1', 'order_list' => '1']],
            ], true),
            $make('power', 'power-1', ['guid' => 'power-1', 'system_name' => 'A Power']),
        ];
    }

    // -- where a record goes -------------------------------------------------

    /**
     * The paths the transport config declares, not the obvious ones.
     */
    public function testRecordsGoWhereTheirTransportSaysTheyGo(): void
    {
        $files = (new RepositoryWriter($this->schema()))->files($this->payloads());

        $this->assertArrayHasKey('src/admin_view/view-1/item.json', $files);
        $this->assertArrayHasKey(
            'src/admin_view/children/view-1/admin-fields.json',
            $files,
            'an owned record is filed under its parent'
        );
        $this->assertArrayHasKey(
            'src/power-1/settings.json',
            $files,
            'power sits at the root of src and its payload is not called item.json'
        );
    }

    public function testAnIndexIsWrittenForEachEntity(): void
    {
        $files = (new RepositoryWriter($this->schema()))->files($this->payloads());

        $this->assertArrayHasKey('index/admin-view.json', $files);
        $this->assertArrayHasKey('index/admin-fields.json', $files);
        $this->assertArrayHasKey('super-powers.json', $files, 'even when it is not under index/');
    }

    /**
     * An index locates payloads and says what they are called.
     */
    public function testAnIndexEntryPointsAtThePayloadAndNamesIt(): void
    {
        $files = (new RepositoryWriter($this->schema()))->files($this->payloads());
        $index = json_decode($files['index/admin-view.json'], true);

        $this->assertSame([
            'name'     => 'Greetings (system name)',
            'path'     => 'src/admin_view/view-1',
            'settings' => 'src/admin_view/view-1/item.json',
            'guid'     => 'view-1',
            'desc'     => 'Greetings',
        ], $index['view-1']);
    }

    /**
     * An owned record has no title of its own, and JCB's own indexes carry the
     * owner's guid in that field rather than leaving it out.
     */
    public function testAnOwnedRecordIsIndexedByItsOwner(): void
    {
        $files = (new RepositoryWriter($this->schema()))->files($this->payloads());
        $index = json_decode($files['index/admin-fields.json'], true);

        $this->assertSame('view-1', $index['view-1']['name']);
        $this->assertArrayNotHasKey('desc', $index['view-1']);
    }

    /**
     * Written twice from the same models, the same repository - which is what
     * makes it something to commit rather than something that churns.
     */
    public function testTheSameModelsWriteTheSameRepository(): void
    {
        $writer = new RepositoryWriter($this->schema());

        $this->assertSame(
            $writer->files($this->payloads()),
            $writer->files(array_reverse($this->payloads()))
        );
    }

    public function testAnEntityThisJcbDoesNotHaveIsReportedNotGuessedAt(): void
    {
        $writer = new RepositoryWriter($this->schema());

        $files = $writer->files([
            new Payload('invented_entity', 'g1', 'src/x.json', ['name' => 'x'], false),
        ]);

        $this->assertSame([], $files);
        $this->assertContains('ENTITY_UNKNOWN', array_column($writer->diagnostics(), 'code'));
    }

    /**
     * Two payloads claiming one file is a blueprint that cannot be written.
     * The second silently replacing the first would lose a record with nothing
     * saying so.
     */
    public function testTwoPayloadsWantingOneFileIsReported(): void
    {
        $writer = new RepositoryWriter($this->schema());

        $writer->files([
            new Payload('power', 'p', 'x', ['guid' => 'p', 'system_name' => 'First'], false),
            new Payload('power', 'p', 'x', ['guid' => 'p', 'system_name' => 'Second'], false),
        ]);

        $this->assertContains('PATH_COLLISION', array_column($writer->diagnostics(), 'code'));
    }

    // -- the loop ------------------------------------------------------------

    /**
     * The test the writer exists to pass.
     *
     * Payloads written to a directory and read back out of it by the reader
     * that has always been there are the payloads that went in - entity,
     * identity, ownership and every value. A writer that produced a plausible
     * tree the reader could not read would pass everything above this.
     */
    public function testPayloadsWrittenToDiskComeBackUnchanged(): void
    {
        $this->scratch = sys_get_temp_dir() . '/jcbinout-writer-' . bin2hex(random_bytes(6));

        $written = (new RepositoryWriter($this->schema()))
            ->writeTo($this->scratch, $this->payloads());

        $this->assertSame(3, $written['payloads']);
        $this->assertGreaterThan(0, $written['bytes']);

        // With the schema, because that is how a reader learns the layouts that
        // are not the obvious one: `power` keeps its payload in settings.json
        // at the root of src, and a walk that only knows the convention would
        // find two of these three and say nothing about the third.
        $read = (new RepositorySource($this->scratch, $this->schema()))->payloads();

        $this->assertCount(3, $read, 'every payload was found again');

        $shape = static fn (array $payloads): array => array_map(
            static fn (Payload $p): array => [
                'entity'  => $p->entity,
                'owner'   => $p->ownerGuid,
                'isChild' => $p->isChild,
                'data'    => $p->data,
            ],
            $payloads
        );

        $expected = $this->payloads();

        usort($expected, static fn (Payload $a, Payload $b) => [$a->entity, $a->ownerGuid]
            <=> [$b->entity, $b->ownerGuid]);

        $this->assertEquals($shape($expected), $shape($read));
    }
}
