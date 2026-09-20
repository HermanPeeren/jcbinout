<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Blueprint;

\defined('_JEXEC') or die;

use Yepr\Component\Jcbinout\Administrator\Jcb\Schema;

/**
 * Decides what importing a blueprint into JCB would do, without doing any of it.
 *
 * Planning is separate from writing on purpose. A plan is a candidate list: it
 * can be shown, counted and argued with before a single row is touched, and it
 * can be tested without a database, which the writing cannot. It is also the
 * only honest way to offer "initialize" and "reset" as different operations -
 * the difference between them is entirely a matter of what the plan decides,
 * not of how a row is written.
 *
 * @since 1.0.0
 */
final class ImportPlanner
{
	/**
	 * Keep what is already here. A local definition that exists satisfies the
	 * request; only what is missing gets written. This is what lets someone
	 * import a blueprint without losing edits they have made locally.
	 */
	public const INITIALIZE = 'initialize';

	/**
	 * Refresh from the incoming model, overwriting what is already here.
	 */
	public const RESET = 'reset';

	public const INSERT = 'insert';
	public const UPDATE = 'update';
	public const SKIP   = 'skip';

	private Schema $schema;
	private array $diagnostics = [];

	public function __construct(Schema $schema)
	{
		$this->schema = $schema;
	}

	/**
	 * @param list<Payload>              $payloads
	 * @param array<string,list<string>> $existing  entity => identifier values already in JCB
	 * @param string                     $mode      INITIALIZE or RESET
	 *
	 * @return array{mode:string,operations:list<array>,counts:array<string,int>}
	 */
	public function plan(array $payloads, array $existing, string $mode = self::INITIALIZE): array
	{
		$this->diagnostics = [];

		if (!in_array($mode, [self::INITIALIZE, self::RESET], true))
		{
			throw new \InvalidArgumentException("Unknown import mode '{$mode}'.");
		}

		$operations = [];

		foreach ($this->inDependencyOrder($payloads) as $payload)
		{
			$entity = $payload->entity;

			if (!$this->schema->knows($entity))
			{
				$this->diag('error', 'ENTITY_UNKNOWN',
					"The blueprint carries a '{$entity}' payload, which this JCB does not have a table for.",
					['entity' => $entity]);
				continue;
			}

			$identifier = $this->schema->identifier($entity);
			$value      = $payload->ownerGuid;
			$present    = in_array($value, $existing[$entity] ?? [], true);

			$action = match (true)
			{
				!$present          => self::INSERT,
				$mode === self::RESET => self::UPDATE,
				default            => self::SKIP,
			};

			$operations[] = [
				'entity'     => $entity,
				'table'      => $this->schema->table($entity),
				'identifier' => $identifier,
				'value'      => $value,
				'action'     => $action,
				'reason'     => $this->reason($action, $mode, $entity),
				'columns'    => $action === self::SKIP ? [] : $this->columns($payload),
				'path'       => $payload->relativePath,
			];
		}

		$counts = [self::INSERT => 0, self::UPDATE => 0, self::SKIP => 0];

		foreach ($operations as $operation)
		{
			$counts[$operation['action']]++;
		}

		return ['mode' => $mode, 'operations' => $operations, 'counts' => $counts];
	}

	private function reason(string $action, string $mode, string $entity): string
	{
		return match ($action)
		{
			self::INSERT => 'not present in this installation',
			self::UPDATE => 'reset: refreshed from the blueprint',
			default      => 'already present; initialize keeps local work',
		};
	}

	/**
	 * A payload's columns, encoded the way JCB stores them.
	 *
	 * Columns the language does not model are dropped rather than guessed at:
	 * they are the ones JCB itself marks installation-local, and writing a
	 * value for them would be inventing design the blueprint never carried.
	 * Joomla's own columns are left to the writer, which sets them on an insert
	 * and never touches them on an update.
	 */
	private function columns(Payload $payload): array
	{
		$entity  = $payload->entity;
		$columns = [];

		foreach ($payload->designKeys() as $column)
		{
			if ($this->schema->isJoomlaColumn($column))
			{
				continue;
			}

			if (!$this->schema->isPortableColumn($entity, $column))
			{
				$this->diag('info', 'COLUMN_NOT_PORTABLE',
					"{$entity}.{$column} is not portable design; not written.",
					['entity' => $entity, 'column' => $column]);
				continue;
			}

			$columns[$column] = $this->schema->encode($entity, $column, $payload->data[$column]);
		}

		return $columns;
	}

	/**
	 * Order payloads so a row is written after whatever it points at.
	 *
	 * JCB has no foreign keys, so nothing breaks outright if this is wrong; it
	 * matters because a half-applied import should leave a coherent state
	 * rather than references into rows that do not exist yet. Cycles are
	 * possible between entity types and are simply broken in a stable place.
	 *
	 * @param list<Payload> $payloads
	 *
	 * @return list<Payload>
	 */
	private function inDependencyOrder(array $payloads): array
	{
		$entities = [];

		foreach ($payloads as $payload)
		{
			$entities[$payload->entity] = true;
		}

		$ordered = [];
		$state   = [];

		$visit = function (string $entity) use (&$visit, &$ordered, &$state, $entities): void {
			if (($state[$entity] ?? null) !== null)
			{
				return;   // done, or currently being visited: a cycle
			}

			$state[$entity] = 'visiting';

			foreach ($this->schema->referencedEntities($entity) as $target)
			{
				if (isset($entities[$target]))
				{
					$visit($target);
				}
			}

			$state[$entity] = 'done';
			$ordered[]      = $entity;
		};

		// Visit from a sorted starting point, not from the order the payloads
		// happened to be read in: entities with no dependency between them keep
		// whatever order the traversal met them in, so an unsorted start makes
		// the same blueprint plan differently depending on the filesystem.
		$roots = array_keys($entities);
		sort($roots);

		foreach ($roots as $entity)
		{
			$visit($entity);
		}

		$rank = array_flip($ordered);
		$sorted = $payloads;

		usort($sorted, static function (Payload $a, Payload $b) use ($rank) {
			$byEntity = ($rank[$a->entity] ?? PHP_INT_MAX) <=> ($rank[$b->entity] ?? PHP_INT_MAX);

			if ($byEntity !== 0)
			{
				return $byEntity;
			}

			// Stable within an entity, so two plans of the same blueprint match.
			return [$a->ownerGuid, $a->relativePath] <=> [$b->ownerGuid, $b->relativePath];
		});

		return $sorted;
	}

	private function diag(string $severity, string $code, string $message, array $context = []): void
	{
		// One line per distinct message: a blueprint with 45 non-portable
		// columns should say so once per column, not once per row.
		$key = $code . '|' . $message;

		if (isset($this->diagnostics[$key]))
		{
			$this->diagnostics[$key]['count']++;

			return;
		}

		$this->diagnostics[$key] = [
			'severity' => $severity,
			'code'     => $code,
			'message'  => $message,
			'context'  => $context,
			'count'    => 1,
		];
	}

	public function diagnostics(): array
	{
		return array_values($this->diagnostics);
	}
}
