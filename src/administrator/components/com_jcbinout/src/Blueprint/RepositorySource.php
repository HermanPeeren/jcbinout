<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Blueprint;

\defined('_JEXEC') or die;

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

	public function __construct(string $root)
	{
		$this->root = rtrim(str_replace('\\', '/', $root), '/');
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

		$found = [];

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
				$found[] = $payload;
			}
		}

		// A repository walk is filesystem-ordered, which differs between
		// machines. Export has to be reproducible, so impose one.
		usort($found, static fn(Payload $a, Payload $b) => [$a->entity, $a->ownerGuid, $a->relativePath]
			<=> [$b->entity, $b->ownerGuid, $b->relativePath]);

		return $found;
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
