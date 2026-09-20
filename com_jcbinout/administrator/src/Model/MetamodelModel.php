<?php
/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Yepr\Component\Jcbinout\Administrator\Jcb\Locator;
use Yepr\Component\Jcbinout\Administrator\Lionweb\LanguageBuilder;
use Yepr\Component\Jcbinout\Administrator\Lionweb\Validator;
use Yepr\Component\Jcbinout\Administrator\Metamodel\EnumHarvester;
use Yepr\Component\Jcbinout\Administrator\Metamodel\Extractor;

/**
 * Derives JCB's metamodel and the LionWeb language from the installed JCB, and
 * keeps the results where the rest of the component can read them.
 *
 * @since 1.0.0
 */
class MetamodelModel extends BaseDatabaseModel
{
	public const METAMODEL_FILE = 'jcb-metamodel.json';
	public const LANGUAGE_FILE  = 'jcb-language.lionweb.json';
	public const ENUM_MAP_FILE  = 'jcb-enum-values.json';
	public const KEY_MAP_FILE   = 'jcb-feature-keys.json';
	public const NAMES_FILE     = 'interface-names.json';

	private ?Locator $locator = null;

	public function getLocator(): Locator
	{
		return $this->locator ??= new Locator();
	}

	/**
	 * Where derived artefacts live. Writable, outside the component's own
	 * source, so an update does not discard them.
	 */
	public function workPath(): string
	{
		$path = JPATH_ADMINISTRATOR . '/components/com_jcbinout/data';

		if (!is_dir($path))
		{
			@mkdir($path, 0755, true);
		}

		return $path;
	}

	/**
	 * Reference artefacts shipped with the component, used as a fallback and
	 * as something to compare a freshly derived metamodel against.
	 */
	public function shippedPath(): string
	{
		return JPATH_ADMINISTRATOR . '/components/com_jcbinout/data';
	}

	public function artefact(string $file): ?array
	{
		$path = $this->workPath() . '/' . $file;

		if (!is_file($path))
		{
			return null;
		}

		$decoded = json_decode((string) file_get_contents($path), true);

		return is_array($decoded) ? $decoded : null;
	}

	public function artefactInfo(string $file): array
	{
		$path = $this->workPath() . '/' . $file;

		return [
			'file'     => $file,
			'exists'   => is_file($path),
			'size'     => is_file($path) ? filesize($path) : 0,
			'modified' => is_file($path) ? gmdate('c', filemtime($path)) : null,
		];
	}

	/**
	 * Where the installed JCB keeps its admin form XML and language file, which
	 * is where enumeration members are declared.
	 */
	private function harvester(): EnumHarvester
	{
		$base  = JPATH_ADMINISTRATOR . '/components/com_componentbuilder';
		$forms = $base . '/forms';

		if (!is_dir($forms) && is_dir($base . '/models/forms'))
		{
			$forms = $base . '/models/forms';
		}

		$ini = $base . '/language/en-GB/en-GB.com_componentbuilder.ini';

		if (!is_file($ini))
		{
			$ini = JPATH_ADMINISTRATOR . '/language/en-GB/en-GB.com_componentbuilder.ini';
		}

		return new EnumHarvester($forms, is_file($ini) ? $ini : null);
	}

	/**
	 * Derive the metamodel from the installed JCB and write it out.
	 *
	 * @param callable|null $progress fn(string $stage, int $done, int $total)
	 *
	 * @throws \RuntimeException when JCB is not usable
	 */
	public function deriveMetamodel(?callable $progress = null): array
	{
		$locator = $this->getLocator();

		if (!$locator->register())
		{
			throw new \RuntimeException(
				'Joomla Component Builder was not found on this site. '
				. implode(' ', $locator->messages())
			);
		}

		$extractor = new Extractor($this->harvester());

		if ($progress !== null)
		{
			$extractor->onProgress($progress);
		}

		$document = $extractor->document([
			'jcbVersion'        => $locator->version(),
			'jcbSourcePath'     => $locator->findSource(),
			'schemaFingerprint' => $locator->schemaFingerprint(),
			'site'              => Factory::getApplication()->get('sitename'),
		]);

		$this->write(self::METAMODEL_FILE, $document);

		return $document;
	}

	/**
	 * Build the LionWeb language from a derived metamodel.
	 */
	public function buildLanguage(?array $metamodel = null): array
	{
		$metamodel ??= $this->artefact(self::METAMODEL_FILE);

		if ($metamodel === null)
		{
			throw new \RuntimeException('No metamodel has been derived yet.');
		}

		$namesFile = $this->shippedPath() . '/' . self::NAMES_FILE;
		$names     = is_file($namesFile)
			? (json_decode((string) file_get_contents($namesFile), true)['interfaces'] ?? [])
			: [];

		$builder = new LanguageBuilder($metamodel, $names);

		$fingerprint = $metamodel['meta']['schemaFingerprint'] ?? null;
		$version     = ($metamodel['meta']['jcbVersion'] ?? '0')
			. ($fingerprint ? '-' . substr($fingerprint, 0, 8) : '');

		$chunk = $builder->build($version);

		$this->write(self::LANGUAGE_FILE, $chunk);
		$this->write(self::KEY_MAP_FILE, [
			'featureKeys' => $builder->featureKeys(),
			'sharedGuids' => $builder->sharedGuids(),
			'hoistedInto' => $builder->hoistedInto(),
		]);

		$valueMap = [];

		foreach ($metamodel['enumerations'] ?? [] as $name => $enum)
		{
			foreach ($enum['literals'] as $lit)
			{
				$valueMap[$name][$lit['name']] = $lit['value'];
			}
		}

		$this->write(self::ENUM_MAP_FILE, $valueMap);

		return [
			'chunk'       => $chunk,
			'diagnostics' => $builder->diagnostics(),
			'census'      => Validator::census($chunk),
		];
	}

	/**
	 * Validate the generated language chunk.
	 */
	public function validateLanguage(?array $chunk = null): array
	{
		$chunk ??= $this->artefact(self::LANGUAGE_FILE);

		if ($chunk === null)
		{
			throw new \RuntimeException('No language has been generated yet.');
		}

		$validator = new Validator();
		$ok        = $validator->validate($chunk);

		return [
			'valid'  => $ok,
			'checks' => $validator->checks(),
			'errors' => $validator->errors(),
			'census' => Validator::census($chunk),
		];
	}

	private function write(string $file, array $data): void
	{
		$path = $this->workPath() . '/' . $file;
		$json = json_encode($data,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		if ($json === false)
		{
			throw new \RuntimeException("Could not encode {$file}: " . json_last_error_msg());
		}

		if (file_put_contents($path, $json . "\n") === false)
		{
			throw new \RuntimeException("Could not write {$path}. Check permissions.");
		}
	}

	/**
	 * Everything the status view renders.
	 */
	public function getStatus(): array
	{
		$metamodel = $this->artefact(self::METAMODEL_FILE);
		$language  = $this->artefact(self::LANGUAGE_FILE);

		return [
			'jcb'       => $this->getLocator()->report(),
			'metamodel' => [
				'info'  => $this->artefactInfo(self::METAMODEL_FILE),
				'stats' => $metamodel['stats'] ?? null,
				'meta'  => $metamodel['meta'] ?? null,
			],
			'language'  => [
				'info'   => $this->artefactInfo(self::LANGUAGE_FILE),
				'census' => $language ? Validator::census($language) : null,
			],
			'stale'     => $this->isStale($metamodel),
		];
	}

	/**
	 * A derived metamodel is stale when the installed JCB's schema no longer
	 * matches the one it was derived from.
	 */
	private function isStale(?array $metamodel): ?bool
	{
		if ($metamodel === null)
		{
			return null;
		}

		$was = $metamodel['meta']['schemaFingerprint'] ?? null;
		$is  = $this->getLocator()->schemaFingerprint();

		if ($was === null || $is === null)
		{
			return null;
		}

		return $was !== $is;
	}
}
