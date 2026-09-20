<?php

/**
 * @package JcbInOut
 */

namespace Yepr\Component\Jcbinout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Component\Jcbinout\Administrator\Blueprint\DesignProjection;
use Yepr\Component\Jcbinout\Administrator\Blueprint\Payload;
use Yepr\Component\Jcbinout\Administrator\Blueprint\RepositorySource;
use Yepr\Component\Jcbinout\Administrator\Lionweb\InstanceExporter;
use Yepr\Component\Jcbinout\Administrator\Lionweb\InstanceImporter;
use Yepr\Component\Jcbinout\Administrator\Lionweb\LanguageIndex;

/**
 * Blueprint to LionWeb and back, compared under the design projection.
 *
 * The comparison is only worth anything if it can fail, so the negative
 * controls here matter as much as the round trip itself: a projection loose
 * enough to call any two blueprints equal would pass the main test and tell
 * nobody anything.
 */
final class RoundTripTest extends TestCase
{
    private static LanguageIndex $index;
    private static array $original;
    private static array $returned;

    public static function setUpBeforeClass(): void
    {
        $root     = \dirname(__DIR__, 2);
        $language = $root . '/data/jcb-language.lionweb.json';

        if (!is_file($language)) {
            self::markTestSkipped('No language built yet. Run: php tools/cli.php all');
        }

        $source = new RepositorySource($root . '/tests/fixtures/hello-world');

        if (!$source->exists()) {
            self::markTestSkipped('Hello World fixture not unpacked.');
        }

        self::$index    = LanguageIndex::fromFiles($language, $root . '/data/jcb-enum-values.json');
        self::$original = $source->payloads();

        $chunk = (new InstanceExporter(self::$index))
            ->export(self::$original, 'hello-world');

        self::$returned = (new InstanceImporter(self::$index))->import($chunk);
    }

    public function testDesignSurvivesTheRoundTrip(): void
    {
        $before = DesignProjection::of(self::$original, self::$index);
        $after  = DesignProjection::of(self::$returned, self::$index);
        $diff   = DesignProjection::diff($before, $after);

        $report = implode("\n", array_map(
            static fn(array $d) => "  [{$d['kind']}] {$d['where']}: {$d['detail']}",
            \array_slice($diff, 0, 15)
        ));

        $this->assertSame(
            [],
            $diff,
            \count($diff) . " design difference(s) after a round trip:\n" . $report
        );
    }

    public function testEveryPayloadComesBack(): void
    {
        $this->assertCount(\count(self::$original), self::$returned);

        $before = array_map(
            static fn(Payload $p) => DesignProjection::keyOf($p),
            self::$original
        );
        $after  = array_map(
            static fn(Payload $p) => DesignProjection::keyOf($p),
            self::$returned
        );

        sort($before);
        sort($after);

        $this->assertSame($before, $after);
    }

    /**
     * The projection is not a comparison that always succeeds.
     */
    public function testAChangedValueIsCaught(): void
    {
        $mutated = $this->mutate(self::$returned, 'field', 'name', 'Something else');

        $diff = DesignProjection::diff(
            DesignProjection::of(self::$original, self::$index),
            DesignProjection::of($mutated, self::$index)
        );

        $this->assertNotEmpty($diff, 'a changed column must be reported');
        $this->assertSame('column-changed', $diff[0]['kind']);
    }

    public function testADroppedColumnIsCaught(): void
    {
        $mutated = [];

        foreach (self::$returned as $payload) {
            $data = $payload->data;

            if ($payload->entity === 'field') {
                unset($data['name']);
            }

            $mutated[] = new Payload(
                $payload->entity,
                $payload->ownerGuid,
                $payload->relativePath,
                $data,
                $payload->isChild
            );
        }

        $diff = DesignProjection::diff(
            DesignProjection::of(self::$original, self::$index),
            DesignProjection::of($mutated, self::$index)
        );

        $this->assertNotEmpty($diff);
        $this->assertSame('column-lost', $diff[0]['kind']);
    }

    public function testAMissingPayloadIsCaught(): void
    {
        $mutated = array_values(array_filter(
            self::$returned,
            static fn(Payload $p) => $p->entity !== 'field'
        ));

        $diff = DesignProjection::diff(
            DesignProjection::of(self::$original, self::$index),
            DesignProjection::of($mutated, self::$index)
        );

        $kinds = array_column($diff, 'kind');
        $this->assertContains('payload-missing', $kinds);
    }

    /**
     * Subform order is design; the row keys are not.
     */
    public function testReorderedSubformRowsAreCaught(): void
    {
        $mutated = [];

        foreach (self::$returned as $payload) {
            $data = $payload->data;

            if (
                $payload->entity === 'admin_fields' && isset($data['addfields'])
                && \count($data['addfields']) > 1
            ) {
                $data['addfields'] = array_reverse($data['addfields'], true);
            }

            $mutated[] = new Payload(
                $payload->entity,
                $payload->ownerGuid,
                $payload->relativePath,
                $data,
                $payload->isChild
            );
        }

        $diff = DesignProjection::diff(
            DesignProjection::of(self::$original, self::$index),
            DesignProjection::of($mutated, self::$index)
        );

        $this->assertNotEmpty($diff, 'reordering an ordered association changes the design');
    }

    /**
     * Renaming the row keys does not, because JCB regenerates them.
     */
    public function testRenumberedSubformRowKeysAreNotADifference(): void
    {
        $mutated = [];

        foreach (self::$returned as $payload) {
            $data = $payload->data;

            if ($payload->entity === 'admin_fields' && isset($data['addfields'])) {
                $renamed = [];
                $i       = 100;

                foreach ($data['addfields'] as $row) {
                    $renamed['row' . $i++] = $row;
                }

                $data['addfields'] = $renamed;
            }

            $mutated[] = new Payload(
                $payload->entity,
                $payload->ownerGuid,
                $payload->relativePath,
                $data,
                $payload->isChild
            );
        }

        $diff = DesignProjection::diff(
            DesignProjection::of(self::$original, self::$index),
            DesignProjection::of($mutated, self::$index)
        );

        $this->assertSame([], $diff, 'row keys are storage labels, not design');
    }

    /** @return list<Payload> */
    private function mutate(array $payloads, string $entity, string $column, string $value): array
    {
        $out = [];

        foreach ($payloads as $payload) {
            $data = $payload->data;

            if ($payload->entity === $entity && array_key_exists($column, $data)) {
                $data[$column] = $value;
            }

            $out[] = new Payload(
                $payload->entity,
                $payload->ownerGuid,
                $payload->relativePath,
                $data,
                $payload->isChild
            );
        }

        return $out;
    }
}
