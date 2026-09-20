<?php
/**
 * JcbInOut - Phase 1 validation gate
 *
 * Checks the extracted metamodel against a real JCB blueprint repository:
 * every key in every payload must be explained by the metamodel, and every
 * subform row must match its declared row shape.
 *
 * This is the test that the metamodel is *correct*, not merely well-formed.
 *
 * Usage: php validate-against-blueprint.php <metamodel.json> <blueprint-root>
 */

declare(strict_types=1);

$metaFile  = $argv[1] ?? (__DIR__ . '/../jcb-metamodel.json');
$blueprint = $argv[2] ?? (__DIR__ . '/../tests/fixtures/hello-world');

if (!is_file($metaFile))
{
	fwrite(STDERR, "Metamodel not found: {$metaFile}\n");
	exit(1);
}

if (!is_dir($blueprint . '/src'))
{
	fwrite(STDERR, "Blueprint src/ not found under: {$blueprint}\n");
	exit(1);
}

$meta     = json_decode((string) file_get_contents($metaFile), true);
$entities = $meta['entities'] ?? [];

/** Columns JCB adds to every table; present in payloads but not in $tables. */
const IMPLICIT = ['id', 'guid', 'published', 'created', 'modified', 'created_by',
	'modified_by', 'version', 'hits', 'ordering', 'access', 'params', 'asset_id',
	'checked_out', 'checked_out_time', 'metakey', 'metadesc', 'metadata'];

const RESERVED = ['@dependencies'];

$findings = [];
$stats    = ['payloads' => 0, 'keys' => 0, 'unknownKeys' => 0, 'rows' => 0, 'unknownRowKeys' => 0];
$seenEnt  = [];

/** Map a payload path to its entity name. */
$entityFor = static function (string $path) use ($blueprint): ?string {
	$rel = str_replace('\\', '/', substr($path, strlen($blueprint) + 1));

	// src/<entity>/children/<guid>/<child-entity>.json
	if (preg_match('#^src/[a-z_]+/children/[^/]+/([a-z-]+)\.json$#', $rel, $m))
	{
		return str_replace('-', '_', $m[1]);
	}

	// src/<entity>/<guid>/item.json
	if (preg_match('#^src/([a-z_]+)/[^/]+/item\.json$#', $rel, $m))
	{
		return $m[1];
	}

	return null;
};

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($blueprint . '/src'));

foreach ($rii as $file)
{
	if ($file->getExtension() !== 'json')
	{
		continue;
	}

	$path   = $file->getPathname();
	$entity = $entityFor($path);
	$rel    = str_replace('\\', '/', substr($path, strlen($blueprint) + 1));

	if ($entity === null)
	{
		$findings[] = ['UNRECOGNISED_PATH', $rel, 'Path does not match a known payload layout.'];
		continue;
	}

	$seenEnt[$entity] = true;

	if (!isset($entities[$entity]))
	{
		$findings[] = ['ENTITY_UNKNOWN', $rel, "Payload for entity '{$entity}' which the metamodel does not define."];
		continue;
	}

	$payload = json_decode((string) file_get_contents($path), true);

	if (!is_array($payload))
	{
		$findings[] = ['PAYLOAD_UNREADABLE', $rel, 'Not a JSON object.'];
		continue;
	}

	$stats['payloads']++;
	$props = $entities[$entity]['properties'] ?? [];

	foreach ($payload as $key => $value)
	{
		$stats['keys']++;

		if (in_array($key, RESERVED, true) || in_array($key, IMPLICIT, true))
		{
			continue;
		}

		if (!isset($props[$key]))
		{
			$stats['unknownKeys']++;
			$findings[] = ['KEY_UNKNOWN', $rel, "Key '{$entity}.{$key}' is not in the metamodel."];
			continue;
		}

		// Subform payloads must match the declared row shape.
		$prop = $props[$key];

		if (($prop['kind'] ?? null) === 'containment' && is_array($value))
		{
			$shape = $prop['subform']['fields'] ?? [];

			if ($shape === [])
			{
				continue;   // already reported as SUBFORM_NO_SHAPE at extraction
			}

			foreach ($value as $rowKey => $row)
			{
				if (!is_array($row))
				{
					continue;
				}

				$stats['rows']++;

				foreach (array_keys($row) as $rowField)
				{
					if (!isset($shape[$rowField]))
					{
						$stats['unknownRowKeys']++;
						$findings[] = ['ROW_KEY_UNKNOWN', $rel,
							"Subform '{$entity}.{$key}' row [{$rowKey}] has field '{$rowField}' "
							. 'which the declared row shape does not contain.'];
					}
				}
			}
		}
	}
}

// ---------------------------------------------------------------------------

$byCode = [];

foreach ($findings as [$code, , ])
{
	$byCode[$code] = ($byCode[$code] ?? 0) + 1;
}

echo "Blueprint : {$blueprint}\n";
echo "Metamodel : {$metaFile}\n\n";
printf("payloads checked : %d\n", $stats['payloads']);
printf("entities seen    : %d (%s)\n", count($seenEnt), implode(', ', array_keys($seenEnt)));
printf("keys checked     : %d  (unknown: %d)\n", $stats['keys'], $stats['unknownKeys']);
printf("subform rows     : %d  (unknown fields: %d)\n\n", $stats['rows'], $stats['unknownRowKeys']);

if ($findings === [])
{
	echo "PASS - every key in every payload is explained by the metamodel.\n";
	exit(0);
}

echo "FINDINGS:\n";

foreach ($byCode as $code => $n)
{
	echo "  {$code}: {$n}\n";
}

echo "\nDetail (first 40):\n";

foreach (array_slice($findings, 0, 40) as [$code, $rel, $msg])
{
	echo "  [{$code}] {$rel}\n      {$msg}\n";
}

exit(count($findings) > 0 ? 2 : 0);
