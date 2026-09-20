<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Lionweb;

\defined('_JEXEC') or die;

/**
 * Reads a generated JCB language back, so instance export can ask it what a
 * given JCB entity and column became.
 *
 * The language chunk is the authority, not the metamodel it was derived from:
 * deduplication and interface hoisting both change what a property's feature
 * key and type actually are, and re-deriving those decisions here would be a
 * second implementation free to drift from the first.
 *
 * Feature names are JCB column names, so a lookup is (entity, column).
 *
 * @since 1.0.0
 */
final class LanguageIndex
{
	private const FEATURE_KINDS = ['Property', 'Containment', 'Reference'];

	/** @var array<string,array> node id => node */
	private array $nodes = [];

	/** concept key => [feature name => descriptor] */
	private array $features = [];

	/** enumeration key => [raw JCB value => literal key] */
	private array $literals = [];

	/** the partition concept's containments: entity key => feature key */
	private array $partitionSlots = [];

	private string $languageKey = 'jcb';
	private string $languageVersion = '';

	/**
	 * @param array $chunk     the generated language
	 * @param array $enumValues enum name => [literal name => raw JCB value]
	 */
	public function __construct(array $chunk, array $enumValues = [])
	{
		foreach ($chunk['nodes'] ?? [] as $node)
		{
			$this->nodes[$node['id']] = $node;
		}

		$this->indexLanguage();
		$this->indexFeatures();
		$this->indexLiterals($enumValues);
		$this->indexPartition();
	}

	public static function fromFiles(string $languageFile, string $enumValuesFile): self
	{
		$chunk = json_decode((string) @file_get_contents($languageFile), true);

		if (!is_array($chunk))
		{
			throw new \RuntimeException("Cannot read the language at {$languageFile}.");
		}

		$values = json_decode((string) @file_get_contents($enumValuesFile), true);

		return new self($chunk, is_array($values) ? $values : []);
	}

	// -- indexing ------------------------------------------------------------

	private function prop(array $node, string $key): ?string
	{
		foreach ($node['properties'] ?? [] as $p)
		{
			if (($p['property']['key'] ?? null) === $key)
			{
				return $p['value'];
			}
		}

		return null;
	}

	private function name(array $node): ?string
	{
		return $this->prop($node, 'LionCore-builtins-INamed-name');
	}

	private function key(array $node): ?string
	{
		return $this->prop($node, 'IKeyed-key');
	}

	private function children(array $node, string $containmentKey): array
	{
		foreach ($node['containments'] ?? [] as $c)
		{
			if (($c['containment']['key'] ?? null) === $containmentKey)
			{
				return $c['children'];
			}
		}

		return [];
	}

	private function referenceTargets(array $node, string $referenceKey): array
	{
		foreach ($node['references'] ?? [] as $r)
		{
			if (($r['reference']['key'] ?? null) === $referenceKey)
			{
				return $r['targets'];
			}
		}

		return [];
	}

	private function indexLanguage(): void
	{
		foreach ($this->nodes as $node)
		{
			if (($node['classifier']['key'] ?? null) === 'Language')
			{
				$this->languageKey     = (string) $this->key($node);
				$this->languageVersion = (string) $this->prop($node, 'Language-version');

				return;
			}
		}
	}

	/**
	 * A concept's usable features are its own plus those of every interface it
	 * implements: hoisting a shared definition into an interface is exactly
	 * what makes a column's feature live somewhere other than its own concept.
	 */
	private function indexFeatures(): void
	{
		foreach ($this->nodes as $id => $node)
		{
			if (($node['classifier']['key'] ?? null) !== 'Concept')
			{
				continue;
			}

			$conceptKey = (string) $this->key($node);
			$features   = $this->ownFeatures($node);

			foreach ($this->referenceTargets($node, 'Concept-implements') as $target)
			{
				$ifaceId = $target['reference'] ?? null;

				if ($ifaceId === null || !isset($this->nodes[$ifaceId]))
				{
					continue;
				}

				// A concept's own declaration wins over an inherited one.
				$features += $this->ownFeatures($this->nodes[$ifaceId]);
			}

			$this->features[$conceptKey] = $features;
		}
	}

	/** @return array<string,array> feature name => descriptor */
	private function ownFeatures(array $classifier): array
	{
		$out = [];

		foreach ($this->children($classifier, 'Classifier-features') as $featureId)
		{
			$feature = $this->nodes[$featureId] ?? null;

			if ($feature === null)
			{
				continue;
			}

			$kind = $feature['classifier']['key'] ?? '';

			if (!in_array($kind, self::FEATURE_KINDS, true))
			{
				continue;
			}

			$typeId = $kind === 'Property'
				? ($this->referenceTargets($feature, 'Property-type')[0]['reference'] ?? null)
				: ($this->referenceTargets($feature, 'Link-type')[0]['reference'] ?? null);

			$typeNode = $typeId !== null ? ($this->nodes[$typeId] ?? null) : null;

			$out[(string) $this->name($feature)] = [
				'key'      => (string) $this->key($feature),
				'kind'     => $kind,
				'multiple' => $this->prop($feature, 'Link-multiple') === 'true',
				'optional' => $this->prop($feature, 'Feature-optional') === 'true',
				'typeKey'  => $typeNode !== null
					? (string) $this->key($typeNode)
					: $this->builtinName($typeId),
				'typeKind' => $typeNode !== null ? ($typeNode['classifier']['key'] ?? null) : 'PrimitiveType',
			];
		}

		return $out;
	}

	/** Builtin primitive ids look like LionCore-builtins-String-2024-1. */
	private function builtinName(?string $typeId): ?string
	{
		if ($typeId === null)
		{
			return null;
		}

		if (preg_match('#^LionCore-builtins-(String|Integer|Boolean)-#', $typeId, $m))
		{
			return $m[1];
		}

		return null;
	}

	private function indexLiterals(array $enumValues): void
	{
		foreach ($this->nodes as $node)
		{
			if (($node['classifier']['key'] ?? null) !== 'Enumeration')
			{
				continue;
			}

			$enumKey = (string) $this->key($node);
			$byName  = $enumValues[$enumKey] ?? [];

			foreach ($this->children($node, 'Enumeration-literals') as $literalId)
			{
				$literal = $this->nodes[$literalId] ?? null;

				if ($literal === null)
				{
					continue;
				}

				$literalName = (string) $this->name($literal);
				$rawValue    = $byName[$literalName] ?? null;

				if ($rawValue === null)
				{
					continue;
				}

				// JCB stores these as strings even when they look numeric.
				$this->literals[$enumKey][(string) $rawValue] = (string) $this->key($literal);
			}
		}
	}

	private function indexPartition(): void
	{
		foreach ($this->nodes as $node)
		{
			if (($node['classifier']['key'] ?? null) !== 'Concept'
				|| $this->prop($node, 'Concept-partition') !== 'true')
			{
				continue;
			}

			foreach ($this->ownFeatures($node) as $name => $descriptor)
			{
				$this->partitionSlots[$name] = $descriptor;
			}

			return;
		}
	}

	// -- queries -------------------------------------------------------------

	public function languageKey(): string
	{
		return $this->languageKey;
	}

	public function languageVersion(): string
	{
		return $this->languageVersion;
	}

	public function hasConcept(string $entity): bool
	{
		return isset($this->features[$entity]);
	}

	/** @return array<string,array> feature name => descriptor */
	public function featuresOf(string $entity): array
	{
		return $this->features[$entity] ?? [];
	}

	public function feature(string $entity, string $property): ?array
	{
		return $this->features[$entity][$property] ?? null;
	}

	/**
	 * The literal key an enumeration uses for a raw JCB value.
	 */
	public function literalKey(string $enumeration, string $rawValue): ?string
	{
		return $this->literals[$enumeration][$rawValue] ?? null;
	}

	/**
	 * The raw JCB value behind a literal key: the inverse of literalKey().
	 */
	public function rawValueFor(string $enumeration, string $literalKey): ?string
	{
		$found = array_search($literalKey, $this->literals[$enumeration] ?? [], true);

		return $found === false ? null : (string) $found;
	}

	/** Entities the partition holds directly, as name => descriptor. */
	public function partitionSlots(): array
	{
		return $this->partitionSlots;
	}

	public function partitionConceptKey(): ?string
	{
		foreach ($this->nodes as $node)
		{
			if (($node['classifier']['key'] ?? null) === 'Concept'
				&& $this->prop($node, 'Concept-partition') === 'true')
			{
				return (string) $this->key($node);
			}
		}

		return null;
	}
}
