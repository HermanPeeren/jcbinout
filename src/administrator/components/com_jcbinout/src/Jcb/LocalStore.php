<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Jcb;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Yepr\Component\Jcbinout\Administrator\Blueprint\ImportPlanner;

/**
 * Reads and writes JCB's own tables.
 *
 * Everything that decides *what* to write lives in
 * {@see ImportPlanner}. This executes a plan and reports what happened, which
 * is the only part that needs a database and the only part that cannot be
 * tested without one.
 *
 * @since 1.0.0
 */
final class LocalStore
{
	private DatabaseInterface $db;
	private Schema $schema;
	private array $diagnostics = [];

	public function __construct(Schema $schema, ?DatabaseInterface $db = null)
	{
		$this->schema = $schema;
		$this->db     = $db ?? Factory::getContainer()->get(DatabaseInterface::class);
	}

	private function diag(string $severity, string $code, string $message, array $context = []): void
	{
		$this->diagnostics[] = array_filter([
			'severity' => $severity,
			'code'     => $code,
			'message'  => $message,
			'context'  => $context ?: null,
		], static fn($v) => $v !== null);
	}

	/**
	 * Which identifier values this installation already has, per entity.
	 *
	 * The planner needs this to tell an insert from an update, and asking once
	 * per entity rather than once per row keeps a 33-payload import to a
	 * handful of queries.
	 *
	 * @param list<string> $entities
	 *
	 * @return array<string,list<string>>
	 */
	public function existingIdentifiers(array $entities): array
	{
		$found = [];

		foreach (array_unique($entities) as $entity)
		{
			if (!$this->schema->knows($entity))
			{
				continue;
			}

			$table      = $this->schema->table($entity);
			$identifier = $this->schema->identifier($entity);

			try
			{
				$query = $this->db->getQuery(true)
					->select($this->db->quoteName($identifier))
					->from($this->db->quoteName($table));

				$this->db->setQuery($query);
				$found[$entity] = array_map('strval', $this->db->loadColumn() ?: []);
			}
			catch (\Throwable $e)
			{
				// A table that is not there is a fact about this installation,
				// not a reason to abandon the import: everything else can still
				// be planned, and the plan will show these as inserts.
				$this->diag('warning', 'TABLE_UNREADABLE',
					"Could not read {$table}: " . $e->getMessage(),
					['entity' => $entity]);

				$found[$entity] = [];
			}
		}

		return $found;
	}

	/**
	 * Execute a plan, or as much of one as there is time for.
	 *
	 * Each operation is its own statement and its own outcome. A failure is
	 * recorded and the rest continue, because a blueprint half-imported with a
	 * list of what failed is more use than one abandoned at the first bad row
	 * with nothing said about the rest.
	 *
	 * With a deadline it stops at the next operation boundary once the time is
	 * spent and says how many it got through, so the caller can come back for
	 * the rest in another request. The budget is enforced here because this is
	 * the only thing that knows what a write costs - the planner decides what
	 * to write, and how long that takes is not a decision.
	 *
	 * **At least one operation runs per call**, whatever the deadline says. A
	 * budget already spent on arrival would otherwise consume nothing, and a
	 * run that consumes nothing never ends.
	 *
	 * @param callable|null $progress fn(string $stage, int $done, int $total)
	 * @param float|null    $deadline A `microtime(true)` after which to stop.
	 *
	 * @return array{applied:int,failed:int,skipped:int,consumed:int,results:list<array>}
	 */
	public function apply(array $plan, ?callable $progress = null, ?float $deadline = null): array
	{
		$operations = $plan['operations'] ?? [];
		$total      = count($operations);
		$done       = 0;

		$applied = 0;
		$failed  = 0;
		$skipped = 0;
		$results = [];

		$user = Factory::getApplication()->getIdentity();
		$now  = Factory::getDate()->toSql();

		foreach ($operations as $operation)
		{
			if ($done > 0 && $deadline !== null && microtime(true) >= $deadline)
			{
				break;
			}

			$done++;

			if ($progress !== null)
			{
				$progress('writing', $done, $total);
			}

			if ($operation['action'] === ImportPlanner::SKIP)
			{
				$skipped++;
				continue;
			}

			try
			{
				$operation['action'] === ImportPlanner::INSERT
					? $this->insert($operation, (int) ($user->id ?? 0), $now)
					: $this->update($operation, (int) ($user->id ?? 0), $now);

				$applied++;
			}
			catch (\Throwable $e)
			{
				$failed++;
				$results[] = [
					'action'  => $operation['action'],
					'entity'  => $operation['entity'],
					'value'   => $operation['value'],
					'error'   => $e->getMessage(),
				];

				$this->diag('error', 'WRITE_FAILED',
					"{$operation['action']} into {$operation['table']} for "
					. "{$operation['value']} failed: " . $e->getMessage(),
					['entity' => $operation['entity']]);
			}
		}

		return [
			'applied'  => $applied,
			'failed'   => $failed,
			'skipped'  => $skipped,
			// What the caller has to advance by, which is not applied + failed
			// + skipped once a deadline can cut a slice short.
			'consumed' => $done,
			'results'  => $results,
		];
	}

	/**
	 * Joomla's own columns are set here and nowhere else: the blueprint has no
	 * business carrying a created_by, and the planner drops them for that
	 * reason.
	 */
	private function insert(array $operation, int $userId, string $now): void
	{
		$columns = $operation['columns'];
		$columns[$operation['identifier']] = $operation['value'];

		$columns += [
			'published'  => 1,
			'created'    => $now,
			'created_by' => $userId,
			'version'    => 1,
			'ordering'   => 0,
			'access'     => 1,
			'params'     => '{}',
		];

		$row = (object) $columns;
		$this->db->insertObject($operation['table'], $row);
	}

	/**
	 * An update touches the design and `modified`, and leaves everything else -
	 * including created, published and ordering - exactly as the site had it.
	 */
	private function update(array $operation, int $userId, string $now): void
	{
		$columns = $operation['columns'];

		if ($columns === [])
		{
			return;
		}

		$query = $this->db->getQuery(true)->update($this->db->quoteName($operation['table']));

		foreach ($columns as $column => $value)
		{
			$query->set($this->db->quoteName($column) . ' = ' . $this->db->quote((string) $value));
		}

		$query->set($this->db->quoteName('modified') . ' = ' . $this->db->quote($now));
		$query->set($this->db->quoteName('modified_by') . ' = ' . (int) $userId);

		$query->where($this->db->quoteName($operation['identifier'])
			. ' = ' . $this->db->quote($operation['value']));

		$this->db->setQuery($query);
		$this->db->execute();
	}

	public function diagnostics(): array
	{
		return $this->diagnostics;
	}
}
