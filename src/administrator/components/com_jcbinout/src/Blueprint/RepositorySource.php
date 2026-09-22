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
 * Reads a JCB blueprint repository from disk.
 *
 * A blueprint lays its payloads out as:
 *
 *   src/<entity>/<guid>/item.json                    a root definition
 *   src/<entity>/children/<guid>/<child-entity>.json a record owned by it
 *
 * Only payloads are read. Indexes locate payloads and generated READMEs
 * describe them; neither carries design the payloads do not already hold, and
 * treating them as input would count a README paragraph as a model decision.
 *
 * @since 1.0.0
 */
final class RepositorySource
{
	public const DEPENDENCIES = '@dependencies';

	private string $root;

	/**
	 * The metamodel, when the caller has one.
	 *
	 * Without it this reads the layout everything obvious uses -
	 * `src/<entity>/<guid>/item.json` and its children - and that is what it
	 * did for a long time, because the fixture holds nothing else. A real
	 * installation does: `power` keeps its payload in `settings.json` at the
	 * root of `src`, and four more entities sit at `src/<guid>/item.json`.
	 * Between them that is 365 of the 824 records on the development site, all
	 * of them invisible to a walk that only knows the convention.
	 *
	 * So when the schema is there, the declared paths are used as well.
	 */
	private ?Schema $schema;

	private array $diagnostics = [];

	public function __construct(string $root, ?Schema $schema = null)
	{
		$this->root   = rtrim(str_replace('\\', '/', $root), '/');
		$this->schema = $schema;
	}

	public function diagnostics(): array
	{
		return array_values($this->diagnostics);
	}

	public function exists(): bool
	{
		return is_dir($this->root . '/src');
	}

	public function root(): string
	{
		return $this->root;
	}

	/**
	 * Every payload in the repository.
	 *
	 * @return list<Payload>
	 */
	public function payloads(): array
	{
		if (!$this->exists())
		{
			throw new \RuntimeException("No src/ directory under {$this->root}.");
		}

		$this->diagnostics = [];

		$found = [];
		$seen  = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->root . '/src', \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file)
		{
			/** @var \SplFileInfo $file */
			if ($file->getExtension() !== 'json')
			{
				continue;
			}

			$relative = str_replace('\\', '/',
				substr($file->getPathname(), \strlen($this->root) + 1));

			$payload = $this->read($file->getPathname(), $relative);

			if ($payload !== null)
			{
				$found[]         = $payload;
				$seen[$relative] = true;
			}
		}

		foreach ($this->declared($seen) as $payload)
		{
			$found[] = $payload;
		}

		// A repository walk is filesystem-ordered, which differs between
		// machines. Export has to be reproducible, so impose one.
		usort($found, static fn(Payload $a, Payload $b) => [$a->entity, $a->ownerGuid, $a->relativePath]
			<=> [$b->entity, $b->ownerGuid, $b->relativePath]);

		return $found;
	}

	/**
	 * Payloads the convention does not find, located by what the metamodel says.
	 *
	 * Each entity declares where its records live and what the payload file is
	 * called, so `srcPath/<identity>/settingsName` is a pattern that can be
	 * looked for directly. Only files the walk did not already claim are
	 * considered, so nothing is read twice and the behaviour without a schema
	 * is exactly what it was.
	 *
	 * **Where two entities declare the same layout, neither is guessed at.**
	 * `joomla_power`, `fieldtype` and `repository` all keep their records at
	 * `src/<guid>/item.json` and all name the same index; the filesystem cannot
	 * say which is which, and inventing an answer would put records under the
	 * wrong entity - which is worse than saying so and reading neither.
	 *
	 * @param array<string, true> $seen Relative paths already read.
	 *
	 * @return list<Payload>
	 */
	private function declared(array $seen): array
	{
		if ($this->schema === null)
		{
			return [];
		}

		$claims = [];

		foreach ($this->schema->portableEntities() as $entity)
		{
			$pattern = $this->root . '/' . $this->schema->srcPath($entity)
				. '/*/' . $this->schema->settingsName($entity);

			foreach (glob($pattern) ?: [] as $match)
			{
				$relative = str_replace('\\', '/', substr($match, \strlen($this->root) + 1));

				if (isset($seen[$relative]))
				{
					continue;
				}

				$claims[$relative][] = $entity;
			}
		}

		$found = [];

		foreach ($claims as $relative => $entities)
		{
			if (\count($entities) > 1)
			{
				// Once per set of entities, not once per file: a site with two
				// hundred of these has one problem, not two hundred.
				$this->diag('warning', 'AMBIGUOUS_LAYOUT',
					implode(', ', $entities) . ' all keep their records at the same path, so a '
					. 'record found there cannot be attributed to one of them. Not read.');

				continue;
			}

			$entity = $entities[0];
			$data   = $this->decode($this->root . '/' . $relative);

			if ($data === null)
			{
				continue;
			}

			// The directory name is the identity, whatever the entity calls it:
			// a guid for most, a function name or a target for the few that are
			// addressed by a natural key.
			$found[] = new Payload(
				$entity,
				basename(\dirname($relative)),
				$relative,
				$data,
				$this->schema->parentOf($entity) !== null
			);
		}

		return $found;
	}

	private function diag(string $severity, string $code, string $message): void
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
			'count'    => 1,
		];
	}

	private function read(string $path, string $relative): ?Payload
	{
		// src/<entity>/children/<owner-guid>/<child-entity>.json
		if (preg_match('#^src/[a-z_]+/children/([^/]+)/([a-z-]+)\.json$#', $relative, $m))
		{
			$data = $this->decode($path);

			return $data === null ? null : new Payload(
				str_replace('-', '_', $m[2]), $m[1], $relative, $data, true
			);
		}

		// src/<entity>/<guid>/item.json
		if (preg_match('#^src/([a-z_]+)/([^/]+)/item\.json$#', $relative, $m))
		{
			$data = $this->decode($path);

			return $data === null ? null : new Payload(
				$m[1], $m[2], $relative, $data, false
			);
		}

		return null;
	}

	private function decode(string $path): ?array
	{
		$data = json_decode((string) file_get_contents($path), true);

		return is_array($data) ? $data : null;
	}
}
