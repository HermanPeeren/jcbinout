<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Lionweb;

\defined('_JEXEC') or die;

use Yepr\Component\Jcbinout\Administrator\Blueprint\Payload;
use Yepr\Component\Jcbinout\Administrator\Jcb\Schema;

/**
 * Turns a LionWeb instance chunk back into JCB blueprint payloads.
 *
 * The inverse of {@see InstanceExporter}, within the bounds the transport model
 * actually allows: it reconstructs the design, not the bytes. Row keys, the
 * difference between an absent column and a null one, and JCB's own storage
 * formatting are not recoverable and are not pretended to be - what has to come
 * back is what {@see \Yepr\Component\Jcbinout\Administrator\Blueprint\DesignProjection}
 * calls design.
 *
 * @since 1.0.0
 */
final class InstanceImporter
{
	private LanguageIndex $index;
	private array $diagnostics = [];

	/** @var array<string,array<string,mixed>> node id => node */
	private array $nodes = [];

	/**
	 * The metamodel, when the caller has one.
	 *
	 * Only the identifying column is wanted from it, and only for the few
	 * entities addressed by something other than a guid. Optional because the
	 * language alone is enough for everything else, and the CLI has no reason
	 * to load a metamodel to read a chunk.
	 */
	private ?Schema $schema;

	public function __construct(LanguageIndex $index, ?Schema $schema = null)
	{
		$this->index  = $index;
		$this->schema = $schema;
	}

	/**
	 * The identity a payload carries in its own columns, if it carries one.
	 *
	 * For everything keyed by a guid this is the node id again and changes
	 * nothing. For a placeholder, keyed by a target that reads `[[[COMPANY]]]`,
	 * it is the difference between handing JCB its target and handing it the
	 * encoding the target travelled under.
	 */
	private function identityIn(string $entity, array $columns): ?string
	{
		if ($this->schema === null || !$this->schema->knows($entity))
		{
			return null;
		}

		$value = $columns[$this->schema->identifier($entity)] ?? null;

		return is_string($value) && $value !== '' ? $value : null;
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
	 * @return list<Payload>
	 */
	public function import(array $chunk): array
	{
		$this->diagnostics = [];
		$this->nodes       = [];

		foreach ($chunk['nodes'] ?? [] as $node)
		{
			$this->nodes[$node['id']] = $node;
		}

		$partition = null;

		foreach ($this->nodes as $node)
		{
			if (($node['parent'] ?? null) === null)
			{
				$partition = $node;
				break;
			}
		}

		if ($partition === null)
		{
			throw new \RuntimeException('The chunk has no root node.');
		}

		$payloads = [];

		// Everything the partition holds is a root definition.
		foreach ($partition['containments'] ?? [] as $containment)
		{
			foreach ($containment['children'] as $childId)
			{
				$node = $this->nodes[$childId] ?? null;

				if ($node === null)
				{
					$this->diag('error', 'CHILD_MISSING',
						"The partition holds {$childId}, which the chunk does not contain.",
						['node' => $childId]);
					continue;
				}

				$payloads = array_merge($payloads, $this->definition($node));
			}
		}

		usort($payloads, static fn(Payload $a, Payload $b) =>
			[$a->entity, $a->ownerGuid, $a->relativePath] <=> [$b->entity, $b->ownerGuid, $b->relativePath]);

		return $payloads;
	}

	/**
	 * One root definition, plus any records it owns.
	 *
	 * @return list<Payload>
	 */
	private function definition(array $node): array
	{
		$entity = $node['classifier']['key'];

		$owned   = [];
		$columns = $this->columns($node, $entity, $owned);

		// The node id is an addressing convention; the identifying column is
		// the identity. They are the same string for everything keyed by a
		// guid, and they are not for the few keyed by a natural key: a
		// placeholder's target reads `[[[COMPANY]]]`, which no chunk will
		// accept as an id, so the id it travels under is an encoding of it.
		// Reading the identity back off the id would hand JCB the encoding.
		$guid = $this->identityIn($entity, $columns) ?? $node['id'];

		$payloads = [new Payload(
			$entity,
			$guid,
			"src/{$entity}/{$guid}/item.json",
			$columns,
			false
		)];

		foreach ($owned as $child)
		{
			$childEntity  = $child['classifier']['key'];
			$ignored      = [];
			$childColumns = $this->columns($child, $childEntity, $ignored);

			$file       = str_replace('_', '-', $childEntity);
			$payloads[] = new Payload(
				$childEntity,
				$guid,
				"src/{$entity}/children/{$guid}/{$file}.json",
				$childColumns,
				true
			);
		}

		return $payloads;
	}

	/**
	 * A node's columns.
	 *
	 * Containments split two ways: a row concept is a subform column, anything
	 * else is a record the definition owns and which becomes its own payload.
	 *
	 * @param array<string,mixed>       $node
	 * @param list<array<string,mixed>> $owned filled with the owned child nodes
	 *
	 * @return array<string,mixed>
	 */
	private function columns(array $node, string $entity, array &$owned): array
	{
		$owned = [];

		// feature key => name, so the payload gets JCB column names back.
		$names = [];

		foreach ($this->index->featuresOf($entity) as $name => $descriptor)
		{
			$names[$descriptor['key']] = ['name' => $name] + $descriptor;
		}

		$columns = [];

		foreach ($node['properties'] ?? [] as $property)
		{
			$key     = $property['property']['key'];
			$feature = $names[$key] ?? null;

			if ($feature === null)
			{
				$this->diag('warning', 'PROPERTY_UNKNOWN',
					"{$entity} carries property {$key}, which the language does not declare for it.",
					['entity' => $entity, 'feature' => $key]);
				continue;
			}

			$columns[$feature['name']] = $this->rawValue($feature, $property['value']);
		}

		foreach ($node['references'] ?? [] as $reference)
		{
			$key     = $reference['reference']['key'];
			$feature = $names[$key] ?? null;

			if ($feature === null)
			{
				continue;
			}

			$targets = $reference['targets'] ?? [];

			if ($targets === [])
			{
				continue;
			}

			// A reference column holds one guid. An unresolved target keeps the
			// identity it was pointing at, which is what resolveInfo is for.
			$first = $targets[0];
			$columns[$feature['name']] = $first['reference'] ?? $first['resolveInfo'];
		}

		foreach ($node['containments'] ?? [] as $containment)
		{
			$key     = $containment['containment']['key'];
			$feature = $names[$key] ?? null;

			if ($feature === null)
			{
				$this->diag('warning', 'CONTAINMENT_UNKNOWN',
					"{$entity} carries containment {$key}, which the language does not declare for it.",
					['entity' => $entity, 'feature' => $key]);
				continue;
			}

			$children = [];

			foreach ($containment['children'] as $childId)
			{
				$child = $this->nodes[$childId] ?? null;

				if ($child === null)
				{
					$this->diag('error', 'CHILD_MISSING',
						"{$entity} contains {$childId}, which the chunk does not hold.",
						['node' => $childId]);
					continue;
				}

				$children[] = $child;
			}

			if ($children === [])
			{
				continue;
			}

			// A containment whose type is a row concept is a subform; anything
			// else is a record owned by this definition.
			if ($this->isRowConcept($feature['typeKey'] ?? null))
			{
				$columns[$feature['name']] = $this->subform($children, $feature['name']);
				continue;
			}

			foreach ($children as $child)
			{
				$owned[] = $child;
			}
		}

		return $columns;
	}

	/**
	 * A row concept exists only to hold a subform's rows; an owned record is a
	 * JCB entity in its own right, so it is in the transport catalogue and the
	 * partition knows about it.
	 */
	private function isRowConcept(?string $conceptKey): bool
	{
		if ($conceptKey === null)
		{
			return false;
		}

		return !array_key_exists($conceptKey, $this->index->partitionSlots())
			&& str_ends_with($conceptKey, 'Row');
	}

	/**
	 * Rebuild a subform.
	 *
	 * JCB keys its rows; those keys are storage labels it regenerates, so the
	 * order is what is reconstructed and the keys are numbered from zero.
	 */
	private function subform(array $children, string $column): array
	{
		$rows = [];
		$i    = 0;

		foreach ($children as $child)
		{
			$rowEntity = $child['classifier']['key'];
			$ignored             = [];
			$rows[(string) $i++] = $this->columns($child, $rowEntity, $ignored);
		}

		return $rows;
	}

	/**
	 * A LionWeb property value back as JCB would have stored it.
	 */
	private function rawValue(array $feature, ?string $value): mixed
	{
		if ($value === null)
		{
			return null;
		}

		if (($feature['typeKind'] ?? null) === 'Enumeration')
		{
			$raw = $this->index->rawValueFor((string) $feature['typeKey'], $value);

			if ($raw === null)
			{
				$this->diag('warning', 'LITERAL_UNKNOWN',
					"{$value} is not a literal of {$feature['typeKey']}; carried as-is.",
					['enumeration' => $feature['typeKey'], 'value' => $value]);

				return $value;
			}

			return $raw;
		}

		if (($feature['typeKey'] ?? null) === 'Boolean')
		{
			return $value === 'true' ? '1' : '0';
		}

		return $value;
	}

	public function diagnostics(): array
	{
		return $this->diagnostics;
	}
}
