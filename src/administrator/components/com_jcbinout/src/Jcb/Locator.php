<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Jcb;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Finds the Joomla Component Builder installation this site is running and
 * makes its classes loadable.
 *
 * JcbInOut reads JCB's own classes by reflection rather than shipping a copy
 * of its schema. That way the derived metamodel always describes the JCB that
 * is actually installed, instead of a version pinned at build time - which
 * matters because JCB publishes no schema-compatibility policy.
 *
 * @since 1.0.0
 */
final class Locator
{
	/**
	 * Classes the metamodel derivation needs. If these resolve, JCB is usable.
	 */
	public const REQUIRED = [
		'VDM\\Joomla\\Componentbuilder\\Table',
		'VDM\\Joomla\\Componentbuilder\\Factory',
	];

	private const NAMESPACE_PREFIX = 'VDM\\Joomla\\';

	private ?string $sourcePath = null;
	private array $messages = [];

	/**
	 * Make JCB's classes loadable. Safe to call repeatedly.
	 */
	public function register(): bool
	{
		if ($this->classesAvailable())
		{
			$this->messages[] = 'JCB classes were already autoloadable.';

			return true;
		}

		$path = $this->findSource();

		if ($path === null)
		{
			return false;
		}

		\JLoader::registerNamespace(self::NAMESPACE_PREFIX, $path, false, false);

		if (!$this->classesAvailable())
		{
			$this->messages[] = 'Registered ' . $path . ' but the required classes still do not resolve.';

			return false;
		}

		$this->messages[] = 'Registered JCB namespace from ' . $path;

		return true;
	}

	/**
	 * Whether JCB's classes resolve right now.
	 *
	 * Deliberately impure: registering the namespace changes the answer, so
	 * calling this before and after register() is the point, not a mistake.
	 *
	 * @phpstan-impure
	 */
	public function classesAvailable(): bool
	{
		foreach (self::REQUIRED as $class)
		{
			if (!class_exists($class))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Locate JCB's PSR-4 source root. Known layouts are tried in order; the
	 * first that contains the expected class file wins.
	 */
	public function findSource(): ?string
	{
		if ($this->sourcePath !== null)
		{
			return $this->sourcePath;
		}

		$candidates = [
			JPATH_LIBRARIES . '/vendor_jcb/VDM.Joomla/src',
			JPATH_ADMINISTRATOR . '/components/com_componentbuilder/vendor_jcb/VDM.Joomla/src',
			JPATH_LIBRARIES . '/jcb_powers/VDM.Joomla/src',
		];

		foreach ($candidates as $candidate)
		{
			if (is_file($candidate . '/Componentbuilder/Table.php'))
			{
				$this->sourcePath = $candidate;

				return $candidate;
			}
		}

		$this->messages[] = 'Could not find JCB sources. Looked in: ' . implode(', ', $candidates);

		return null;
	}

	/**
	 * Is com_componentbuilder installed and enabled?
	 */
	public function componentInstalled(): bool
	{
		$db    = Factory::getContainer()->get('DatabaseDriver');
		$query = $db->getQuery(true)
			->select($db->quoteName('enabled'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('element') . ' = ' . $db->quote('com_componentbuilder'))
			->where($db->quoteName('type') . ' = ' . $db->quote('component'));

		try
		{
			$db->setQuery($query);

			return (int) $db->loadResult() === 1;
		}
		catch (\Throwable $e)
		{
			$this->messages[] = 'Could not query the extensions table: ' . $e->getMessage();

			return false;
		}
	}

	/**
	 * The installed JCB version, when the manifest cache reports one.
	 */
	public function version(): ?string
	{
		$db    = Factory::getContainer()->get('DatabaseDriver');
		$query = $db->getQuery(true)
			->select($db->quoteName('manifest_cache'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('element') . ' = ' . $db->quote('com_componentbuilder'))
			->where($db->quoteName('type') . ' = ' . $db->quote('component'));

		try
		{
			$db->setQuery($query);
			$manifest = json_decode((string) $db->loadResult(), true);

			return $manifest['version'] ?? null;
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}

	/**
	 * A fingerprint of the schema source, so a derived metamodel can be tied to
	 * the exact JCB it came from even when the version number has not moved.
	 */
	public function schemaFingerprint(): ?string
	{
		$path = $this->findSource();

		if ($path === null || !is_file($path . '/Componentbuilder/Table.php'))
		{
			return null;
		}

		return hash_file('sha256', $path . '/Componentbuilder/Table.php');
	}

	/**
	 * Everything the status view needs to explain what was found.
	 */
	public function report(): array
	{
		return [
			'componentInstalled' => $this->componentInstalled(),
			'version'            => $this->version(),
			'sourcePath'         => $this->findSource(),
			'classesAvailable'   => $this->classesAvailable(),
			'schemaFingerprint'  => $this->schemaFingerprint(),
			'messages'           => $this->messages,
		];
	}

	public function messages(): array
	{
		return $this->messages;
	}
}
