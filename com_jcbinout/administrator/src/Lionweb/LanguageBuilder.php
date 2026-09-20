<?php
/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Lionweb;

\defined('_JEXEC') or die;

/**
 * Builds a LionWeb language (M2) from the derived JCB metamodel.
 *
 * A column's GUID in JCB's Table.php is the portable identity of the field
 * definition that generated it, so one GUID on several entities means one
 * reusable definition used at several use-sites. That drives two decisions:
 * enumerations and subform row concepts reached through a single definition
 * are emitted once, and each shared definition is declared once in an
 * Interface that every using concept implements - keyed by the bare GUID.
 *
 * @since 1.0.0
 */
final class LanguageBuilder
{
	public const LW_FORMAT = '2024.1';
	public const M3        = 'LionCore-M3';
	public const BUILTINS  = 'LionCore-builtins';
	public const LANG_KEY  = 'jcb';
	public const LANG_NAME = 'JCB';

	private array $nodes = [];
	private array $diagnostics = [];
	private array $conceptIds = [];     // entity/row name => node id
	private array $enumIds = [];        // enum name => node id
	private array $usedKeys = [];       // key => owner, for collision detection
	private array $featureKeys = [];    // feature key => {entity, property, guid, kind}
	private array $guidOwners = [];     // jcb guid => list of entity.property using it
	private array $guidSites = [];      // jcb guid => [[entity, property], ...]
	private array $enumCanon = [];      // enum name => canonical enum name
	private array $rowCanon = [];       // row concept => canonical row concept
	private array $hoisted = [];        // "entity.property" => interface node id
	private array $implementsOf = [];   // entity => [interface node id, ...]
	private array $ifaceNames = [];     // signature => configured name
	private array $mergedEnums = 0 ? [] : [];

	public function __construct(private array $meta, array $ifaceNames = [])
	{
		$this->ifaceNames = $ifaceNames;
	}

	// -- metapointer helpers -------------------------------------------------

	private function mpM3(string $key): array
	{
		return ['language' => self::M3, 'version' => self::LW_FORMAT, 'key' => $key];
	}

	private function mpBuiltin(string $key): array
	{
		return ['language' => self::BUILTINS, 'version' => self::LW_FORMAT, 'key' => $key];
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

	/**
	 * A column's GUID in Table.php is the portable identity of the JCB *field
	 * definition* that generated it. Where one GUID appears on several
	 * entities, those columns are one definition used at several use-sites.
	 *
	 * That fact drives two decisions:
	 *   - enumerations and subform row concepts reached through one definition
	 *     are the same type, so they are emitted once (dedupe);
	 *   - the definition itself is declared once, in an Interface that every
	 *     using concept implements, keyed by the bare GUID.
	 */
	private function prepare(array $portable): void
	{
		$enumByGuid = [];
		$rowByGuid  = [];

		foreach ($portable as $entity => $e)
		{
			foreach ($e['properties'] ?? [] as $pname => $p)
			{
				if (empty($p['portable']) || empty($p['guid']))
				{
					continue;
				}

				$g = $p['guid'];
				$this->guidSites[$g][] = [$entity, $pname];

				if (!empty($p['enumeration']))
				{
					$enumByGuid[$g][$p['enumeration']] = true;
				}

				$row = $p['subform']['rowConcept'] ?? null;

				if ($row !== null)
				{
					$rowByGuid[$g][$row] = true;
				}
			}
		}

		foreach ([$enumByGuid, $rowByGuid] as $i => $byGuid)
		{
			foreach ($byGuid as $g => $names)
			{
				$names = array_keys($names);
				sort($names);
				$canon = $names[0];

				foreach ($names as $n)
				{
					if ($i === 0)
					{
						$this->enumCanon[$n] = $canon;
					}
					else
					{
						$this->rowCanon[$n] = $canon;
					}
				}
			}
		}

		// Cluster shared definitions by the exact set of entities using them.
		$clusters = [];

		foreach ($this->guidSites as $g => $sites)
		{
			if (count($sites) < 2)
			{
				continue;
			}

			$ents = array_values(array_unique(array_map(static fn($s) => $s[0], $sites)));
			sort($ents);
			$clusters[implode('+', $ents)][] = $g;
		}

		uasort($clusters, static fn($a, $b) => count($b) <=> count($a));

		foreach ($clusters as $signature => $guids)
		{
			$name = $this->ifaceNames[$signature]['name'] ?? null;

			if ($name === null)
			{
				$parts = explode('+', $signature);
				$name  = 'IShared' . $this->pascal($parts[0]) . count($parts) . 'x' . count($guids);
				$this->diag('warning', 'INTERFACE_NAME_DRIFT',
					"No configured name for cluster '{$signature}'; derived '{$name}'. "
					. 'Add it to interface-names.json.');
			}

			$this->clusterPlan[$signature] = ['name' => $name, 'guids' => $guids];
		}
	}

	private array $clusterPlan = [];
	private array $emittedRows = [];

	private function canonEnum(string $n): string
	{
		return $this->enumCanon[$n] ?? $n;
	}

	private function canonRow(string $n): string
	{
		return $this->rowCanon[$n] ?? $n;
	}

	public function build(string $version): array
	{
		$entities = $this->meta['entities'];
		$portable = array_filter($entities, static fn($e) => $e['portable']);

		$this->prepare($portable);

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
					$row = $this->canonRow($p['subform']['rowConcept']);
					$this->conceptIds[$row] = 'jcb-' . $this->safe($row);
				}
			}
		}

		foreach ($this->meta['enumerations'] ?? [] as $ename => $_)
		{
			$canon = $this->canonEnum($ename);
			$this->enumIds[$ename] = 'jcb-enum-' . $this->safe($canon);
		}

		// Pass 2: emit.
		$entityNodeIds = [];

		foreach ($this->meta['enumerations'] ?? [] as $ename => $enum)
		{
			if ($this->canonEnum($ename) !== $ename)
			{
				continue;   // same field definition, already emitted
			}

			$entityNodeIds[] = $this->emitEnumeration($ename, $enum);
		}

		// Interfaces carry the shared field definitions, declared once each.
		foreach ($this->clusterPlan as $signature => $plan)
		{
			$id = $this->emitInterface($signature, $plan);

			if ($id !== null)
			{
				$entityNodeIds[] = $id;
			}
		}

		foreach ($portable as $name => $e)
		{
			$entityNodeIds[] = $this->emitConcept($name, $e);

			// Row concepts are siblings of their owner in the language.
			foreach ($e['properties'] ?? [] as $pname => $p)
			{
				if (($p['kind'] ?? '') === 'containment' && !empty($p['subform']['rowConcept']))
				{
					$row = $this->canonRow($p['subform']['rowConcept']);

					if (isset($this->emittedRows[$row]))
					{
						continue;   // same field definition, already emitted
					}

					$this->emittedRows[$row] = true;
					$sub = $p['subform'];
					$sub['rowConcept'] = $row;
					$entityNodeIds[] = $this->emitRowConcept($sub, $name, $pname);
				}
			}
		}

		$entityNodeIds[] = $this->emitBlueprintPartition($portable);

		// The Language node itself.
		$this->node(
			'jcb-language',
			$this->mpM3('Language'),
			array_merge(
				$this->named(self::LANG_NAME, self::LANG_KEY),
				[$this->prop($this->mpM3('Language-version'), $version)]
			),
			[['containment' => $this->mpM3('Language-entities'), 'children' => $entityNodeIds]],
			[[
				'reference' => $this->mpM3('Language-dependsOn'),
				'targets'   => [['resolveInfo' => self::BUILTINS,
					'reference' => self::BUILTINS . '-' . str_replace('.', '-', self::LW_FORMAT)]],
			]],
			null
		);

		return [
			'serializationFormatVersion' => self::LW_FORMAT,
			'languages'                  => [
				['key' => self::M3, 'version' => self::LW_FORMAT],
				['key' => self::BUILTINS, 'version' => self::LW_FORMAT],
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

	/**
	 * Emit one interface holding the shared field definitions of a cluster.
	 * A definition is hoistable only when every use-site resolves to the same
	 * shape; otherwise it stays on each concept and is reported.
	 */
	private function emitInterface(string $signature, array $plan): ?string
	{
		$entities = $this->meta['entities'];
		$name     = $plan['name'];
		$id       = 'jcb-iface-' . $this->safe($name);
		$features = [];

		foreach ($plan['guids'] as $guid)
		{
			$sites  = $this->guidSites[$guid];
			$shapes = [];

			foreach ($sites as [$entity, $pname])
			{
				$p = $entities[$entity]['properties'][$pname];
				$shapes[json_encode([
					$p['kind'],
					$p['datatype'] ?? null,
					isset($p['enumeration']) ? $this->canonEnum($p['enumeration']) : null,
					$p['target'] ?? null,
					isset($p['subform']['rowConcept'])
						? $this->canonRow($p['subform']['rowConcept']) : null,
				])] = true;
			}

			if (count($shapes) !== 1)
			{
				$this->diag('warning', 'DEFINITION_NOT_UNIFORM',
					"Field definition {$guid} resolves to " . count($shapes)
					. ' different shapes across its use-sites; left on each concept.');
				continue;
			}

			[$entity, $pname] = $sites[0];
			$p = $entities[$entity]['properties'][$pname];

			// Declared once, so the key is the bare GUID: the identity JCB asserts.
			$featureId = $this->emitFeature($id, $entity, $pname, $p, $guid,
				'jcb-shared-' . $this->safe($guid));

			if ($featureId === null)
			{
				continue;
			}

			$features[] = $featureId;

			foreach ($sites as [$e, $pn])
			{
				$this->hoisted["{$e}.{$pn}"] = $id;
				$this->implementsOf[$e][$id] = $name;
			}
		}

		if ($features === [])
		{
			return null;
		}

		$this->node(
			$id,
			$this->mpM3('Interface'),
			$this->named($name, $this->claimKey($this->safe($name), "interface {$name}")),
			[['containment' => $this->mpM3('Classifier-features'), 'children' => $features]],
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
			if (isset($this->hoisted["{$entity}.{$pname}"]))
			{
				continue;   // declared once on the interface this concept implements
			}

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
			$this->implementsRefs($entity),
			'jcb-language'
		);

		return $id;
	}

	/** Concept-implements, one target per interface the entity participates in. */
	private function implementsRefs(string $entity): array
	{
		$ifaces = $this->implementsOf[$entity] ?? [];

		if ($ifaces === [])
		{
			return [];
		}

		$targets = [];

		foreach ($ifaces as $ifaceId => $ifaceName)
		{
			$targets[] = ['resolveInfo' => $ifaceName, 'reference' => $ifaceId];
		}

		return [['reference' => $this->mpM3('Concept-implements'), 'targets' => $targets]];
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
							. str_replace('.', '-', self::LW_FORMAT)]]]],
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

	private function emitFeature(string $conceptId, string $entity, string $pname, array $p,
		?string $forceKey = null, ?string $forceId = null): ?string
	{
		if (empty($p['portable']))
		{
			return null;   // reference_local, secret, or ignore-listed
		}

		$kind = $p['kind'];
		$id   = $forceId ?? ($conceptId . '-' . $this->safe($pname));

		// JCB's own property GUID carries the stable identity, but GUIDs are
		// reused across entities for structurally identical fields, and LionWeb
		// requires feature keys to be unique language-wide (LionCore itself
		// qualifies: Concept-extends vs Annotation-extends). So qualify by
		// entity: the GUID still survives a column rename, which is the point.
		$guid = $p['guid'] ?? null;

		if ($forceKey !== null)
		{
			// Shared definition declared once on an interface: the bare GUID.
			$key = $this->safe($forceKey);
		}
		elseif ($guid === null)
		{
			$key = $this->safe($entity . '-' . $pname);
			$this->diag('info', 'KEY_SYNTHESISED',
				"Property '{$entity}.{$pname}' has no GUID; key synthesised as '{$key}'.");
		}
		else
		{
			$key = $this->safe($entity . '-' . $guid);
		}

		if ($guid !== null)
		{
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
			$row = isset($p['subform']['rowConcept'])
				? $this->canonRow($p['subform']['rowConcept']) : null;

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
			$ename = $this->canonEnum($p['enumeration']);

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
						. str_replace('.', '-', self::LW_FORMAT),
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

	/**
	 * Field definitions JCB uses at more than one site. Derived from the
	 * definition graph, not from what happened to be emitted: a hoisted
	 * definition is declared once but still has every one of its use-sites.
	 */
	public function sharedGuids(): array
	{
		$shared = [];

		foreach ($this->guidSites as $guid => $sites)
		{
			if (count($sites) < 2)
			{
				continue;
			}

			$shared[$guid] = array_map(static fn($s) => "{$s[0]}.{$s[1]}", $sites);
		}

		ksort($shared);

		return $shared;
	}

	/** entity.property => the interface its definition was hoisted into. */
	public function hoistedInto(): array
	{
		$out = [];

		foreach ($this->hoisted as $site => $ifaceId)
		{
			$out[$site] = $ifaceId;
		}

		ksort($out);

		return $out;
	}
}
