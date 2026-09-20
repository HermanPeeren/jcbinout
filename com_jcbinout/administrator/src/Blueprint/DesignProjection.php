<?php
/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Blueprint;

\defined('_JEXEC') or die;

use Yepr\Component\Jcbinout\Administrator\Lionweb\LanguageIndex;

/**
 * The normalised design of a blueprint: beta(D) in the transport model.
 *
 * Two blueprints are design-equivalent when their projections are equal. That
 * is a weaker relation than byte equality and deliberately so - a round trip
 * through LionWeb and back cannot preserve things that were never design in the
 * first place, and pretending otherwise would mean either a failing comparison
 * that says nothing or a comparison quietly tuned until it passed.
 *
 * The rule, in full. What it ignores:
 *
 *   - `@dependencies`. Transport instructions, derived from the relationships
 *     the payload already carries, not design in their own right.
 *   - The row keys of a subform. JCB writes 'addfields0', 'tabs0', '0';
 *     they label storage slots, and JCB regenerates them. Row *order* is
 *     compared, because an ordered association is not a set.
 *   - The difference between a column that is absent and one holding null.
 *     LionWeb has no way to say "present and null" that survives every
 *     implementation, so export writes neither.
 *   - Scalar type. JCB stores 1 and '1' interchangeably in the same column
 *     across payloads, so everything scalar is compared as a string.
 *   - Columns the language does not model. A column JCB marks installation-local
 *     - a link keyed on a row id, an encrypted credential, anything on an
 *     entity's `ignore` list - is by JCB's own reckoning not portable design,
 *     and beta is the *portable* projection.
 *   - JCB's no-value sentinels, by declared type: '' in a list column and '0'
 *     in a reference column both mean "nothing selected". Two blueprints that
 *     both say unselected are equal, however they spell it.
 *
 * What it does *not* ignore: an empty string in a text column. That is a value,
 * and it compares unequal to absence.
 *
 * The last two rules were added after the first comparison run, which is worth
 * saying plainly rather than presenting the rule as though it arrived complete.
 * Both follow from what the language declares a column to be, not from what
 * made a difference disappear - and the run also turned up a real defect, a
 * radio column classified Boolean that was losing every value above 1.
 *
 * @since 1.0.0
 */
final class DesignProjection
{
	/**
	 * Project a set of payloads into the comparable design.
	 *
	 * @param list<Payload> $payloads
	 *
	 * @return array<string,array> payload key => normalised columns
	 */
	public static function of(array $payloads, ?LanguageIndex $index = null): array
	{
		$out = [];

		foreach ($payloads as $payload)
		{
			$features = $index?->featuresOf($payload->entity) ?? [];
			$columns  = [];

			foreach ($payload->designKeys() as $column)
			{
				$feature = $features[$column] ?? null;

				if ($index !== null && $feature === null)
				{
					continue;   // not portable design
				}

				$value = self::normalise($payload->data[$column]);

				if ($value === null || self::isUnset($feature, $value))
				{
					continue;
				}

				$columns[$column] = $value;
			}

			ksort($columns);
			$out[self::keyOf($payload)] = $columns;
		}

		ksort($out);

		return $out;
	}

	/**
	 * Whether a value is JCB's way of saying a column has no selection.
	 *
	 * Only for the kinds of column where that is what it means: '' is a real
	 * value in a text column and only "unselected" in a list.
	 */
	private static function isUnset(?array $feature, array|string $value): bool
	{
		if ($feature === null || !is_string($value))
		{
			return false;
		}

		if (($feature['typeKind'] ?? null) === 'Enumeration')
		{
			return $value === '';
		}

		if (($feature['kind'] ?? null) === 'Reference')
		{
			return $value === '' || $value === '0';
		}

		return false;
	}

	public static function keyOf(Payload $payload): string
	{
		return $payload->entity . '|' . $payload->ownerGuid . '|' . ($payload->isChild ? 'child' : 'root');
	}

	/**
	 * A value in comparable form, or null when it carries nothing.
	 */
	private static function normalise(mixed $value): array|string|null
	{
		if ($value === null)
		{
			return null;
		}

		if (is_bool($value))
		{
			return $value ? '1' : '0';
		}

		if (is_scalar($value))
		{
			$text = (string) $value;

			// A column whose text is a JSON structure is compared as that
			// structure: export encodes an unshaped JSON column to text, and an
			// import hands it back as text, while the original was an array.
			$decoded = json_decode($text, true);

			if (is_array($decoded) && $text !== '' && ($text[0] === '{' || $text[0] === '['))
			{
				return self::normaliseList($decoded);
			}

			return $text;
		}

		if (is_array($value))
		{
			return self::normaliseList($value);
		}

		return null;
	}

	/**
	 * Subforms and JSON columns, as an ordered list with the keys dropped.
	 *
	 * The keys are JCB's storage labels; the order is the design.
	 */
	private static function normaliseList(array $value): array
	{
		$out = [];

		foreach ($value as $item)
		{
			if (is_array($item))
			{
				$row = [];

				foreach ($item as $column => $cell)
				{
					$normalised = self::normalise($cell);

					if ($normalised !== null)
					{
						$row[$column] = $normalised;
					}
				}

				ksort($row);
				$out[] = $row;

				continue;
			}

			$normalised = self::normalise($item);

			if ($normalised !== null)
			{
				$out[] = $normalised;
			}
		}

		return $out;
	}

	/**
	 * Compare two projections and describe every difference.
	 *
	 * @return list<array{kind:string,where:string,detail:string}>
	 */
	public static function diff(array $expected, array $actual): array
	{
		$differences = [];

		foreach ($expected as $key => $columns)
		{
			if (!array_key_exists($key, $actual))
			{
				$differences[] = [
					'kind'   => 'payload-missing',
					'where'  => $key,
					'detail' => 'the round trip did not produce this payload',
				];

				continue;
			}

			$differences = array_merge($differences,
				self::diffColumns($key, $columns, $actual[$key]));
		}

		foreach ($actual as $key => $columns)
		{
			if (!array_key_exists($key, $expected))
			{
				$differences[] = [
					'kind'   => 'payload-added',
					'where'  => $key,
					'detail' => 'the round trip produced a payload the source does not have',
				];
			}
		}

		return $differences;
	}

	private static function diffColumns(string $key, array $expected, array $actual): array
	{
		$differences = [];

		foreach ($expected as $column => $value)
		{
			if (!array_key_exists($column, $actual))
			{
				$differences[] = [
					'kind'   => 'column-lost',
					'where'  => "{$key}.{$column}",
					'detail' => 'source has ' . self::show($value) . ', round trip has nothing',
				];

				continue;
			}

			if ($actual[$column] !== $value)
			{
				$differences[] = [
					'kind'   => 'column-changed',
					'where'  => "{$key}.{$column}",
					'detail' => 'source ' . self::show($value)
						. ' became ' . self::show($actual[$column]),
				];
			}
		}

		foreach ($actual as $column => $value)
		{
			if (!array_key_exists($column, $expected))
			{
				$differences[] = [
					'kind'   => 'column-added',
					'where'  => "{$key}.{$column}",
					'detail' => 'round trip invented ' . self::show($value),
				];
			}
		}

		return $differences;
	}

	private static function show(mixed $value): string
	{
		$text = is_array($value)
			? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
			: var_export($value, true);

		$text = (string) $text;

		return \strlen($text) > 120 ? substr($text, 0, 117) . '...' : $text;
	}
}
