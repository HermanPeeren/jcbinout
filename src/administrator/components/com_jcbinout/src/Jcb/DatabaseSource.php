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
use Yepr\Component\Jcbinout\Administrator\Blueprint\Payload;

/**
 * Reads what JCB actually holds, as blueprint payloads.
 *
 * The other half of {@see \Yepr\Component\Jcbinout\Administrator\Blueprint\RepositorySource},
 * which reads a blueprint that has been pushed to disk. This reads the tables,
 * so a component somebody is building in JCB's own interface can be exported
 * without first being pushed anywhere - which, until there was this, it could
 * not be.
 *
 * **It produces the same payloads a repository does**, deliberately. Everything
 * downstream - the exporter, the design projection, the round trip - works on
 * `Payload` and does not care which side it came from, so a row read here and
 * the same row read out of a repository have to be indistinguishable. That is
 * why values are decoded on the way out: a payload carries a subform as an
 * array and a PHP snippet as source, while the column holds JSON and base64.
 *
 * **What a payload does not carry, this does not invent.** Joomla's own columns
 * and the ones JCB marks installation-local are left behind, the same ones the
 * planner drops on the way back in, so a row exported from here and re-imported
 * lands on the same columns it started on.
 *
 * @since 1.0.0
 */
final class DatabaseSource
{
	/**
	 * Joomla's trashed state. A trashed row is one somebody deleted; exporting
	 * it would put it back on the next import, which is not what deleting it
	 * meant.
	 */
	private const TRASHED = -2;

	private DatabaseInterface $db;
	private Schema $schema;
	private array $diagnostics = [];
	private array $counts = [];

	public function __construct(Schema $schema, ?DatabaseInterface $db = null)
	{
		$this->schema = $schema;
		$this->db     = $db ?? Factory::getContainer()->get(DatabaseInterface::class);
	}

	/**
	 * Every portable row this installation holds, as payloads.
	 *
	 * @param list<string>|null $only Entities to read, or null for all portable ones.
	 *
	 * @return list<Payload>
	 */
	public function payloads(?array $only = null): array
	{
		$this->diagnostics = [];
		$this->counts      = [];

		$entities = $only ?? $this->schema->portableEntities();
		$found    = [];

		sort($entities);

		foreach ($entities as $entity)
		{
			if (!$this->schema->knows($entity))
			{
				$this->diag('warning', 'ENTITY_UNKNOWN',
					"This JCB has no {$entity}; nothing read for it.", ['entity' => $entity]);

				continue;
			}

			foreach ($this->rowsOf($entity) as $row)
			{
				$payload = $this->payload($entity, $row);

				if ($payload !== null)
				{
					$found[]                 = $payload;
					$this->counts[$entity]   = ($this->counts[$entity] ?? 0) + 1;
				}
			}
		}

		// The same order a repository walk is sorted into, so two exports of
		// the same models compare rather than merely both being correct.
		usort($found, static fn(Payload $a, Payload $b) => [$a->entity, $a->ownerGuid, $a->relativePath]
			<=> [$b->entity, $b->ownerGuid, $b->relativePath]);

		return $found;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function rowsOf(string $entity): array
	{
		$table = $this->schema->table($entity);

		try
		{
			$query = $this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($table));

			// Only where the column exists: not every JCB table is a Joomla
			// item table, and asking for `published` on one that has none
			// fails the whole read rather than the one condition.
			if ($this->hasColumn($table, 'published'))
			{
				$query->where($this->db->quoteName('published') . ' != ' . (int) self::TRASHED);
			}

			$this->db->setQuery($query);

			return $this->db->loadAssocList() ?: [];
		}
		catch (\Throwable $e)
		{
			$this->diag('warning', 'TABLE_UNREADABLE',
				"Could not read {$table}: " . $e->getMessage(), ['entity' => $entity]);

			return [];
		}
	}

	private function hasColumn(string $table, string $column): bool
	{
		static $known = [];

		if (!isset($known[$table]))
		{
			try
			{
				$known[$table] = $this->db->getTableColumns($table, false);
			}
			catch (\Throwable $e)
			{
				$known[$table] = [];
			}
		}

		return isset($known[$table][$column]);
	}

	/**
	 * One row, as the payload a repository would have held for it.
	 */
	private function payload(string $entity, array $row): ?Payload
	{
		$identifier = $this->schema->identifier($entity);
		$owner      = (string) ($row[$identifier] ?? '');

		if ($owner === '')
		{
			// A row with nothing to identify it cannot be addressed, exported
			// or imported. Real tables have these: a record half-created and
			// abandoned, or one whose parent was deleted out from under it.
			$this->diag('warning', 'ROW_NOT_IDENTIFIED',
				"A {$entity} row has no {$identifier}, so it has no portable identity and is not exported.",
				['entity' => $entity]);

			return null;
		}

		$data = [];

		foreach ($row as $column => $value)
		{
			if ($this->schema->isJoomlaColumn($column) || !$this->schema->isPortableColumn($entity, $column))
			{
				continue;
			}

			$data[$column] = $this->schema->decode($entity, $column, $value);
		}

		// Where a repository would have filed this row. Asked for rather than
		// built here: the transport config declares it, and an owned record
		// lives under its parent while `power` lives at the root of `src` and
		// calls its payload something else entirely.
		//
		// Ownership is declared too, and is not the same question as whether
		// the identifier is a guid: custom_code, placeholder and
		// validation_rule are identified by a natural key and own nothing.
		$parent = $this->schema->parentOf($entity);

		return new Payload(
			$entity,
			$owner,
			$this->schema->payloadPath($entity, $owner),
			$data,
			$parent !== null
		);
	}

	/** How many payloads each entity contributed, for the last read. */
	public function counts(): array
	{
		ksort($this->counts);

		return $this->counts;
	}

	public function diagnostics(): array
	{
		return array_values($this->diagnostics);
	}

	private function diag(string $severity, string $code, string $message, array $context = []): void
	{
		// One line per distinct message: forty unidentified rows of one entity
		// is one thing worth saying, not forty.
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
}
