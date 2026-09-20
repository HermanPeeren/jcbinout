<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Lionweb;

\defined('_JEXEC') or die;

use Yepr\Component\Jcbinout\Administrator\Blueprint\Payload;
use Yepr\Component\Jcbinout\Administrator\Blueprint\RepositorySource;

/**
 * Turns a JCB blueprint into a LionWeb instance chunk (M1).
 *
 * Definitions keep their JCB guid as their node id: that guid is the portable
 * identity the blueprint is built around, and inventing a new one would throw
 * away the only thing that lets a model survive moving between installations.
 *
 * Occurrences are reified rather than cloned. A subform row such as
 * `admin_fields.addfields[i]` holds a reference to a `field` definition plus
 * the roles that use-site gives it, so it becomes its own node and the
 * definition it points at stays a single node however many views use it.
 *
 * @since 1.0.0
 */
final class InstanceExporter
{
	public const FORMAT = '2024.1';

	private LanguageIndex $index;
	private array $nodes = [];
	private array $diagnostics = [];

	/** node id => true, for reference resolution after the walk. */
	private array $emitted = [];

	/** [node id, feature key, target guid, context] queued for resolution. */
	private array $pendingReferences = [];

	/** Progress callback: fn(string $stage, int $done, int $total): void */
	private $progress = null;

	public function __construct(LanguageIndex $index)
	{
		$this->index = $index;
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

	private function diag(string $severity, string $code, string $message, array $ctx = []): void
	{
		$this->diagnostics[] = array_filter([
			'severity' => $severity,
			'code'     => $code,
			'message'  => $message,
			'context'  => $ctx ?: null,
		], static fn($v) => $v !== null);
	}

	// -- node construction ---------------------------------------------------

	private function mp(string $key): array
	{
		return [
			'language' => $this->index->languageKey(),
			'version'  => $this->index->languageVersion(),
			'key'      => $key,
		];
	}

	private function newNode(string $id, string $conceptKey, ?string $parent): array
	{
		return [
			'id'           => $id,
			'classifier'   => $this->mp($conceptKey),
			'properties'   => [],
			'containments' => [],
			'references'   => [],
			'annotations'  => [],
			'parent'       => $parent,
		];
	}

	/**
	 * An unset property is one that is not listed, rather than one listed with
	 * a null value. Both are schema-legal, but "absent" is what consumers
	 * expect of an optional property that was never given a value - and an
	 * explicit null makes an enumeration undeserialisable in at least one
	 * LionWeb implementation.
	 *
	 * An empty string is a value and is kept: JCB distinguishes an empty text
	 * column from an unselected list, and so does this.
	 */
	private function addProperty(array &$node, string $featureKey, ?string $value): void
	{
		if ($value === null)
		{
			return;
		}

		$node['properties'][] = ['property' => $this->mp($featureKey), 'value' => $value];
	}

	private function addChildren(array &$node, string $featureKey, array $ids): void
	{
		foreach ($node['containments'] as &$c)
		{
			if ($c['containment']['key'] === $featureKey)
			{
				$c['children'] = array_merge($c['children'], $ids);

				return;
			}
		}

		unset($c);
		$node['containments'][] = ['containment' => $this->mp($featureKey), 'children' => $ids];
	}

	private function addReference(array &$node, string $featureKey, ?string $target, string $resolveInfo): void
	{
		foreach ($node['references'] as &$r)
		{
			if ($r['reference']['key'] === $featureKey)
			{
				$r['targets'][] = ['resolveInfo' => $resolveInfo, 'reference' => $target];

				return;
			}
		}

		unset($r);
		$node['references'][] = [
			'reference' => $this->mp($featureKey),
			'targets'   => [['resolveInfo' => $resolveInfo, 'reference' => $target]],
		];
	}

	/**
	 * Whether a reference column actually points at something.
	 *
	 * JCB writes '0' into a reference column that has no selection, the same
	 * way it writes '' into an unselected list. Emitting that as a reference
	 * would produce a target pointing at a definition named "0", which exists
	 * nowhere and never will: portable references are guids.
	 */
	private function hasTarget(mixed $value): bool
	{
		return $value !== null && $value !== '' && $value !== '0' && $value !== 0;
	}

	/** LionWeb ids are restricted to [a-zA-Z0-9_-]. */
	private function safeId(string $s): string
	{
		$safe = trim((string) preg_replace('#[^a-zA-Z0-9_-]+#', '-', $s), '-');

		// '0' is a perfectly good id fragment and a falsy string, so ?: would
		// quietly replace it - and a subform's first row is keyed '0'.
		return $safe === '' ? 'x' : $safe;
	}

	// -- value conversion ----------------------------------------------------

	/**
	 * A JCB stored value as the LionWeb property value for its declared type.
	 *
	 * Everything in LionWeb is carried as a string; what differs is which
	 * string. An enumeration carries its literal's key, not JCB's raw value,
	 * so the mapping has to go through the language.
	 */
	private function propertyValue(array $feature, mixed $raw, string $where): ?string
	{
		if ($raw === null)
		{
			return null;
		}

		if (is_array($raw))
		{
			// A JSON-stored column with no declared row shape. Carrying the
			// encoded text keeps the data; the structure is simply not modelled.
			return json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		$type = $feature['typeKey'] ?? 'String';

		if (($feature['typeKind'] ?? null) === 'Enumeration')
		{
			// JCB writes '' for an unselected list. That is the absence of a
			// value, not a member of the enumeration, so the property is simply
			// not set - which is what "optional" on the feature means.
			if ($raw === '')
			{
				if (empty($feature['optional']))
				{
					$this->diag('warning', 'ENUM_EMPTY_BUT_REQUIRED',
						"{$where} is empty but its feature is not optional.",
						['enumeration' => $type]);
				}

				return null;
			}

			$literal = $this->index->literalKey($type, (string) $raw);

			if ($literal === null)
			{
				$this->diag('warning', 'ENUM_VALUE_UNKNOWN',
					"{$where} holds " . var_export($raw, true)
					. ", which is not a member of {$type}; carried as-is.",
					['enumeration' => $type, 'value' => $raw]);

				return (string) $raw;
			}

			return $literal;
		}

		if ($type === 'Boolean')
		{
			return ((int) $raw) === 1 || $raw === true || $raw === 'true' ? 'true' : 'false';
		}

		if ($type === 'Integer')
		{
			if (!is_numeric($raw))
			{
				// JCB stores '' for an unset integer often enough that failing
				// would be noise, but silently writing '' would be a lie.
				if ($raw === '')
				{
					return null;
				}

				$this->diag('warning', 'INTEGER_NOT_NUMERIC',
					"{$where} is declared Integer but holds " . var_export($raw, true) . '.',
					['value' => $raw]);

				return (string) $raw;
			}

			return (string) (int) $raw;
		}

		return is_scalar($raw) ? (string) $raw : json_encode($raw);
	}

	// -- export --------------------------------------------------------------

	/**
	 * @param list<Payload> $payloads
	 */
	public function export(array $payloads, string $partitionId = 'blueprint'): array
	{
		$this->nodes             = [];
		$this->diagnostics       = [];
		$this->emitted           = [];
		$this->pendingReferences = [];

		$partitionKey = $this->index->partitionConceptKey();

		if ($partitionKey === null)
		{
			throw new \RuntimeException('The language declares no partition concept.');
		}

		$partition = $this->newNode($this->safeId($partitionId), $partitionKey, null);
		$slots     = $this->index->partitionSlots();

		// Owned records attach to their owner, so index them by owner first.
		$childrenByOwner = [];
		$roots           = [];

		foreach ($payloads as $payload)
		{
			if ($payload->isChild)
			{
				$childrenByOwner[$payload->ownerGuid][] = $payload;
				continue;
			}

			$roots[] = $payload;
		}

		$total = count($roots);
		$done  = 0;

		foreach ($roots as $payload)
		{
			$this->tick('definitions', ++$done, $total);

			if (!$this->index->hasConcept($payload->entity))
			{
				$this->diag('error', 'ENTITY_NOT_IN_LANGUAGE',
					"Blueprint holds a '{$payload->entity}' payload, which the language does not define.",
					['entity' => $payload->entity, 'path' => $payload->relativePath]);
				continue;
			}

			$nodeId = $payload->nodeId();
			$node   = $this->newNode($nodeId, $payload->entity, null);

			$this->fill($node, $payload);
			$this->attachOwned($node, $payload, $childrenByOwner[$payload->ownerGuid] ?? []);

			// Where the partition has a slot for this entity it goes there;
			// otherwise it is a definition nothing owns and nothing holds, and
			// leaving it parentless would produce a second root.
			$slot = $slots[$payload->entity] ?? null;

			if ($slot === null)
			{
				$this->diag('warning', 'NO_PARTITION_SLOT',
					"Entity '{$payload->entity}' has no slot on the partition concept; "
					. 'its definitions cannot be attached.',
					['entity' => $payload->entity]);
				continue;
			}

			$node['parent'] = $partition['id'];
			$this->addChildren($partition, $slot['key'], [$nodeId]);

			$this->nodes[$nodeId] = $node;
			$this->emitted[$nodeId] = true;
		}

		// Owned records whose owner was never emitted would otherwise vanish.
		foreach ($childrenByOwner as $ownerGuid => $owned)
		{
			if (isset($this->emitted[$ownerGuid]))
			{
				continue;
			}

			foreach ($owned as $payload)
			{
				$this->diag('error', 'OWNER_MISSING',
					"'{$payload->entity}' is owned by {$ownerGuid}, which the blueprint does not contain.",
					['entity' => $payload->entity, 'owner' => $ownerGuid,
						'path' => $payload->relativePath]);
			}
		}

		$this->nodes[$partition['id']] = $partition;
		$this->emitted[$partition['id']] = true;

		$this->resolveReferences();

		return [
			'serializationFormatVersion' => self::FORMAT,
			'languages'                  => [[
				'key'     => $this->index->languageKey(),
				'version' => $this->index->languageVersion(),
			]],
			'nodes' => array_values($this->nodes),
		];
	}

	/**
	 * An owned record becomes a node contained by its owner, through the
	 * containment the owner's transport config declared.
	 */
	private function attachOwned(array &$owner, Payload $ownerPayload, array $owned): void
	{
		foreach ($owned as $payload)
		{
			if (!$this->index->hasConcept($payload->entity))
			{
				$this->diag('error', 'ENTITY_NOT_IN_LANGUAGE',
					"Owned record '{$payload->entity}' is not defined by the language.",
					['entity' => $payload->entity, 'path' => $payload->relativePath]);
				continue;
			}

			$feature = $this->index->feature($ownerPayload->entity, $payload->entity);

			if ($feature === null || $feature['kind'] !== 'Containment')
			{
				$this->diag('error', 'NO_CONTAINMENT_FOR_CHILD',
					"'{$ownerPayload->entity}' has no containment for its owned "
					. "'{$payload->entity}' record.",
					['owner' => $ownerPayload->entity, 'child' => $payload->entity]);
				continue;
			}

			$childId = $payload->nodeId();
			$child   = $this->newNode($childId, $payload->entity, $owner['id']);

			$this->fill($child, $payload);

			$this->nodes[$childId]   = $child;
			$this->emitted[$childId] = true;

			$this->addChildren($owner, $feature['key'], [$childId]);
		}
	}

	/** Write a payload's columns onto its node. */
	private function fill(array &$node, Payload $payload): void
	{
		$entity = $payload->entity;

		foreach ($payload->designKeys() as $column)
		{
			$value   = $payload->data[$column];
			$feature = $this->index->feature($entity, $column);

			if ($feature === null)
			{
				// Columns JCB excludes from transport, and the row metadata
				// every table carries, are simply not part of the design.
				continue;
			}

			$where = "{$entity}.{$column}";

			switch ($feature['kind'])
			{
				case 'Reference':
					if (!$this->hasTarget($value))
					{
						break;
					}

					$this->pendingReferences[] = [
						'node'    => $node['id'],
						'feature' => $feature['key'],
						'target'  => (string) $value,
						'where'   => $where,
					];
					break;

				case 'Containment':
					$this->fillSubform($node, $payload, $column, $feature, $value);
					break;

				default:
					$this->addProperty($node, $feature['key'],
						$this->propertyValue($feature, $value, $where));
			}
		}
	}

	/**
	 * A subform's rows become occurrence nodes.
	 *
	 * Row keys are JCB's own ('0', '1', 'tabs0'), so the order they appear in
	 * is the order that matters; the key is used for the node id rather than a
	 * counter so that re-exporting an unchanged blueprint gives the same ids.
	 */
	private function fillSubform(array &$node, Payload $payload, string $column,
		array $feature, mixed $value): void
	{
		if (!is_array($value) || $value === [])
		{
			return;
		}

		$rowConcept = $feature['typeKey'] ?? null;

		if ($rowConcept === null || !$this->index->hasConcept($rowConcept))
		{
			// The language carries this subform as an opaque property because
			// JCB declares no row shape for it.
			$this->diag('info', 'SUBFORM_NOT_MODELLED',
				"{$payload->entity}.{$column} has no row concept; its structure is not modelled.",
				['entity' => $payload->entity, 'property' => $column]);

			return;
		}

		$ids = [];

		foreach ($value as $rowKey => $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$rowId = $node['id'] . '--' . $this->safeId($column) . '--' . $this->safeId((string) $rowKey);
			$rowNode = $this->newNode($rowId, $rowConcept, $node['id']);

			foreach ($row as $rowColumn => $rowValue)
			{
				$rowFeature = $this->index->feature($rowConcept, (string) $rowColumn);

				if ($rowFeature === null)
				{
					$this->diag('warning', 'ROW_COLUMN_UNKNOWN',
						"{$payload->entity}.{$column} row '{$rowKey}' has column "
						. "'{$rowColumn}', which {$rowConcept} does not declare.",
						['rowConcept' => $rowConcept, 'column' => $rowColumn]);
					continue;
				}

				$where = "{$rowConcept}.{$rowColumn}";

				if ($rowFeature['kind'] === 'Reference')
				{
					if ($this->hasTarget($rowValue))
					{
						$this->pendingReferences[] = [
							'node'    => $rowId,
							'feature' => $rowFeature['key'],
							'target'  => (string) $rowValue,
							'where'   => $where,
						];
					}

					continue;
				}

				$this->addProperty($rowNode, $rowFeature['key'],
					$this->propertyValue($rowFeature, $rowValue, $where));
			}

			$this->nodes[$rowId]   = $rowNode;
			$this->emitted[$rowId] = true;
			$ids[]                 = $rowId;
		}

		if ($ids !== [])
		{
			$this->addChildren($node, $feature['key'], $ids);
		}
	}

	/**
	 * References are resolved after the walk, because a definition may be
	 * referenced before it has been read.
	 *
	 * A target outside this blueprint is not an error: a blueprint can
	 * legitimately reference a Power or a field that lives in another
	 * repository. LionWeb carries that as a target with resolveInfo and no id,
	 * which says "this points somewhere, and not here" rather than pretending
	 * the reference does not exist.
	 */
	private function resolveReferences(): void
	{
		$unresolved = 0;

		foreach ($this->pendingReferences as $pending)
		{
			$nodeId = $pending['node'];

			if (!isset($this->nodes[$nodeId]))
			{
				continue;
			}

			$target  = $pending['target'];
			$present = isset($this->emitted[$target]);

			if (!$present)
			{
				$unresolved++;
			}

			$this->addReference(
				$this->nodes[$nodeId],
				$pending['feature'],
				$present ? $target : null,
				$target
			);
		}

		if ($unresolved > 0)
		{
			$this->diag('info', 'REFERENCES_OUTSIDE_BLUEPRINT',
				"{$unresolved} reference(s) point at definitions this blueprint does not "
				. 'contain; they carry resolveInfo so another repository can supply them.',
				['count' => $unresolved]);
		}
	}

	public function diagnostics(): array
	{
		return $this->diagnostics;
	}

	/** Counts worth reporting after an export. */
	public function stats(array $chunk): array
	{
		$byConcept = [];

		foreach ($chunk['nodes'] as $node)
		{
			$key             = $node['classifier']['key'];
			$byConcept[$key] = ($byConcept[$key] ?? 0) + 1;
		}

		ksort($byConcept);

		$references = 0;
		$properties = 0;

		foreach ($chunk['nodes'] as $node)
		{
			$properties += count($node['properties']);

			foreach ($node['references'] as $r)
			{
				$references += count($r['targets']);
			}
		}

		return [
			'nodes'      => count($chunk['nodes']),
			'properties' => $properties,
			'references' => $references,
			'byConcept'  => $byConcept,
		];
	}
}
