<?php
/**
 * JcbInOut - Phase 2: LionWeb language definition (M2)
 *
 * Turns the derived metamodel (Phase 1) into a LionWeb language serialisation
 * chunk, so JCB's entity catalogue becomes a first-class, exchangeable
 * language rather than an implicit database schema.
 *
 * Mapping rules (see METAMODEL.md for counts):
 *
 *   portable entity      -> Concept, key = entity name
 *   property             -> Property,    key = the property's JCB GUID
 *   reference (guid)     -> Reference,   key = the property's JCB GUID
 *   subform              -> Containment  + a row Concept (the occurrence)
 *   list                 -> Enumeration harvested from admin/forms/<entity>.xml
 *   reference (local id) -> dropped, not portable between installations
 *   encrypted            -> dropped, installation-local
 *   ignore-listed        -> dropped, outside JCB's own transport projection
 *
 * Usage: php generate-lionweb-language.php <metamodel.json> [out.json]
 */

declare(strict_types=1);

const LW_FORMAT   = '2024.1';
const M3          = 'LionCore-M3';
const BUILTINS    = 'LionCore-builtins';
const LANG_KEY    = 'jcb';
const LANG_NAME   = 'JCB';

$metaFile = $argv[1] ?? (__DIR__ . '/../jcb-metamodel.json');
$outFile  = $argv[2] ?? (__DIR__ . '/../jcb-language.lionweb.json');

$meta = json_decode((string) file_get_contents($metaFile), true);

if (!is_array($meta))
{
	fwrite(STDERR, "Cannot read metamodel: {$metaFile}\n");
	exit(1);
}

// ---------------------------------------------------------------------------

final class LanguageBuilder
{
	private array $nodes = [];
	private array $diagnostics = [];
	private array $conceptIds = [];     // entity/row name => node id
	private array $enumIds = [];        // enum name => node id
	private array $usedKeys = [];       // key => owner, for collision detection
	private array $featureKeys = [];    // feature key => {entity, property, guid, kind}
	private array $guidOwners = [];     // jcb guid => list of entity.property using it

	public function __construct(private array $meta) {}

	// -- metapointer helpers -------------------------------------------------

	private function mpM3(string $key): array
	{
		return ['language' => M3, 'version' => LW_FORMAT, 'key' => $key];
	}

	private function mpBuiltin(string $key): array
	{
		return ['language' => BUILTINS, 'version' => LW_FORMAT, 'key' => $key];
	}

	private function prop(array $mp, ?string $value): array
	{
		return ['property' => $mp, 'value' => $value];
	}

	private function named(string $name, string $key): array
	{
		return [
			$this->prop($this->mpBuiltin('LionCore-builtins-INamed-name'), $name),
			$this->prop($this->mpM3('IKeyed-key'), $key),
		];
	}

	/** Every node must carry all seven members, even when empty. */
	private function node(string $id, array $classifier, array $properties,
		array $containments, array $references, ?string $parent): void
	{
		$this->nodes[] = [
			'id'           => $id,
			'classifier'   => $classifier,
			'properties'   => $properties,
			'containments' => $containments,
			'references'   => $references,
			'annotations'  => [],
			'parent'       => $parent,
		];
	}

	private function diag(string $severity, string $code, string $message): void
	{
		$this->diagnostics[] = compact('severity', 'code', 'message');
	}

	/** LionWeb ids and keys are restricted to [a-zA-Z0-9_-]. */
	private function safe(string $s): string
	{
		$s = (string) preg_replace('#[^a-zA-Z0-9_-]+#', '-', $s);

		return trim($s, '-') ?: 'x';
	}

	private function pascal(string $s): string
	{
		return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $s)));
	}

	private function claimKey(string $key, string $owner): string
	{
		if (isset($this->usedKeys[$key]))
		{
			$this->diag('error', 'KEY_COLLISION',
				"Key '{$key}' claimed by both '{$this->usedKeys[$key]}' and '{$owner}'.");
		}

		$this->usedKeys[$key] = $owner;

		return $key;
	}

	// -- build ---------------------------------------------------------------

	public function build(string $version): array
	{
		$entities = $this->meta['entities'];
		$portable = array_filter($entities, static fn($e) => $e['portable']);

		// Pass 1: allocate concept ids so features can point at them.
		foreach ($portable as $name => $_)
		{
			$this->conceptIds[$name] = 'jcb-' . $this->safe($name);
		}

		foreach ($portable as $name => $e)
		{
			foreach ($e['properties'] ?? [] as $pname => $p)
			{
				if (($p['kind'] ?? '') === 'containment' && !empty($p['subform']['rowConcept']))
				{
					$row = $p['subform']['rowConcept'];
					$this->conceptIds[$row] = 'jcb-' . $this->safe($row);
				}
			}
		}

		foreach ($this->meta['enumerations'] ?? [] as $ename => $_)
		{
			$this->enumIds[$ename] = 'jcb-enum-' . $this->safe($ename);
		}

		// Pass 2: emit.
		$entityNodeIds = [];

		foreach ($this->meta['enumerations'] ?? [] as $ename => $enum)
		{
			$entityNodeIds[] = $this->emitEnumeration($ename, $enum);
		}

		foreach ($portable as $name => $e)
		{
			$entityNodeIds[] = $this->emitConcept($name, $e);

			// Row concepts are siblings of their owner in the language.
			foreach ($e['properties'] ?? [] as $pname => $p)
			{
				if (($p['kind'] ?? '') === 'containment' && !empty($p['subform']['rowConcept']))
				{
					$entityNodeIds[] = $this->emitRowConcept($p['subform'], $name, $pname);
				}
			}
		}

		$entityNodeIds[] = $this->emitBlueprintPartition($portable);

		// The Language node itself.
		$this->node(
			'jcb-language',
			$this->mpM3('Language'),
			array_merge(
				$this->named(LANG_NAME, LANG_KEY),
				[$this->prop($this->mpM3('Language-version'), $version)]
			),
			[['containment' => $this->mpM3('Language-entities'), 'children' => $entityNodeIds]],
			[[
				'reference' => $this->mpM3('Language-dependsOn'),
				'targets'   => [['resolveInfo' => BUILTINS,
					'reference' => BUILTINS . '-' . str_replace('.', '-', LW_FORMAT)]],
			]],
			null
		);

		return [
			'serializationFormatVersion' => LW_FORMAT,
			'languages'                  => [
				['key' => M3, 'version' => LW_FORMAT],
				['key' => BUILTINS, 'version' => LW_FORMAT],
			],
			'nodes' => $this->nodes,
		];
	}

	// -- enumerations --------------------------------------------------------

	private function emitEnumeration(string $name, array $enum): string
	{
		$id       = $this->enumIds[$name];
		$literals = [];

		foreach ($enum['literals'] as $lit)
		{
			$litId  = $id . '-' . $this->safe($lit['name']);
			$litKey = $this->claimKey(
				$this->safe($name . '-' . $lit['name']),
				"enum literal {$name}.{$lit['name']}"
			);

			$this->node(
				$litId,
				$this->mpM3('EnumerationLiteral'),
				$this->named($lit['name'], $litKey),
				[], [], $id
			);

			$literals[] = $litId;
		}

		$this->node(
			$id,
			$this->mpM3('Enumeration'),
			$this->named($name, $this->claimKey($this->safe($name), "enumeration {$name}")),
			[['containment' => $this->mpM3('Enumeration-literals'), 'children' => $literals]],
			[],
			'jcb-language'
		);

		return $id;
	}

	// -- concepts ------------------------------------------------------------

	private function emitConcept(string $entity, array $e): string
	{
		$id       = $this->conceptIds[$entity];
		$features = [];

		foreach ($e['properties'] ?? [] as $pname => $p)
		{
			$featureId = $this->emitFeature($id, $entity, $pname, $p);

			if ($featureId !== null)
			{
				$features[] = $featureId;
			}
		}

		// Declared children are containments that transport config owns,
		// not properties of the row itself.
		foreach ($e['transport']['children'] ?? [] as $child)
		{
			if (!isset($this->conceptIds[$child]))
			{
				$this->diag('warning', 'CHILD_NOT_PORTABLE',
					"Entity '{$entity}' declares child '{$child}' which is not a portable concept; "
					. 'containment omitted.');
				continue;
			}

			$featureId = 'jcb-' . $this->safe($entity) . '-child-' . $this->safe($child);

			$this->node(
				$featureId,
				$this->mpM3('Containment'),
				array_merge(
					$this->named($child, $this->claimKey(
						$this->safe($entity . '-child-' . $child), "{$entity} child {$child}")),
					[
						$this->prop($this->mpM3('Feature-optional'), 'true'),
						$this->prop($this->mpM3('Link-multiple'), 'true'),
					]
				),
				[],
				[['reference' => $this->mpM3('Link-type'),
					'targets' => [['resolveInfo' => $child, 'reference' => $this->conceptIds[$child]]]]],
				$id
			);

			$features[] = $featureId;
		}

		$this->node(
			$id,
			$this->mpM3('Concept'),
			array_merge(
				$this->named($this->pascal($entity), $this->claimKey($this->safe($entity), "concept {$entity}")),
				[
					$this->prop($this->mpM3('Concept-abstract'), 'false'),
					$this->prop($this->mpM3('Concept-partition'), 'false'),
				]
			),
			[['containment' => $this->mpM3('Classifier-features'), 'children' => $features]],
			[],
			'jcb-language'
		);

		return $id;
	}

	private function emitRowConcept(array $subform, string $owner, string $ownerProp): string
	{
		$name     = $subform['rowConcept'];
		$id       = $this->conceptIds[$name];
		$features = [];

		foreach ($subform['fields'] as $fname => $f)
		{
			$featureId = $id . '-' . $this->safe($fname);
			// Row fields carry no GUID in Table.php; synthesise a stable key.
			$key = $this->claimKey($this->safe($name . '-' . $fname), "{$name}.{$fname}");

			if (($f['kind'] ?? '') === 'reference')
			{
				$target = $f['target'] ?? null;

				if ($target === null || !isset($this->conceptIds[$target]))
				{
					$this->diag('warning', 'ROW_REF_UNRESOLVED',
						"Row field '{$name}.{$fname}' references '"
						. ($target ?? '?') . "' which is not a portable concept; emitted as String.");
				}
				else
				{
					$this->node(
						$featureId,
						$this->mpM3('Reference'),
						array_merge($this->named($fname, $key), [
							$this->prop($this->mpM3('Feature-optional'), 'true'),
							$this->prop($this->mpM3('Link-multiple'), 'false'),
						]),
						[],
						[['reference' => $this->mpM3('Link-type'),
							'targets' => [['resolveInfo' => $target,
								'reference' => $this->conceptIds[$target]]]]],
						$id
					);

					$features[] = $featureId;
					continue;
				}
			}

			// Row settings have no declared datatype; String is the safe carrier.
			$this->node(
				$featureId,
				$this->mpM3('Property'),
				array_merge($this->named($fname, $key), [
					$this->prop($this->mpM3('Feature-optional'), 'true'),
				]),
				[],
				[['reference' => $this->mpM3('Property-type'),
					'targets' => [['resolveInfo' => 'String',
						'reference' => 'LionCore-builtins-String-'
							. str_replace('.', '-', LW_FORMAT)]]]],
				$id
			);

			$features[] = $featureId;
		}

		$this->node(
			$id,
			$this->mpM3('Concept'),
			array_merge(
				$this->named($name, $this->claimKey($this->safe($name), "row concept {$name}")),
				[
					$this->prop($this->mpM3('Concept-abstract'), 'false'),
					$this->prop($this->mpM3('Concept-partition'), 'false'),
				]
			),
			[['containment' => $this->mpM3('Classifier-features'), 'children' => $features]],
			[],
			'jcb-language'
		);

		return $id;
	}

	// -- features ------------------------------------------------------------

	private function emitFeature(string $conceptId, string $entity, string $pname, array $p): ?string
	{
		if (empty($p['portable']))
		{
			return null;   // reference_local, secret, or ignore-listed
		}

		$kind = $p['kind'];
		$id   = $conceptId . '-' . $this->safe($pname);

		// JCB's own property GUID carries the stable identity, but GUIDs are
		// reused across entities for structurally identical fields, and LionWeb
		// requires feature keys to be unique language-wide (LionCore itself
		// qualifies: Concept-extends vs Annotation-extends). So qualify by
		// entity: the GUID still survives a column rename, which is the point.
		$guid = $p['guid'] ?? null;

		if ($guid === null)
		{
			$key = $this->safe($entity . '-' . $pname);
			$this->diag('info', 'KEY_SYNTHESISED',
				"Property '{$entity}.{$pname}' has no GUID; key synthesised as '{$key}'.");
		}
		else
		{
			$key = $this->safe($entity . '-' . $guid);
			$this->guidOwners[$guid][] = "{$entity}.{$pname}";
		}

		$key = $this->claimKey($key, "{$entity}.{$pname}");

		$this->featureKeys[$key] = [
			'entity'   => $entity,
			'property' => $pname,
			'guid'     => $guid,
			'kind'     => $kind,
		];
		$optional = 'true';

		if ($kind === 'reference')
		{
			$target = $p['target'] ?? null;

			if ($target === null || !isset($this->conceptIds[$target]))
			{
				$this->diag('warning', 'REF_TARGET_NOT_PORTABLE',
					"Property '{$entity}.{$pname}' references '" . ($target ?? '?')
					. "' which is not a portable concept; feature omitted.");

				return null;
			}

			$this->node($id, $this->mpM3('Reference'),
				array_merge($this->named($pname, $key), [
					$this->prop($this->mpM3('Feature-optional'), $optional),
					$this->prop($this->mpM3('Link-multiple'), 'false'),
				]),
				[],
				[['reference' => $this->mpM3('Link-type'),
					'targets' => [['resolveInfo' => $target,
						'reference' => $this->conceptIds[$target]]]]],
				$conceptId);

			return $id;
		}

		if ($kind === 'containment')
		{
			$row = $p['subform']['rowConcept'] ?? null;

			if ($row === null || !isset($this->conceptIds[$row]))
			{
				// No declared row shape: carry the JSON verbatim rather than lose it.
				$this->diag('warning', 'SUBFORM_AS_BLOB',
					"Subform '{$entity}.{$pname}' has no row shape; emitted as a String property "
					. '(lossy: structure is not modelled).');

				return $this->emitPlainProperty($id, $conceptId, $pname, $key, 'String', $optional);
			}

			$this->node($id, $this->mpM3('Containment'),
				array_merge($this->named($pname, $key), [
					$this->prop($this->mpM3('Feature-optional'), $optional),
					$this->prop($this->mpM3('Link-multiple'), 'true'),
				]),
				[],
				[['reference' => $this->mpM3('Link-type'),
					'targets' => [['resolveInfo' => $row, 'reference' => $this->conceptIds[$row]]]]],
				$conceptId);

			return $id;
		}

		if ($kind === 'structured')
		{
			$this->diag('info', 'JSON_AS_STRING',
				"Property '{$entity}.{$pname}' is an unshaped JSON blob; carried as String.");

			return $this->emitPlainProperty($id, $conceptId, $pname, $key, 'String', $optional);
		}

		// Plain property: builtin primitive or a harvested enumeration.
		if (($p['datatype'] ?? null) === 'Enumeration' && !empty($p['enumeration']))
		{
			$ename = $p['enumeration'];

			if (!isset($this->enumIds[$ename]))
			{
				$this->diag('error', 'ENUM_MISSING',
					"Property '{$entity}.{$pname}' names enumeration '{$ename}' which was not emitted.");

				return $this->emitPlainProperty($id, $conceptId, $pname, $key, 'String', $optional);
			}

			$this->node($id, $this->mpM3('Property'),
				array_merge($this->named($pname, $key), [
					$this->prop($this->mpM3('Feature-optional'), $optional),
				]),
				[],
				[['reference' => $this->mpM3('Property-type'),
					'targets' => [['resolveInfo' => $ename, 'reference' => $this->enumIds[$ename]]]]],
				$conceptId);

			return $id;
		}

		$datatype = $p['datatype'] ?? 'String';

		if (!in_array($datatype, ['String', 'Integer', 'Boolean'], true))
		{
			$datatype = 'String';
		}

		return $this->emitPlainProperty($id, $conceptId, $pname, $key, $datatype, $optional);
	}

	private function emitPlainProperty(string $id, string $conceptId, string $pname,
		string $key, string $datatype, string $optional): string
	{
		$this->node($id, $this->mpM3('Property'),
			array_merge($this->named($pname, $key), [
				$this->prop($this->mpM3('Feature-optional'), $optional),
			]),
			[],
			[['reference' => $this->mpM3('Property-type'),
				'targets' => [[
					'resolveInfo' => $datatype,
					// builtin node ids carry the version: LionCore-builtins-String-2024-1
					'reference'   => 'LionCore-builtins-' . $datatype . '-'
						. str_replace('.', '-', LW_FORMAT),
				]]]],
			$conceptId);

		return $id;
	}

	// -- partition -----------------------------------------------------------

	/**
	 * LionWeb needs a partition root. A Blueprint contains every entity type
	 * that is not owned as a child of another entity.
	 */
	private function emitBlueprintPartition(array $portable): string
	{
		$owned = [];

		foreach ($portable as $e)
		{
			foreach ($e['transport']['children'] ?? [] as $c)
			{
				$owned[$c] = true;
			}
		}

		$id       = 'jcb-Blueprint';
		$features = [];

		foreach (array_keys($portable) as $name)
		{
			if (isset($owned[$name]))
			{
				continue;
			}

			$featureId = $id . '-' . $this->safe($name);

			$this->node($featureId, $this->mpM3('Containment'),
				array_merge(
					$this->named($name, $this->claimKey(
						$this->safe('Blueprint-' . $name), "Blueprint.{$name}")),
					[
						$this->prop($this->mpM3('Feature-optional'), 'true'),
						$this->prop($this->mpM3('Link-multiple'), 'true'),
					]
				),
				[],
				[['reference' => $this->mpM3('Link-type'),
					'targets' => [['resolveInfo' => $name,
						'reference' => $this->conceptIds[$name]]]]],
				$id);

			$features[] = $featureId;
		}

		$this->node($id, $this->mpM3('Concept'),
			array_merge(
				$this->named('Blueprint', $this->claimKey('Blueprint', 'partition concept')),
				[
					$this->prop($this->mpM3('Concept-abstract'), 'false'),
					$this->prop($this->mpM3('Concept-partition'), 'true'),
				]
			),
			[['containment' => $this->mpM3('Classifier-features'), 'children' => $features]],
			[],
			'jcb-language');

		return $id;
	}

	public function diagnostics(): array
	{
		return $this->diagnostics;
	}

	public function featureKeys(): array
	{
		ksort($this->featureKeys);

		return $this->featureKeys;
	}

	/** GUIDs JCB reuses for the same logical field across several entities. */
	public function sharedGuids(): array
	{
		$shared = array_filter($this->guidOwners, static fn($o) => count($o) > 1);
		ksort($shared);

		return $shared;
	}
}

// ---------------------------------------------------------------------------

$commit  = $meta['meta']['jcbCommit'] ?? 'unknown';
$version = substr($commit, 0, 10) . '-1';

$builder = new LanguageBuilder($meta);
$chunk   = $builder->build($version);

file_put_contents($outFile,
	json_encode($chunk, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

// Side-car: the enumeration literal <-> JCB raw value map that Phase 3 needs
// to translate instance values in both directions.
$valueMap = [];

foreach ($meta['enumerations'] ?? [] as $ename => $enum)
{
	foreach ($enum['literals'] as $lit)
	{
		$valueMap[$ename][$lit['name']] = $lit['value'];
	}
}

$mapFile = dirname($outFile) . '/jcb-enum-values.json';
file_put_contents($mapFile,
	json_encode($valueMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$counts = [];

foreach ($chunk['nodes'] as $n)
{
	$k = $n['classifier']['key'];
	$counts[$k] = ($counts[$k] ?? 0) + 1;
}

// Side-car: feature key -> the JCB (entity, property, guid) it stands for.
// Phase 3 needs this in both directions. It also records where JCB reuses a
// single GUID for the same logical field across several entities.
$keyFile = dirname($outFile) . '/jcb-feature-keys.json';
file_put_contents($keyFile, json_encode([
	'featureKeys' => $builder->featureKeys(),
	'sharedGuids' => $builder->sharedGuids(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "
");

ksort($counts);

$diags = $builder->diagnostics();
$bySev = [];

foreach ($diags as $d)
{
	$bySev[$d['severity']] = ($bySev[$d['severity']] ?? 0) + 1;
}

fwrite(STDERR, sprintf(
	"Wrote %s
  language: %s v%s
  nodes: %d %s
  side-cars: %s, %s
  shared guids: %d
  diagnostics: %s
",
	$outFile, LANG_KEY, $version, count($chunk['nodes']), json_encode($counts),
	basename($mapFile), basename($keyFile), count($builder->sharedGuids()),
	json_encode($bySev ?: ['none' => 0])
));

foreach ($diags as $d)
{
	if ($d['severity'] === 'error')
	{
		fwrite(STDERR, "  ERROR [{$d['code']}] {$d['message']}\n");
	}
}
