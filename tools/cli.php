<?php
/**
 * JcbInOut - development CLI.
 *
 * Runs the *component's own* classes outside Joomla, against a vendored copy of
 * JCB's sources, so the pipeline can be exercised and regression-tested without
 * a Joomla installation. There is deliberately no second implementation: this
 * is a thin harness around com_jcbinout/administrator/src.
 *
 * Commands:
 *   php tools/cli.php fetch [commit]   vendor JCB's sources for offline work
 *   php tools/cli.php derive           derive the metamodel
 *   php tools/cli.php build            build the LionWeb language
 *   php tools/cli.php validate         validate the language
 *   php tools/cli.php report           render METAMODEL.md
 *   php tools/cli.php all              derive, build, validate, report
 *
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

// The component classes guard on _JEXEC; this harness is a legitimate caller.
\define('_JEXEC', 1);

$root   = dirname(__DIR__);
$srcDir = $root . '/com_jcbinout/administrator/src';
$vendor = $root . '/vendor-jcb';
$data   = $root . '/data';

// --- autoloading -------------------------------------------------------------

spl_autoload_register(static function (string $class) use ($srcDir, $vendor): void {
	$componentPrefix = 'Yepr\\Component\\Jcbinout\\Administrator\\';

	if (str_starts_with($class, $componentPrefix)) {
		$rel  = str_replace('\\', '/', substr($class, strlen($componentPrefix)));
		$file = $srcDir . '/' . $rel . '.php';

		if (is_file($file)) {
			require_once $file;
		}

		return;
	}

	// JCB's own classes, from the vendored copy.
	if (str_starts_with($class, 'VDM\\Joomla\\')) {
		$rel  = str_replace('\\', '/', substr($class, strlen('VDM\\Joomla\\')));
		$file = $vendor . '/' . $rel . '.php';

		if (is_file($file)) {
			require_once $file;
			return;
		}

		// The vendoring script flattens a few paths.
		$flat = [
			'Componentbuilder/Table'   => $vendor . '/Table.php',
			'Componentbuilder/Factory' => $vendor . '/Factory.php',
			'Abstraction/Remote/Config' => $vendor . '/AbstractConfig.php',
			'Interfaces/TableInterface' => $vendor . '/deps/Interfaces_TableInterface.php',
			'Interfaces/Remote/ConfigInterface' => $vendor . '/deps/Interfaces_Remote_ConfigInterface.php',
			'Componentbuilder/Power/Interfaces/TableInterface'
				=> $vendor . '/deps/Componentbuilder_Power_Interfaces_TableInterface.php',
			'Abstraction/BaseTable' => $vendor . '/deps/Abstraction_BaseTable.php',
		];

		if (isset($flat[$rel]) && is_file($flat[$rel])) {
			require_once $flat[$rel];
			return;
		}

		// Per-entity remote configs live under two different namespaces.
		if (preg_match('#^Componentbuilder/(?:Package/)?([A-Za-z]+)/Remote/Config$#', $rel, $m)) {
			$file = $vendor . '/configs/' . $m[1] . '.php';

			if (is_file($file)) {
				require_once $file;
			}
		}
	}
});

// --- helpers -----------------------------------------------------------------

function out(string $msg): void
{
	fwrite(STDERR, $msg . "\n");
}

function readJson(string $path): ?array
{
	if (!is_file($path)) {
		return null;
	}

	$d = json_decode((string) file_get_contents($path), true);

	return is_array($d) ? $d : null;
}

function writeJson(string $path, array $data): void
{
	file_put_contents($path, json_encode($data,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/**
 * Vendor the JCB sources the extractor reflects over. Pin the commit so a
 * regenerated metamodel is reproducible.
 */
function cmdFetch(string $vendor, string $pin): int
{
	$repo = 'joomengine/Joomla-Component-Builder';
	$raw  = "https://raw.githubusercontent.com/{$repo}/{$pin}/libraries/vendor_jcb/VDM.Joomla/src";

	@mkdir($vendor . '/configs', 0755, true);
	@mkdir($vendor . '/deps', 0755, true);
	@mkdir($vendor . '/forms', 0755, true);

	$get = static function (string $url, string $to): bool {
		$body = @file_get_contents($url);

		if ($body === false || $body === '') {
			out("  FAIL  {$url}");

			return false;
		}

		file_put_contents($to, $body);

		return true;
	};

	out("Vendoring JCB sources @ {$pin}");

	$core = [
		'Componentbuilder/Table.php'    => $vendor . '/Table.php',
		'Componentbuilder/Factory.php'  => $vendor . '/Factory.php',
		'Abstraction/Remote/Config.php' => $vendor . '/AbstractConfig.php',
		'Abstraction/BaseTable.php'     => $vendor . '/deps/Abstraction_BaseTable.php',
		'Interfaces/TableInterface.php' => $vendor . '/deps/Interfaces_TableInterface.php',
		'Interfaces/Remote/ConfigInterface.php'
			=> $vendor . '/deps/Interfaces_Remote_ConfigInterface.php',
		'Componentbuilder/Power/Interfaces/TableInterface.php'
			=> $vendor . '/deps/Componentbuilder_Power_Interfaces_TableInterface.php',
	];

	foreach ($core as $rel => $to) {
		$get("{$raw}/{$rel}", $to);
	}

	$tree = @file_get_contents(
		"https://api.github.com/repos/{$repo}/git/trees/{$pin}?recursive=1",
		false,
		stream_context_create(['http' => ['header' => "User-Agent: jcbinout\r\n"]])
	);

	$n = 0;

	// Parse the tree rather than pattern-matching the response: GitHub returns
	// compact JSON, so whitespace in a regex is not something to rely on.
	$decoded = $tree === false ? null : json_decode($tree, true);

	foreach ($decoded['tree'] ?? [] as $entry) {
		if (!preg_match(
			'#^libraries/vendor_jcb/VDM\.Joomla/src/Componentbuilder/Package/([A-Za-z]+)/Remote/Config\.php$#',
			(string) ($entry['path'] ?? ''), $m)) {
			continue;
		}

		if ($get("{$raw}/Componentbuilder/Package/{$m[1]}/Remote/Config.php",
			$vendor . '/configs/' . $m[1] . '.php')) {
			$n++;
		}
	}

	if ($n === 0) {
		out('  WARNING: no Package configs found via the tree API; '
			. 'only the top-level entity configs were vendored.');
	}

	foreach (['Power', 'JoomlaPower', 'Fieldtype', 'Snippet', 'Repository'] as $area) {
		if ($get("{$raw}/Componentbuilder/{$area}/Remote/Config.php",
			$vendor . '/configs/' . $area . '.php')) {
			$n++;
		}
	}

	// Enumeration members live in JCB's admin form XML, labels in its language file.
	$forms = "https://raw.githubusercontent.com/{$repo}/{$pin}/admin";

	foreach (['power', 'admin_view', 'class_extends', 'class_property', 'class_method',
		'field', 'fieldtype'] as $entity) {
		$get("{$forms}/forms/{$entity}.xml", $vendor . '/forms/' . $entity . '.xml');
	}

	$get("{$forms}/language/en-GB/en-GB.com_componentbuilder.ini",
		$vendor . '/en-GB.com_componentbuilder.ini');

	file_put_contents($vendor . '/PINNED_COMMIT', $pin . "\n");
	out("Done: {$n} entity configs vendored.");

	return 0;
}

function cmdDerive(string $vendor, string $data): int
{
	$harvester = new \Yepr\Component\Jcbinout\Administrator\Metamodel\EnumHarvester(
		$vendor . '/forms',
		$vendor . '/en-GB.com_componentbuilder.ini'
	);

	$extractor = new \Yepr\Component\Jcbinout\Administrator\Metamodel\Extractor($harvester);

	$pin = is_file($vendor . '/PINNED_COMMIT')
		? trim((string) file_get_contents($vendor . '/PINNED_COMMIT'))
		: null;

	$doc = $extractor->document(array_filter([
		'jcbCommit'         => $pin,
		'jcbSourcePath'     => $vendor,
		'schemaFingerprint' => is_file($vendor . '/Table.php')
			? hash_file('sha256', $vendor . '/Table.php') : null,
	]));

	writeJson($data . '/jcb-metamodel.json', $doc);

	$s = $doc['stats'];
	out(sprintf(
		"Derived metamodel\n  entities: %d (%d portable)\n  properties: %d\n"
		. "  kinds: %s\n  enumerations: %d (%d literals)\n  diagnostics: %s",
		$s['entitiesTotal'], $s['entitiesPortable'], $s['propertiesTotal'],
		json_encode($s['byKind']), $s['enumerations'], $s['enumerationLiterals'],
		json_encode($s['diagnosticsBySeverity'])
	));

	return 0;
}

function cmdBuild(string $data): int
{
	$meta = readJson($data . '/jcb-metamodel.json');

	if ($meta === null) {
		out('No metamodel found. Run: php tools/cli.php derive');

		return 1;
	}

	$names = readJson($data . '/interface-names.json')['interfaces'] ?? [];
	$builder = new \Yepr\Component\Jcbinout\Administrator\Lionweb\LanguageBuilder($meta, $names);

	$version = substr((string) ($meta['meta']['jcbCommit'] ?? 'dev'), 0, 10) . '-1';
	$chunk   = $builder->build($version);

	writeJson($data . '/jcb-language.lionweb.json', $chunk);
	writeJson($data . '/jcb-feature-keys.json', [
		'featureKeys' => $builder->featureKeys(),
		'sharedGuids' => $builder->sharedGuids(),
		'hoistedInto' => $builder->hoistedInto(),
	]);

	$valueMap = [];

	foreach ($meta['enumerations'] ?? [] as $name => $enum) {
		foreach ($enum['literals'] as $lit) {
			$valueMap[$name][$lit['name']] = $lit['value'];
		}
	}

	writeJson($data . '/jcb-enum-values.json', $valueMap);

	$census = \Yepr\Component\Jcbinout\Administrator\Lionweb\Validator::census($chunk);
	$diags  = [];

	foreach ($builder->diagnostics() as $d) {
		$diags[$d['severity']] = ($diags[$d['severity']] ?? 0) + 1;
	}

	out(sprintf("Built LionWeb language\n  version: %s\n  nodes: %d %s\n  diagnostics: %s",
		$version, count($chunk['nodes']), json_encode($census), json_encode($diags ?: ['none' => 0])));

	foreach ($builder->diagnostics() as $d) {
		if ($d['severity'] === 'error') {
			out("  ERROR [{$d['code']}] {$d['message']}");
		}
	}

	return 0;
}

function cmdValidate(string $data): int
{
	$chunk = readJson($data . '/jcb-language.lionweb.json');

	if ($chunk === null) {
		out('No language found. Run: php tools/cli.php build');

		return 1;
	}

	$v  = new \Yepr\Component\Jcbinout\Administrator\Lionweb\Validator();
	$ok = $v->validate($chunk);

	foreach ($v->checks() as $c) {
		out('  [ok] ' . $c);
	}

	foreach ($v->errors() as $e) {
		out('  [FAIL] ' . $e);
	}

	out('');
	out('  census: ' . json_encode(
		\Yepr\Component\Jcbinout\Administrator\Lionweb\Validator::census($chunk)));
	out('');
	out($ok ? 'PASS - valid LionWeb language serialisation.' : 'FAIL - not valid.');

	return $ok ? 0 : 1;
}

// --- dispatch ----------------------------------------------------------------

$cmd = $argv[1] ?? 'all';
@mkdir($data, 0755, true);

switch ($cmd) {
	case 'fetch':
		exit(cmdFetch($vendor, $argv[2] ?? 'bca4a1520484f3e2c2fbd12964a5995b0d058de1'));

	case 'derive':
		exit(cmdDerive($vendor, $data));

	case 'build':
		exit(cmdBuild($data));

	case 'validate':
		exit(cmdValidate($data));

	case 'report':
		require __DIR__ . '/report-metamodel.php';
		exit(0);

	case 'all':
		$rc = cmdDerive($vendor, $data);
		$rc = $rc ?: cmdBuild($data);
		$rc = $rc ?: cmdValidate($data);
		exit($rc);

	default:
		out("Unknown command '{$cmd}'. Try: fetch, derive, build, validate, report, all");
		exit(2);
}
