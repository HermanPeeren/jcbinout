<?php
/**
 * Constants Joomla defines when an application boots.
 *
 * They exist at runtime and in no file an analyser reads, so without this every
 * `\defined('_JEXEC') or die;` line is an error and every JPATH_* is unknown.
 *
 * @package JcbInOut
 */

\define('_JEXEC', 1);

$root = \dirname(__DIR__) . '/joomla';

\define('JPATH_BASE', $root);
\define('JPATH_ROOT', $root);
\define('JPATH_SITE', $root);
\define('JPATH_ADMINISTRATOR', $root . '/administrator');
\define('JPATH_LIBRARIES', $root . '/libraries');
\define('JPATH_PLUGINS', $root . '/plugins');
\define('JPATH_CONFIGURATION', $root);
\define('JPATH_INSTALLATION', $root . '/installation');
\define('JPATH_THEMES', $root . '/templates');
\define('JPATH_CACHE', $root . '/cache');
\define('JPATH_MANIFESTS', $root . '/administrator/manifests');
\define('JPATH_API', $root . '/api');
\define('JPATH_CLI', $root . '/cli');
