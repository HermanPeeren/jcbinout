<?php
/**
 * @package JcbInOut
 */

namespace Yepr\Component\Jcbinout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Component\Jcbinout\Administrator\Blueprint\RepositorySource;
use Yepr\Component\Jcbinout\Administrator\Lionweb\InstanceExporter;
use Yepr\Component\Jcbinout\Administrator\Lionweb\LanguageIndex;
use Yepr\Component\Jcbinout\Administrator\Lionweb\Validator;

/**
 * Exporting the public Hello World blueprint to a LionWeb instance chunk.
 *
 * The fixture is small enough to assert exactly and real enough to be worth
 * asserting: it is JCB's own published example, with a component, a module, a
 * plugin, views, fields and the field occurrences that carry their use-site
 * roles.
 */
final class InstanceExportTest extends TestCase
{
	private const GREETING_FIELD = '75e830a6-a3a5-4327-9161-3f774a6f1591';

	private static LanguageIndex $index;
	private static array $payloads;
	private static array $chunk;

	public static function setUpBeforeClass(): void
	{
		$root = \dirname(__DIR__, 2);

		$language = $root . '/data/jcb-language.lionweb.json';

		if (!is_file($language)) {
			self::markTestSkipped('No language built yet. Run: php tools/cli.php all');
		}

		$blueprint = new RepositorySource($root . '/tests/fixtures/hello-world');

		if (!$blueprint->exists()) {
			self::markTestSkipped('Hello World fixture not unpacked.');
		}

		self::$index    = LanguageIndex::fromFiles($language, $root . '/data/jcb-enum-values.json');
		self::$payloads = $blueprint->payloads();
		self::$chunk    = (new InstanceExporter(self::$index))
			->export(self::$payloads, 'hello-world');
	}

	public function testTheFixtureIsReadWhole(): void
	{
		$this->assertCount(33, self::$payloads, 'the published fixture has 33 payloads');

		$children = array_filter(self::$payloads, static fn($p) => $p->isChild);
		$this->assertCount(9, $children, 'nine of them are records owned by a definition');
	}

	public function testTheExportIsAValidLionWebSerialisation(): void
	{
		$validator = new Validator();

		$this->assertTrue($validator->validate(self::$chunk),
			implode('; ', $validator->errors()));
	}

	public function testEverythingHangsOffOnePartition(): void
	{
		$roots = array_filter(self::$chunk['nodes'],
			static fn(array $n) => $n['parent'] === null);

		$this->assertCount(1, $roots, 'a partition is the single root');

		$root = array_values($roots)[0];
		$this->assertSame('Blueprint', $root['classifier']['key']);

		// Every node must be reachable, or something was emitted and orphaned.
		$reachable = $this->reachable(self::$chunk, $root['id']);
		$this->assertCount(\count(self::$chunk['nodes']), $reachable,
			'every exported node is reachable from the partition');
	}

	/**
	 * The point of the whole exercise: one definition, referenced from its
	 * use-sites, not copied into them.
	 */
	public function testAFieldDefinitionIsReferencedNotCloned(): void
	{
		$byId = [];

		foreach (self::$chunk['nodes'] as $node) {
			$byId[$node['id']] = $node;
		}

		$this->assertArrayHasKey(self::GREETING_FIELD, $byId,
			'the Greeting field keeps its JCB guid as its node id');

		$field = $byId[self::GREETING_FIELD];
		$this->assertSame('field', $field['classifier']['key']);

		// Exactly one node in the fixture uses it, and it is an occurrence
		// carrying use-site roles rather than a second copy of the field.
		$occurrences = [];

		foreach (self::$chunk['nodes'] as $node) {
			foreach ($node['references'] as $reference) {
				foreach ($reference['targets'] as $target) {
					if (($target['reference'] ?? null) === self::GREETING_FIELD) {
						$occurrences[] = $node;
					}
				}
			}
		}

		$this->assertCount(1, $occurrences);
		$this->assertSame('AdminFieldsAddfieldsRow', $occurrences[0]['classifier']['key']);

		// The roles the architecture paper follows: title, searchable, sortable.
		$values = $this->propertyValues($occurrences[0]);

		$this->assertSame('1', $values['title'] ?? null);
		$this->assertSame('1', $values['search'] ?? null);
		$this->assertSame('1', $values['sort'] ?? null);
	}

	/**
	 * An enumeration carries its literal's key, not JCB's raw column value.
	 */
	public function testEnumerationValuesAreLiteralKeys(): void
	{
		$field = null;

		foreach (self::$chunk['nodes'] as $node) {
			if ($node['id'] === self::GREETING_FIELD) {
				$field = $node;
			}
		}

		$values = $this->propertyValues($field);

		$this->assertSame('FieldDatatype-VARCHAR', $values['datatype'] ?? null);
		$this->assertSame('FieldIndexes-NONE', $values['indexes'] ?? null);
	}

	/**
	 * An unselected list is an absent property, not a property holding ''.
	 */
	public function testUnsetEnumerationsAreOmitted(): void
	{
		foreach (self::$chunk['nodes'] as $node) {
			foreach ($node['properties'] as $property) {
				$this->assertNotNull($property['value'],
					"node {$node['id']} has a property with a null value");
			}
		}
	}

	/**
	 * Ids have to be stable or nothing downstream can be diffed, and a subform's
	 * first row is keyed '0' - which is a falsy string in PHP and was silently
	 * replaced until it was asserted here.
	 */
	public function testNodeIdsAreDeterministicAndUnique(): void
	{
		$again = (new InstanceExporter(self::$index))
			->export(self::$payloads, 'hello-world');

		$first  = array_column(self::$chunk['nodes'], 'id');
		$second = array_column($again['nodes'], 'id');

		$this->assertSame($first, $second, 'a second export produces the same ids');
		$this->assertSame(\count($first), \count(array_unique($first)), 'ids are unique');

		$rows = array_filter($first, static fn(string $id) => str_contains($id, '--addfields--'));
		$this->assertNotEmpty($rows);

		foreach ($rows as $id) {
			$this->assertStringEndsNotWith('--x', $id,
				"row id {$id} lost its key to a falsy-string check");
		}
	}

	/**
	 * A reference to something outside this blueprint is carried with
	 * resolveInfo and no id, rather than dropped or faked.
	 */
	public function testReferencesOutsideTheBlueprintKeepTheirTarget(): void
	{
		$ids      = array_flip(array_column(self::$chunk['nodes'], 'id'));
		$external = 0;

		foreach (self::$chunk['nodes'] as $node) {
			foreach ($node['references'] as $reference) {
				foreach ($reference['targets'] as $target) {
					if ($target['reference'] === null) {
						$external++;
						// assertNotEmpty would pass '0' off as missing, which is
						// the same falsy-string trap that mangled the row ids.
						$this->assertNotSame('', (string) $target['resolveInfo'],
							'an unresolved reference still says what it pointed at');
						$this->assertNotSame('0', (string) $target['resolveInfo'],
							"JCB's no-selection sentinel is not a reference");
						continue;
					}

					$this->assertArrayHasKey($target['reference'], $ids,
						'a resolved reference points at a node in this chunk');
				}
			}
		}

		// The fixture does reference definitions it does not carry, so a zero
		// here would mean the walk stopped finding them.
		$this->assertGreaterThan(0, $external);
	}

	// -- helpers -------------------------------------------------------------

	/** @return array<string,string|null> feature name => value */
	private function propertyValues(array $node): array
	{
		$names = [];

		foreach (self::$index->featuresOf($node['classifier']['key']) as $name => $descriptor) {
			$names[$descriptor['key']] = $name;
		}

		$out = [];

		foreach ($node['properties'] as $property) {
			$key       = $property['property']['key'];
			$out[$names[$key] ?? $key] = $property['value'];
		}

		return $out;
	}

	/** @return array<string,true> */
	private function reachable(array $chunk, string $rootId): array
	{
		$byId = [];

		foreach ($chunk['nodes'] as $node) {
			$byId[$node['id']] = $node;
		}

		$seen  = [];
		$stack = [$rootId];

		while ($stack !== []) {
			$id = array_pop($stack);

			if (isset($seen[$id]) || !isset($byId[$id])) {
				continue;
			}

			$seen[$id] = true;

			foreach ($byId[$id]['containments'] as $containment) {
				foreach ($containment['children'] as $child) {
					$stack[] = $child;
				}
			}
		}

		return $seen;
	}
}
