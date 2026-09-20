<?php
/**
 * @package JcbInOut
 */

namespace Yepr\Component\Jcbinout\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The derived metamodel has to explain a real JCB blueprint.
 *
 * This is the check that the metamodel is *correct* rather than merely
 * well-formed: every key in every payload of the public Hello World blueprint
 * must be a property the metamodel knows about, and every subform row must
 * match its declared shape. A missing entity type or a renamed column shows up
 * here and nowhere else.
 */
final class BlueprintCoverageTest extends TestCase
{
	/** Columns JCB adds to every table; present in payloads, absent from Table.php. */
	private const IMPLICIT = ['id', 'guid', 'published', 'created', 'modified', 'created_by',
		'modified_by', 'version', 'hits', 'ordering', 'access', 'params', 'asset_id',
		'checked_out', 'checked_out_time', 'metakey', 'metadesc', 'metadata'];

	private const RESERVED = ['@dependencies'];

	private static array $metamodel;
	private static string $blueprint;

	public static function setUpBeforeClass(): void
	{
		$root = \dirname(__DIR__, 2);

		$meta = $root . '/data/jcb-metamodel.json';

		if (!is_file($meta)) {
			self::markTestSkipped('No metamodel derived yet. Run: php tools/cli.php derive');
		}

		self::$metamodel = json_decode((string) file_get_contents($meta), true);
		self::$blueprint = $root . '/tests/fixtures/hello-world';

		if (!is_dir(self::$blueprint . '/src')) {
			self::markTestSkipped('Hello World fixture not unpacked.');
		}
	}

	/** src/<entity>/<guid>/item.json, or src/<entity>/children/<guid>/<child>.json */
	private function entityFor(string $relative): ?string
	{
		if (preg_match('#^src/[a-z_]+/children/[^/]+/([a-z-]+)\.json$#', $relative, $m)) {
			return str_replace('-', '_', $m[1]);
		}

		if (preg_match('#^src/([a-z_]+)/[^/]+/item\.json$#', $relative, $m)) {
			return $m[1];
		}

		return null;
	}

	public function testEveryPayloadKeyIsExplainedByTheMetamodel(): void
	{
		$entities = self::$metamodel['entities'];
		$problems = [];
		$payloads = 0;
		$keys     = 0;
		$rows     = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(self::$blueprint . '/src',
				\FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			/** @var \SplFileInfo $file */
			if ($file->getExtension() !== 'json') {
				continue;
			}

			$relative = str_replace(DIRECTORY_SEPARATOR, '/',
				substr($file->getPathname(), \strlen(self::$blueprint) + 1));

			$entity = $this->entityFor($relative);

			if ($entity === null) {
				$problems[] = "unrecognised payload path: {$relative}";
				continue;
			}

			if (!isset($entities[$entity])) {
				$problems[] = "payload for unknown entity '{$entity}' ({$relative})";
				continue;
			}

			$payload = json_decode((string) file_get_contents($file->getPathname()), true);

			if (!\is_array($payload)) {
				$problems[] = "unreadable payload: {$relative}";
				continue;
			}

			$payloads++;
			$props = $entities[$entity]['properties'] ?? [];

			foreach ($payload as $key => $value) {
				$keys++;

				if (\in_array($key, self::RESERVED, true) || \in_array($key, self::IMPLICIT, true)) {
					continue;
				}

				if (!isset($props[$key])) {
					$problems[] = "unknown property {$entity}.{$key} ({$relative})";
					continue;
				}

				$prop = $props[$key];

				if (($prop['kind'] ?? null) !== 'containment' || !\is_array($value)) {
					continue;
				}

				$shape = $prop['subform']['fields'] ?? [];

				if ($shape === []) {
					continue;   // already reported at extraction as SUBFORM_NO_SHAPE
				}

				foreach ($value as $rowKey => $row) {
					if (!\is_array($row)) {
						continue;
					}

					$rows++;

					foreach (array_keys($row) as $rowField) {
						if (!isset($shape[$rowField])) {
							$problems[] = "subform {$entity}.{$key}[{$rowKey}] has undeclared "
								. "field '{$rowField}'";
						}
					}
				}
			}
		}

		$this->assertSame([], $problems,
			\count($problems) . ' payload key(s) the metamodel cannot explain');

		// Guard against the walk silently finding nothing.
		$this->assertGreaterThan(30, $payloads, 'expected the full fixture to be walked');
		$this->assertGreaterThan(600, $keys);
		$this->assertGreaterThan(10, $rows);
	}

	public function testTheCatalogueMatchesWhatJcbPublishes(): void
	{
		$stats = self::$metamodel['stats'];

		// JCB's own architecture documentation states 45 canonical transport
		// entity types. Deriving a different number means the catalogue moved.
		$this->assertSame(45, $stats['entitiesPortable']);
		$this->assertGreaterThanOrEqual(45, $stats['entitiesTotal']);
	}
}
