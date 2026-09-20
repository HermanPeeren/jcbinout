<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Metamodel;

\defined('_JEXEC') or die;

/**
 * Derives an explicit metamodel from the installed Joomla Component Builder.
 *
 * JCB has no declared metamodel, but it has a machine-readable one spread
 * across three places:
 *
 *   Componentbuilder/Table.php        entity properties: schema and relations,
 *                                     each carrying the GUID of the JCB field
 *                                     definition that generated the column
 *   Componentbuilder/Factory.php      the canonical portable entity catalogue
 *   **\Remote\Config                  per-entity transport projection
 *
 * Those classes are read by reflection, never by parsing PHP text, so the
 * result describes the JCB that is actually installed rather than a version
 * pinned when JcbInOut was built.
 *
 * @since 1.0.0
 */
final class Extractor
{
	public const VERSION = '0.2.0';

	private array $tables = [];
	private array $entityMap = [];
	private array $defaults = [];
	private array $enumerations = [];
	private array $diagnostics = [];

	private Classifier $classifier;
	private ?EnumHarvester $enums;

	/** Progress callback: fn(string $stage, int $done, int $total): void */
	private $progress = null;

	public function __construct(?EnumHarvester $enums = null)
	{
		$this->classifier = new Classifier();
		$this->enums      = $enums;
	}

	public function onProgress(callable $cb): self
	{
		$this->progress = $cb;

		return $this;
	}

	private function tick(string $stage, int $done, int $total): void
	{
		if ($this->progress !== null)
		{
			($this->progress)($stage, $done, $total);
		}
	}

	// -- reflection over the installed JCB -----------------------------------

	private function readProtected(string $class, string $property, bool $static = false)
	{
		$rc   = new \ReflectionClass($class);
		$prop = $rc->getProperty($property);
		$prop->setAccessible(true);

		return $static ? $prop->getValue() : $prop->getValue($rc->newInstanceWithoutConstructor());
	}

	private function load(): void
	{
		if ($this->tables !== [])
		{
			return;
		}

		$this->tables    = $this->readProtected('VDM\\Joomla\\Componentbuilder\\Table', 'tables');
		$this->defaults  = $this->readProtected('VDM\\Joomla\\Componentbuilder\\Table', 'defaults');
		$this->entityMap = $this->readProtected('VDM\\Joomla\\Componentbuilder\\Factory', 'entityMap', true);
	}

	private function diag(string $severity, string $code, string $message, array $ctx = []): void
	{
		$this->diagnostics[] = array_filter([
			'severity' => $severity,
			'code'     => $code,
			'message'  => $message,
			'context'  => $ctx ?: null,
		], static fn($v) => $v !== null);
	}

	/**
	 * Instantiate an entity's transport config.
	 *
	 * Remote configs live in two places: application entities under
	 * Package\<Area>\Remote, and the reusable/distribution entities (Power,
	 * JoomlaPower, Fieldtype, Snippet, Repository) under <Area>\Remote.
	 */
	private function transportFor(string $entity, ?string $area): ?array
	{
		if ($area === null)
		{
			return null;
		}

		$segment = str_replace('.', '', $area);
		$tail    = str_contains($area, '.')
			? substr($area, (int) strrpos($area, '.') + 1)
			: null;

		$candidates = array_values(array_unique(array_filter([
			"VDM\\Joomla\\Componentbuilder\\Package\\{$segment}\\Remote\\Config",
			"VDM\\Joomla\\Componentbuilder\\{$segment}\\Remote\\Config",
			$tail ? "VDM\\Joomla\\Componentbuilder\\Package\\{$tail}\\Remote\\Config" : null,
			$tail ? "VDM\\Joomla\\Componentbuilder\\{$tail}\\Remote\\Config" : null,
		])));

		$class = null;

		foreach ($candidates as $candidate)
		{
			if (class_exists($candidate))
			{
				$class = $candidate;
				break;
			}
		}

		if ($class === null)
		{
			$this->diag('warning', 'CONFIG_MISSING',
				"No remote Config class for entity '{$entity}' (area '{$area}').",
				['entity' => $entity, 'area' => $area, 'tried' => $candidates]);

			return null;
		}

		try
		{
			$config = new $class(new TableAdapter(new \VDM\Joomla\Componentbuilder\Table()));
		}
		catch (\Throwable $e)
		{
			$this->diag('warning', 'CONFIG_UNUSABLE',
				"Could not construct {$class}: " . $e->getMessage(), ['entity' => $entity]);

			return null;
		}

		$get = static function (object $o, string $method) {
			try
			{
				return method_exists($o, $method) ? $o->$method() : null;
			}
			catch (\Throwable $e)
			{
				return null;
			}
		};

		// $ignore defines the portable projection but has no public accessor.
		$raw = static function (object $o, string $property) {
			try
			{
				$p = new \ReflectionProperty($o, $property);
				$p->setAccessible(true);

				return $p->isInitialized($o) ? $p->getValue($o) : null;
			}
			catch (\Throwable $e)
			{
				return null;
			}
		};

		return array_filter([
			'configClass'     => $class,
			'ignore'          => $raw($config, 'ignore'),
			'placeholders'    => $raw($config, 'placeholders') ?: null,
			'guidField'       => $get($config, 'getGuidField'),
			'guidHelperField' => $get($config, 'getGuidHelperField'),
			'indexPath'       => $get($config, 'getIndexPath'),
			'srcPath'         => $get($config, 'getSrcPath'),
			'settingsName'    => $get($config, 'getSettingsName'),
			'children'        => $get($config, 'getChildren'),
			'files'           => $get($config, 'getFiles'),
			'folders'         => $get($config, 'getFolders'),
			'titleName'       => $get($config, 'getTitleName'),
		], static fn($v) => $v !== null);
	}

	// -- derivation ----------------------------------------------------------

	public function extract(): array
	{
		$this->load();

		$entities = [];
		$total    = count($this->tables);
		$done     = 0;

		foreach ($this->tables as $entity => $properties)
		{
			$this->tick('entities', ++$done, $total);

			$area     = $this->entityMap[$entity]['area'] ?? null;
			$portable = isset($this->entityMap[$entity]);

			if (!$portable)
			{
				$this->diag('info', 'NOT_PORTABLE',
					"Entity '{$entity}' exists in Table.php but is not in the transport catalogue; "
					. 'it is installation-local and will not appear in a blueprint.',
					['entity' => $entity]);
			}

			$transport = $portable ? $this->transportFor($entity, $area) : null;
			$ignore    = $transport['ignore'] ?? [];
			$props     = [];

			foreach ($properties as $name => $prop)
			{
				if (!is_array($prop))
				{
					continue;
				}

				$props[$name] = $this->property($entity, $name, $prop, $ignore);
			}

			$entities[$entity] = array_filter([
				'name'       => $entity,
				'area'       => $area,
				'portable'   => $portable,
				'superpower' => $this->entityMap[$entity]['superpower'] ?? null,
				'transport'  => $transport,
				'properties' => $props,
			], static fn($v) => $v !== null);
		}

		foreach (array_keys($this->entityMap) as $entity)
		{
			if (!isset($this->tables[$entity]))
			{
				$this->diag('error', 'CATALOGUE_NO_SCHEMA',
					"Entity '{$entity}' is in the transport catalogue but has no definition in Table.php.",
					['entity' => $entity]);
			}
		}

		$this->checkChildren($entities);

		return $entities;
	}

	private function property(string $entity, string $name, array $prop, array $ignore): array
	{
		$c        = $this->classifier->classify($prop);
		$inIgnore = in_array($name, $ignore, true);

		$entry = [
			'name'     => $prop['name'] ?? $name,
			'guid'     => $prop['guid'] ?? null,
			'label'    => $prop['label'] ?? null,
			'jcbType'  => $prop['type'] ?? null,
			'store'    => $prop['store'] ?? null,
			'kind'     => $c['kind'],
			'portable' => $c['portable'] && !$inIgnore,
		];

		if ($inIgnore)
		{
			$entry['excludedBy'] = 'transport-ignore';
		}

		if (!empty($prop['db']) && is_array($prop['db']))
		{
			$entry['db'] = [
				'type'      => $prop['db']['type'] ?? null,
				'default'   => $prop['db']['default'] ?? null,
				'null'      => $prop['db']['null_switch'] ?? null,
				'key'       => $prop['db']['key'] ?? null,
				'uniqueKey' => $prop['db']['unique_key'] ?? null,
			];
		}

		foreach (['datatype', 'encoding', 'target', 'targetKey'] as $k)
		{
			if (!empty($c[$k]))
			{
				$entry[$k] = $c[$k];
			}
		}

		if ($entry['guid'] === null && $entry['portable'])
		{
			$this->diag('warning', 'PROPERTY_NO_GUID',
				"Portable property '{$entity}.{$name}' has no GUID; a stable feature key must be synthesised.",
				['entity' => $entity, 'property' => $name]);
		}

		if (isset($c['target']) && !isset($this->tables[$c['target']]))
		{
			$this->diag('error', 'LINK_TARGET_UNKNOWN',
				"Property '{$entity}.{$name}' links to unknown entity '{$c['target']}'.",
				['entity' => $entity, 'property' => $name, 'target' => $c['target']]);
		}

		if ($c['kind'] === 'containment')
		{
			if (!empty($c['hasRowShape']))
			{
				$entry['subform'] = [
					'rowConcept' => $this->rowConceptName($entity, $name),
					'fields'     => $this->classifier->classifyRow($prop['fields']),
				];
			}
			else
			{
				$entry['subform'] = ['rowConcept' => null, 'fields' => []];
				$this->diag('warning', 'SUBFORM_NO_SHAPE',
					"Subform '{$entity}.{$name}' declares no row shape; it must fall back to a JSON blob (lossy).",
					['entity' => $entity, 'property' => $name]);
			}
		}

		if (($c['datatype'] ?? null) === 'Enumeration?')
		{
			$this->resolveEnumeration($entity, $name, $entry);
		}

		return $entry;
	}

	/**
	 * Table.php records that a property is a list but not what its members are.
	 * They live in JCB's admin form XML.
	 */
	private function resolveEnumeration(string $entity, string $name, array &$entry): void
	{
		$harvested = $this->enums?->harvest($entity, $name);

		if ($harvested === null)
		{
			$entry['datatype'] = 'String';
			$this->diag('warning', 'ENUM_OPTIONS_UNRESOLVED',
				"Property '{$entity}.{$name}' is a list but no options were found in "
				. "the admin form for '{$entity}'; falling back to String.",
				['entity' => $entity, 'property' => $name]);

			return;
		}

		$enumName = $this->enumName($entity, $name);

		$this->enumerations[$enumName] = [
			'name'        => $enumName,
			'origin'      => ['entity' => $entity, 'property' => $name],
			'allowsEmpty' => $harvested['allowsEmpty'],
			'literals'    => $harvested['literals'],
		];

		$entry['datatype']    = 'Enumeration';
		$entry['enumeration'] = $enumName;
		$entry['optional']    = $harvested['allowsEmpty'];
	}

	private function enumName(string $entity, string $property): string
	{
		return $this->pascal($entity) . $this->pascal($property);
	}

	private function rowConceptName(string $entity, string $property): string
	{
		return $this->pascal($entity) . $this->pascal($property) . 'Row';
	}

	private function pascal(string $s): string
	{
		return str_replace(' ', '', ucwords(str_replace('_', ' ', $s)));
	}

	/**
	 * A declared child should have some property pointing back at its parent.
	 * Where it does not, transport config and schema disagree.
	 */
	private function checkChildren(array $entities): void
	{
		foreach ($entities as $entity => $def)
		{
			foreach ($def['transport']['children'] ?? [] as $child)
			{
				if (!isset($entities[$child]))
				{
					$this->diag('error', 'CHILD_UNKNOWN',
						"Entity '{$entity}' declares child '{$child}' which has no definition.",
						['entity' => $entity, 'child' => $child]);
					continue;
				}

				foreach ($entities[$child]['properties'] ?? [] as $p)
				{
					if (($p['target'] ?? null) === $entity)
					{
						continue 2;
					}
				}

				$this->diag('warning', 'CHILD_NO_BACKREF',
					"Child '{$child}' of '{$entity}' has no property linking back to its parent; "
					. 'the containment edge is implied by transport config only.',
					['entity' => $entity, 'child' => $child]);
			}
		}
	}

	// -- document ------------------------------------------------------------

	/**
	 * The complete metamodel document, ready to persist.
	 */
	public function document(array $provenance = []): array
	{
		$entities = $this->extract();

		$stats = [
			'entitiesTotal'         => count($entities),
			'entitiesPortable'      => count(array_filter($entities, static fn($e) => $e['portable'])),
			'propertiesTotal'       => 0,
			'byKind'                => [],
			'enumerations'          => count($this->enumerations),
			'enumerationLiterals'   => 0,
			'diagnosticsBySeverity' => [],
		];

		foreach ($entities as $e)
		{
			foreach ($e['properties'] ?? [] as $p)
			{
				$stats['propertiesTotal']++;
				$stats['byKind'][$p['kind']] = ($stats['byKind'][$p['kind']] ?? 0) + 1;
			}
		}

		foreach ($this->enumerations as $e)
		{
			$stats['enumerationLiterals'] += count($e['literals']);
		}

		foreach ($this->diagnostics as $d)
		{
			$stats['diagnosticsBySeverity'][$d['severity']] =
				($stats['diagnosticsBySeverity'][$d['severity']] ?? 0) + 1;
		}

		ksort($stats['byKind']);
		ksort($this->enumerations);

		// The columns Joomla manages on every table. An import has to set them on
		// an insert and leave them alone on an update; without the list it would
		// either write nothing valid or trample created/modified on every run.
		$defaults = array_keys($this->defaults);
		sort($defaults);

		return [
			'meta' => array_merge([
				'generator'        => 'jcbinout-metamodel-extractor',
				'generatorVersion' => self::VERSION,
				'extractedAt'      => gmdate('c'),
			], $provenance),
			'stats'        => $stats,
			'joomlaColumns' => $defaults,
			'enumerations' => $this->enumerations,
			'entities'     => $entities,
			'diagnostics'  => $this->diagnostics,
		];
	}

	public function defaults(): array
	{
		$this->load();

		return $this->defaults;
	}

	public function diagnostics(): array
	{
		return $this->diagnostics;
	}
}
