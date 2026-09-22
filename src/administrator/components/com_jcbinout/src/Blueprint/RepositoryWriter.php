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
 * Writes payloads out as a blueprint repository.
 *
 * The inverse of {@see RepositorySource}, and the fourth of the four
 * directions: a model that arrived as a LionWeb chunk becomes a tree of JSON
 * files on disk - one somebody can read, commit to git and hand to JCB - rather
 * than only rows in a database somewhere.
 *
 * **It builds the files before it writes any of them.** A repository is a
 * directory, not a file, so there is no atomic way to replace one; producing
 * the whole set first means a payload that cannot be encoded is found before
 * anything has been written, rather than half way through a tree somebody now
 * has to reason about.
 *
 * **Where a record goes is declared, not derived.** The transport config says
 * the directory, the file name and the index for each entity, and they are not
 * all the obvious ones - `power` sits at the root of `src` and keeps its
 * payload in `settings.json`, and every owned record is filed under its parent.
 * Guessing is right for most of them and silently wrong for the rest.
 *
 * @since 1.0.0
 */
final class RepositoryWriter
{
	private Schema $schema;
	private array $diagnostics = [];

	public function __construct(Schema $schema)
	{
		$this->schema = $schema;
	}

	/**
	 * The whole repository, in memory: repository-relative path => contents.
	 *
	 * @param list<Payload> $payloads
	 *
	 * @return array<string, string>
	 */
	public function files(array $payloads): array
	{
		$this->diagnostics = [];

		$files   = [];
		$indexes = [];

		foreach ($payloads as $payload)
		{
			$entity = $payload->entity;

			if (!$this->schema->knows($entity))
			{
				$this->diag('warning', 'ENTITY_UNKNOWN',
					"This JCB has no {$entity}, so there is nowhere in a repository to put one.",
					['entity' => $entity]);

				continue;
			}

			$sharing = $this->sharingLayoutWith($entity);

			if ($sharing !== [])
			{
				// Not a shortcoming of this writer: JCB's own transport config
				// puts all of these at `src/<identity>/item.json` and names the
				// same index for them, so a repository cannot say which record
				// belongs to which entity. Writing them anyway would produce a
				// tree that reads back as the wrong thing, which is worse than
				// a tree that is honestly incomplete.
				$this->diag('warning', 'LAYOUT_NOT_DISTINCT',
					$entity . ' shares its repository layout with '
					. implode(', ', $sharing) . ', so a record of it could not be told '
					. 'from one of those when the repository is read. Not written.',
					['entity' => $entity]);

				continue;
			}

			$path    = $this->schema->payloadPath($entity, $payload->ownerGuid);
			$encoded = $this->encode($payload->data, $path);

			if ($encoded === null)
			{
				continue;
			}

			if (isset($files[$path]))
			{
				// Two payloads claiming one file is a blueprint that cannot be
				// written rather than one that writes oddly: the second would
				// replace the first and the repository would be missing a
				// record nothing said was missing.
				$this->diag('error', 'PATH_COLLISION',
					"Two {$entity} payloads both want {$path}; only the first is written.",
					['entity' => $entity]);

				continue;
			}

			$files[$path]             = $encoded;
			$indexes[$entity][$payload->ownerGuid] = $this->indexEntry($payload);
		}

		foreach ($indexes as $entity => $entries)
		{
			// Sorted, so a repository written twice from the same models is the
			// same repository - which is what makes it something to commit.
			ksort($entries);

			$path    = $this->schema->indexPath($entity);
			$encoded = $this->encode($entries, $path);

			if ($encoded !== null)
			{
				$files[$path] = $encoded;
			}
		}

		ksort($files);

		return $files;
	}

	/**
	 * Write a repository to disk, replacing what that path already holds.
	 *
	 * @param list<Payload> $payloads
	 *
	 * @return array{root:string,files:int,payloads:int,bytes:int}
	 *
	 * @throws \RuntimeException when the tree cannot be written
	 */
	public function writeTo(string $root, array $payloads): array
	{
		$files = $this->files($payloads);

		if ($files === [])
		{
			throw new \RuntimeException('There is nothing to write: no payload could be placed.');
		}

		$root  = rtrim(str_replace('\\', '/', $root), '/');
		$bytes = 0;

		foreach ($files as $path => $contents)
		{
			$target    = $root . '/' . $path;
			$directory = \dirname($target);

			if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory))
			{
				throw new \RuntimeException("Could not create {$directory}. Check permissions.");
			}

			if (file_put_contents($target, $contents) === false)
			{
				throw new \RuntimeException("Could not write {$target}. Check permissions.");
			}

			$bytes += \strlen($contents);
		}

		return [
			'root'     => $root,
			'files'    => \count($files),
			'payloads' => \count($payloads),
			'bytes'    => $bytes,
		];
	}

	/**
	 * Other portable entities that would be written to the same places.
	 *
	 * Two entities collide when they agree on both the directory and the
	 * payload's name; the index is no help either, because the ones that do
	 * this also name the same index file.
	 *
	 * @return list<string>
	 */
	private function sharingLayoutWith(string $entity): array
	{
		static $cache = [];

		if (isset($cache[$entity]))
		{
			return $cache[$entity];
		}

		$mine   = $this->schema->srcPath($entity) . '|' . $this->schema->settingsName($entity);
		$others = [];

		foreach ($this->schema->portableEntities() as $other)
		{
			if ($other === $entity)
			{
				continue;
			}

			if ($this->schema->srcPath($other) . '|' . $this->schema->settingsName($other) === $mine)
			{
				$others[] = $other;
			}
		}

		return $cache[$entity] = $others;
	}

	/**
	 * What an index says about one record.
	 *
	 * An index locates payloads and carries no design, which is why
	 * `RepositorySource` does not read one. It is written because JCB's own
	 * tooling looks there first, and a repository without one is a repository
	 * JCB cannot see into.
	 */
	private function indexEntry(Payload $payload): array
	{
		$entity = $payload->entity;
		$title  = $this->schema->titleName($entity);

		$entry = [
			// An owned record has no title of its own; JCB's own indexes carry
			// the owner's guid in that field rather than leaving it out.
			'name'     => $title !== null && is_scalar($payload->data[$title] ?? null)
				? (string) $payload->data[$title]
				: $payload->ownerGuid,
			'path'     => $this->schema->recordPath($entity, $payload->ownerGuid),
			'settings' => $this->schema->payloadPath($entity, $payload->ownerGuid),
			'guid'     => $payload->ownerGuid,
		];

		if (is_scalar($payload->data['short_description'] ?? null)
			&& (string) $payload->data['short_description'] !== '')
		{
			$entry['desc'] = (string) $payload->data['short_description'];
		}

		return $entry;
	}

	/**
	 * JSON as JCB writes it: pretty-printed, with slashes escaped.
	 *
	 * Matching the style is free and means a repository written here and one
	 * pulled by JCB can be diffed without the whole file changing.
	 */
	private function encode(array $data, string $path): ?string
	{
		$json = json_encode($data, JSON_PRETTY_PRINT);

		if ($json === false)
		{
			$this->diag('error', 'ENCODE_FAILED',
				"Could not encode {$path}: " . json_last_error_msg());

			return null;
		}

		return $json . "\n";
	}

	public function diagnostics(): array
	{
		return array_values($this->diagnostics);
	}

	private function diag(string $severity, string $code, string $message, array $context = []): void
	{
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
