<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Jcb;

\defined('_JEXEC') or die;

/**
 * How JCB stores what the LionWeb language deliberately does not model.
 *
 * The language is the authority on what a column *is* - its feature key, its
 * type, whether it is a reference or a containment. It says nothing about how
 * JCB writes the value to disk, because that is a storage concern and no part
 * of the portable design. Which table a row belongs in, which column identifies
 * it, and whether the value is base64-encoded, JSON-encoded or neither all come
 * from the derived metamodel instead.
 *
 * @since 1.0.0
 */
final class Schema
{
	public const TABLE_PREFIX = '#__componentbuilder_';

	private array $entities;

	/** Columns Joomla manages on every table. */
	private array $joomlaColumns;

	public function __construct(array $metamodel)
	{
		$this->entities      = $metamodel['entities'] ?? [];
		$this->joomlaColumns = $metamodel['joomlaColumns'] ?? [];
	}

	public function knows(string $entity): bool
	{
		return isset($this->entities[$entity]);
	}

	/** Every entity JCB will accept in a blueprint. */
	public function portableEntities(): array
	{
		return array_keys(array_filter(
			$this->entities,
			static fn(array $e) => !empty($e['portable'])
		));
	}

	public function table(string $entity): string
	{
		return self::TABLE_PREFIX . $entity;
	}

	/**
	 * The column that identifies a row of this entity.
	 *
	 * Usually `guid`. An owned record has no guid of its own and is addressed
	 * by its parent instead - `admin_fields` is identified by `admin_view` -
	 * which is why this is asked for rather than assumed.
	 */
	public function identifier(string $entity): string
	{
		return $this->entities[$entity]['transport']['guidField'] ?? 'guid';
	}

	/** null, 'base64' or 'json'. */
	public function store(string $entity, string $column): ?string
	{
		return $this->entities[$entity]['properties'][$column]['store'] ?? null;
	}

	public function isPortableColumn(string $entity, string $column): bool
	{
		return !empty($this->entities[$entity]['properties'][$column]['portable']);
	}

	public function isJoomlaColumn(string $column): bool
	{
		return in_array($column, $this->joomlaColumns, true);
	}

	public function joomlaColumns(): array
	{
		return $this->joomlaColumns;
	}

	/** Entities this one owns, as declared by its transport config. */
	public function children(string $entity): array
	{
		return $this->entities[$entity]['transport']['children'] ?? [];
	}

	/**
	 * The entity that owns this one, if any owns it.
	 *
	 * Not the same question as whether {@see identifier()} is a guid, though it
	 * looks like it. `custom_code`, `placeholder` and `validation_rule` are
	 * identified by a natural key - a function name, a target, a rule name -
	 * and are nobody's children; reading a missing guid as "this is an owned
	 * record" files them in the wrong place, under a parent that does not
	 * exist. Ownership is declared, so it is read rather than inferred.
	 */
	public function parentOf(string $entity): ?string
	{
		foreach ($this->entities as $name => $definition)
		{
			if (in_array($entity, $definition['transport']['children'] ?? [], true))
			{
				return $name;
			}
		}

		return null;
	}

	/**
	 * Entities this one points at by portable reference.
	 *
	 * Used to write in dependency order, so a row that refers to a definition
	 * is written after the definition it refers to.
	 */
	public function referencedEntities(string $entity): array
	{
		$targets = [];

		foreach ($this->entities[$entity]['properties'] ?? [] as $property)
		{
			if (($property['kind'] ?? null) === 'reference'
				&& !empty($property['portable'])
				&& !empty($property['target']))
			{
				$targets[$property['target']] = true;
			}

			foreach ($property['subform']['fields'] ?? [] as $field)
			{
				if (($field['kind'] ?? null) === 'reference' && !empty($field['target']))
				{
					$targets[$field['target']] = true;
				}
			}
		}

		unset($targets[$entity]);

		return array_keys($targets);
	}

	/**
	 * Encode a value the way JCB stores it.
	 *
	 * The export decoded these on the way out; an import that skipped the
	 * re-encoding would write JSON where JCB expects base64 and produce rows
	 * the compiler cannot read.
	 */
	public function encode(string $entity, string $column, mixed $value): mixed
	{
		if ($value === null)
		{
			return null;
		}

		return match ($this->store($entity, $column))
		{
			'json'   => is_array($value)
				? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
				: $value,
			'base64' => is_string($value) ? base64_encode($value) : $value,
			default  => is_array($value)
				? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
				: $value,
		};
	}

	/**
	 * Read a stored value back the way a blueprint payload carries it.
	 *
	 * The inverse of {@see encode()}, and the reason a row read out of JCB's
	 * tables can be compared with one read out of a repository at all: a
	 * payload holds a subform as an array and a PHP snippet as source, while
	 * the column holds JSON and base64.
	 *
	 * **What an empty value reads as depends on the column.** In a text column
	 * `''` is an empty string and stays one - the design projection is right to
	 * notice the difference between that and nothing. In a JSON column it is
	 * not a value at all: the column's SQL default is `''`, an emptied subform
	 * leaves `[]` behind, and a blueprint payload carries `null` for both. This
	 * was found by reading the same models off disk and out of the tables and
	 * finding three columns where they disagreed.
	 */
	public function decode(string $entity, string $column, mixed $value): mixed
	{
		if ($value === null || !is_string($value))
		{
			return $value;
		}

		$store = $this->store($entity, $column);

		if ($store === 'json' && self::isEmptyJson($value))
		{
			return null;
		}

		if ($value === '')
		{
			return $value;
		}

		return match ($store)
		{
			'base64' => base64_decode($value, true) ?: $value,
			'json'   => self::asArray($value) ?? $value,
			// A column JCB does not declare a store for may still hold JSON -
			// the subform columns of an owned record are the common case - so
			// it is offered the same reading, and keeps its string if it is
			// not one.
			default  => self::asArray($value) ?? $value,
		};
	}

	/**
	 * A JSON object or array, decoded, or null when the string is neither.
	 *
	 * Deliberately not `is_numeric`-blind: `json_decode('7')` succeeds and
	 * would turn a column holding the string `7` into an integer, which is a
	 * change of type a payload never makes.
	 */
	/**
	 * Every way JCB leaves a JSON column holding nothing.
	 *
	 * The SQL default is `''`; emptying a subform in the interface leaves the
	 * encoding of an empty list or object behind. All three mean the same
	 * thing, and a payload says it with `null`.
	 */
	private static function isEmptyJson(string $value): bool
	{
		return in_array(trim($value), ['', '[]', '{}', 'null'], true);
	}

	private static function asArray(string $value): ?array
	{
		$first = $value[0];

		if ($first !== '{' && $first !== '[')
		{
			return null;
		}

		$decoded = json_decode($value, true);

		return is_array($decoded) ? $decoded : null;
	}
}
