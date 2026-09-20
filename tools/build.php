<?php
/**
 * JcbInOut - package the installable Joomla component.
 *
 * Produces dist/com_jcbinout-<version>.zip from com_jcbinout/, and copies the
 * reference artefacts into the package so a fresh install has something to
 * compare a freshly derived metamodel against.
 *
 * Usage: php tools/build.php
 *
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

$root      = dirname(__DIR__);
$component = $root . '/com_jcbinout';
$dist      = $root . '/dist';
$data      = $root . '/data';

if (!is_dir($component)) {
    fwrite(STDERR, "No component directory at {$component}\n");
    exit(1);
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "The zip extension is required. Enable ext-zip in php.ini.\n");
    exit(1);
}

// --- version from the manifest ----------------------------------------------

$manifest = @simplexml_load_file($component . '/jcbinout.xml');

if ($manifest === false) {
    fwrite(STDERR, "Could not read the manifest.\n");
    exit(1);
}

$version = (string) $manifest->version;

// --- ship the reference artefacts -------------------------------------------

@mkdir($component . '/administrator/data', 0755, true);

$reference = ['interface-names.json', 'jcb-metamodel.json',
    'jcb-language.lionweb.json', 'jcb-enum-values.json', 'jcb-feature-keys.json'];

foreach ($reference as $file) {
    if (is_file($data . '/' . $file)) {
        copy($data . '/' . $file, $component . '/administrator/data/' . $file);
    }
}

// --- build -------------------------------------------------------------------

@mkdir($dist, 0755, true);
$zipPath = $dist . "/com_jcbinout-{$version}.zip";
@unlink($zipPath);

$zip = new ZipArchive();

if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Could not create {$zipPath}\n");
    exit(1);
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($component, FilesystemIterator::SKIP_DOTS)
);

$count = 0;
$bytes = 0;

foreach ($files as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile()) {
        continue;
    }

    $local = str_replace('\\', '/', substr($file->getPathname(), strlen($component) + 1));

    // Never ship derived working data - the component regenerates it from the
    // JCB that is actually installed, and shipping it would overwrite a site's
    // own derivation on every update.
    if (str_starts_with($local, 'administrator/data/derived/')) {
        continue;
    }

    if (str_starts_with($local, 'administrator/data/') && !in_array(basename($local), $reference, true)) {
        continue;
    }

    $zip->addFile($file->getPathname(), $local);
    $count++;
    $bytes += $file->getSize();
}

$zip->close();

printf("Wrote %s\n  version: %s\n  files: %d\n  uncompressed: %.1f KB\n  package: %.1f KB\n",
    $zipPath, $version, $count, $bytes / 1024, filesize($zipPath) / 1024);
