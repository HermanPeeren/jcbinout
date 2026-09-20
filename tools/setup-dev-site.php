<?php
/**
 * Create the development Joomla site this repository tests against.
 *
 * Downloads the latest stable Joomla into /joomla, creates the database,
 * installs Joomla, then installs JCB. /joomla is git-ignored: it is a working
 * site, not source. CI builds its own.
 *
 * Everything here is PHP on purpose - a Joomla host cannot be assumed to have
 * bash, Python or a MySQL client binary, and neither can a fresh clone.
 *
 * Usage:
 *   php tools/setup-dev-site.php [--db-suffix=jcbinout] [--joomla=6.1.3]
 *                                [--jcb=6.1.6] [--force]
 *
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

$defaults = [
    'db-suffix'  => 'jcbinout',
    'joomla'     => '6.1.3',
    'jcb'        => '6.1.6',
    'db-host'    => '127.0.0.1',
    'db-user'    => 'root',
    'db-pass'    => '',
    'db-prefix'  => 'jcbio_',
    'admin-user' => 'admin',
    'admin-pass' => 'adminadminadmin',
    'admin-mail' => 'herman@yepr.nl',
    'site-name'  => 'JcbInOut dev',
];

$options = $defaults;
$force   = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force') {
        $force = true;
        continue;
    }

    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $defaults)) {
        $options[$m[1]] = $m[2];
        continue;
    }

    fwrite(STDERR, "Unknown option: {$arg}\n");
    exit(2);
}

$root    = dirname(__DIR__);
$joomla  = $root . '/joomla';
$tmp     = $root . '/.tmp';
$dbName  = 'jlatest-' . $options['db-suffix'];

function step(string $msg): void
{
    fwrite(STDERR, "==> {$msg}\n");
}

function fail(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

// --- preconditions -----------------------------------------------------------

foreach (['zip', 'mysqli', 'curl'] as $ext) {
    if (!extension_loaded($ext)) {
        fail("PHP extension '{$ext}' is required.");
    }
}

if (is_dir($joomla) && !$force) {
    fail("{$joomla} already exists. Pass --force to replace it.");
}

@mkdir($tmp, 0755, true);

// --- database ----------------------------------------------------------------

step("Creating database `{$dbName}`");

$mysqli = @new mysqli($options['db-host'], $options['db-user'], $options['db-pass']);

if ($mysqli->connect_errno) {
    fail("Cannot connect to MySQL: {$mysqli->connect_error}");
}

$quoted = '`' . str_replace('`', '``', $dbName) . '`';

if ($force) {
    $mysqli->query("DROP DATABASE IF EXISTS {$quoted}");
}

if (!$mysqli->query("CREATE DATABASE IF NOT EXISTS {$quoted} "
    . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')) {
    fail("Could not create the database: {$mysqli->error}");
}

$mysqli->close();

// --- download ----------------------------------------------------------------

$version = $options['joomla'];
$archive = $tmp . "/joomla-{$version}.tar.gz";

if (!is_file($archive)) {
    step("Downloading Joomla {$version}");

    $url = "https://github.com/joomla/joomla-cms/releases/download/{$version}"
        . "/Joomla_{$version}-Stable-Full_Package.tar.gz";

    $body = @file_get_contents($url);

    if ($body === false || strlen($body) < 1_000_000) {
        fail("Could not download {$url}");
    }

    file_put_contents($archive, $body);
}

step('Unpacking');

if (is_dir($joomla)) {
    // A Joomla tree is thousands of files; PHP's own recursive delete is slow
    // but dependable, and this only happens with --force.
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($joomla, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }

    @rmdir($joomla);
}

@mkdir($joomla, 0755, true);

$phar = new PharData($archive);
$phar->extractTo($joomla, null, true);

if (!is_file($joomla . '/installation/joomla.php')) {
    fail('The unpacked Joomla has no CLI installer; the download may be corrupt.');
}

// --- install Joomla ----------------------------------------------------------

step('Installing Joomla');

$install = [
    PHP_BINARY,
    escapeshellarg($joomla . '/installation/joomla.php'),
    'install',
    '--site-name=' . escapeshellarg($options['site-name']),
    '--admin-user=' . escapeshellarg('Admin'),
    '--admin-username=' . escapeshellarg($options['admin-user']),
    '--admin-password=' . escapeshellarg($options['admin-pass']),
    '--admin-email=' . escapeshellarg($options['admin-mail']),
    '--db-type=mysqli',
    '--db-host=' . escapeshellarg($options['db-host']),
    '--db-user=' . escapeshellarg($options['db-user']),
    '--db-pass=' . escapeshellarg($options['db-pass']),
    '--db-name=' . escapeshellarg($dbName),
    '--db-prefix=' . escapeshellarg($options['db-prefix']),
    '-n',
];

passthru(implode(' ', $install), $code);

if ($code !== 0) {
    fail('Joomla installation failed.');
}

// --- install JCB -------------------------------------------------------------

$jcbVersion = $options['jcb'];
$jcbZip     = $tmp . "/pkg-component-builder-{$jcbVersion}.zip";

if (!is_file($jcbZip)) {
    step("Downloading JCB {$jcbVersion}");

    $url = "https://github.com/joomengine/pkg-component-builder/archive/refs/tags/v{$jcbVersion}.zip";
    $body = @file_get_contents($url);

    if ($body === false || strlen($body) < 100_000) {
        fail("Could not download {$url}");
    }

    file_put_contents($jcbZip, $body);
}

$jcbDir = $tmp . '/jcb';

if (!is_dir($jcbDir)) {
    $zip = new ZipArchive();
    $zip->open($jcbZip);
    $zip->extractTo($jcbDir);
    $zip->close();
}

$component = $jcbDir . "/pkg-component-builder-{$jcbVersion}/src/joomla__Component-Builder__6.x.zip";

if (!is_file($component)) {
    fail("The JCB package has no component at {$component}");
}

// The component is what JcbInOut reads; the package's plugins are not needed to
// derive a metamodel, and installing the component alone is markedly faster.
step('Installing Joomla Component Builder (this takes a minute)');

passthru(sprintf(
    '%s %s extension:install --path=%s',
    PHP_BINARY,
    escapeshellarg($joomla . '/cli/joomla.php'),
    escapeshellarg($component)
), $code);

if ($code !== 0) {
    fail('JCB installation failed.');
}

// --- report ------------------------------------------------------------------

step('Done');

$name = basename($root);

echo <<<TEXT

  Joomla    {$version}   {$joomla}
  Admin     {$options['admin-user']} / {$options['admin-pass']}
  Database  {$dbName}  (user {$options['db-user']}, prefix {$options['db-prefix']})
  JCB       {$jcbVersion}
  URL       http://localhost/{$name}/joomla

  Next:
    php tools/build.php
    php {$joomla}/cli/joomla.php extension:install --path=dist/com_jcbinout-*.zip

TEXT;
