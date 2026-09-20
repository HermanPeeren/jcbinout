<?php
/**
 * PHPUnit bootstrap.
 *
 * The component's classes guard on _JEXEC, which Joomla defines when an
 * application boots. The unit suite deliberately has no Joomla in it - the
 * classes under test are pure - so the constant is defined here.
 *
 * @package JcbInOut
 */

\defined('_JEXEC') or \define('_JEXEC', 1);

require_once __DIR__ . '/../vendor/autoload.php';
