<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Lionweb;

\defined('_JEXEC') or die;

/**
 * Structural validation of a LionWeb serialisation chunk.
 *
 * This implements the constraints the official serialization schema expresses,
 * plus the integrity rules a chunk must satisfy to be loadable: unique ids,
 * resolvable children, parent agreement (including annotations, whose parent is
 * the annotated node rather than a container), and a single root.
 *
 * It is deliberately self-contained - no JSON-schema library, no external
 * process - so it can run inside a Joomla request.
 *
 * @since 1.0.0
 */
final class Validator
{
	private const ID_PATTERN = '#^[a-zA-Z0-9_-]+$#';

	private const NODE_MEMBERS = ['id', 'classifier', 'properties', 'containments',
		'references', 'annotations', 'parent'];

	private array $errors = [];
	private array $checks = [];

	public function validate(array $chunk): bool
	{
		$this->errors = [];
		$this->checks = [];

		$this->checkEnvelope($chunk);

		$nodes = $chunk['nodes'] ?? [];

		if (!is_array($nodes) || $nodes === [])
		{
			$this->fail('chunk has no nodes');

			return false;
		}

		$byId = $this->checkIds($nodes);
		$this->checkShape($nodes);
		$this->checkParentage($nodes, $byId);
		$this->checkReferences($nodes, $byId);

		return $this->errors === [];
	}

	// -- individual checks ---------------------------------------------------

	private function checkEnvelope(array $chunk): void
	{
		foreach (['serializationFormatVersion', 'languages', 'nodes'] as $member)
		{
			if (!array_key_exists($member, $chunk))
			{
				$this->fail("missing required member '{$member}'");
			}
		}

		foreach ($chunk['languages'] ?? [] as $i => $lang)
		{
			if (!isset($lang['key'], $lang['version']) || count($lang) !== 2)
			{
				$this->fail("languages[{$i}] must have exactly 'key' and 'version'");
			}
		}

		$this->pass('envelope has the required members');
	}

	private function checkIds(array $nodes): array
	{
		$byId = [];
		$dupes = [];

		foreach ($nodes as $n)
		{
			$id = $n['id'] ?? null;

			if (!is_string($id) || $id === '' || !preg_match(self::ID_PATTERN, $id))
			{
				$this->fail('invalid node id: ' . var_export($id, true));
				continue;
			}

			if (isset($byId[$id]))
			{
				$dupes[$id] = true;
			}

			$byId[$id] = $n;
		}

		if ($dupes !== [])
		{
			$this->fail('duplicate node ids: ' . implode(', ', array_slice(array_keys($dupes), 0, 5)));
		}
		else
		{
			$this->pass(count($byId) . ' node ids unique and well-formed');
		}

		return $byId;
	}

	private function checkShape(array $nodes): void
	{
		$bad = 0;

		foreach ($nodes as $n)
		{
			foreach (self::NODE_MEMBERS as $m)
			{
				if (!array_key_exists($m, $n))
				{
					$bad++;

					if ($bad <= 5)
					{
						$this->fail("node {$n['id']} is missing required member '{$m}'");
					}
				}
			}

			foreach ($this->metaPointers($n) as [$role, $mp])
			{
				if (!isset($mp['language'], $mp['version'], $mp['key']) || count($mp) !== 3)
				{
					$this->fail("node {$n['id']}: {$role} metapointer must have language, version and key");
				}
			}
		}

		if ($bad === 0)
		{
			$this->pass('every node carries all seven required members');
		}
	}

	/**
	 * A child's parent must be its container. An annotation instance's parent is
	 * the annotated node, and it is listed in that node's "annotations".
	 */
	private function checkParentage(array $nodes, array $byId): void
	{
		$childOf = [];

		foreach ($nodes as $n)
		{
			foreach ($n['annotations'] ?? [] as $ann)
			{
				if (!isset($byId[$ann]))
				{
					$this->fail("node {$n['id']} is annotated by unknown node {$ann}");
					continue;
				}

				$childOf[$ann] = $n['id'];
			}

			foreach ($n['containments'] ?? [] as $c)
			{
				foreach ($c['children'] ?? [] as $ch)
				{
					if (!isset($byId[$ch]))
					{
						$this->fail("node {$n['id']} contains unknown child {$ch}");
						continue;
					}

					if (isset($childOf[$ch]))
					{
						$this->fail("node {$ch} is contained twice ({$childOf[$ch]}, {$n['id']})");
					}

					$childOf[$ch] = $n['id'];
				}
			}
		}

		$bad = 0;

		foreach ($nodes as $n)
		{
			$expected = $childOf[$n['id']] ?? null;

			if (($n['parent'] ?? null) !== $expected)
			{
				$bad++;

				if ($bad <= 5)
				{
					$this->fail("node {$n['id']}: parent=" . var_export($n['parent'] ?? null, true)
						. ' but contained by ' . var_export($expected, true));
				}
			}
		}

		if ($bad === 0)
		{
			$this->pass('parent and containment agree across ' . count($nodes) . ' nodes');
		}

		$roots = [];

		foreach ($nodes as $n)
		{
			if (($n['parent'] ?? null) === null)
			{
				$roots[] = $n['id'];
			}
		}

		if (count($roots) !== 1)
		{
			$this->fail('expected exactly one root node, found ' . count($roots)
				. ': ' . implode(', ', array_slice($roots, 0, 5)));
		}
		else
		{
			$this->pass('single root: ' . $roots[0]);
		}
	}

	/**
	 * Reference targets resolve in-chunk, or point outside it (to LionCore or
	 * builtins), or are null with a resolveInfo hint.
	 */
	private function checkReferences(array $nodes, array $byId, array $externalIds = []): void
	{
		$unresolved = [];

		foreach ($nodes as $n)
		{
			foreach ($n['references'] ?? [] as $r)
			{
				foreach ($r['targets'] ?? [] as $t)
				{
					$ref = $t['reference'] ?? null;

					if ($ref === null)
					{
						if (($t['resolveInfo'] ?? null) === null)
						{
							$this->fail("node {$n['id']}: reference target has neither id nor resolveInfo");
						}

						continue;
					}

					if (!isset($byId[$ref]) && !str_starts_with($ref, 'LionCore-') && !str_starts_with($ref, '-id-'))
					{
						$unresolved[$ref] = ($unresolved[$ref] ?? 0) + 1;
					}
				}
			}
		}

		if ($unresolved !== [])
		{
			foreach (array_slice($unresolved, 0, 5, true) as $ref => $count)
			{
				$this->fail("reference target not resolvable: {$ref} (x{$count})");
			}
		}
		else
		{
			$this->pass('every reference target resolves in-chunk or to LionCore');
		}
	}

	// -- helpers -------------------------------------------------------------

	private function metaPointers(array $n): array
	{
		$out = [['classifier', $n['classifier'] ?? []]];

		foreach ($n['properties'] ?? [] as $p)
		{
			$out[] = ['property', $p['property'] ?? []];
		}

		foreach ($n['containments'] ?? [] as $c)
		{
			$out[] = ['containment', $c['containment'] ?? []];
		}

		foreach ($n['references'] ?? [] as $r)
		{
			$out[] = ['reference', $r['reference'] ?? []];
		}

		return $out;
	}

	private function fail(string $msg): void
	{
		$this->errors[] = $msg;
	}

	private function pass(string $msg): void
	{
		$this->checks[] = $msg;
	}

	public function errors(): array
	{
		return $this->errors;
	}

	public function checks(): array
	{
		return $this->checks;
	}

	/**
	 * Node counts by classifier, for the status view.
	 */
	public static function census(array $chunk): array
	{
		$counts = [];

		foreach ($chunk['nodes'] ?? [] as $n)
		{
			$k = $n['classifier']['key'] ?? '?';
			$counts[$k] = ($counts[$k] ?? 0) + 1;
		}

		ksort($counts);

		return $counts;
	}
}
