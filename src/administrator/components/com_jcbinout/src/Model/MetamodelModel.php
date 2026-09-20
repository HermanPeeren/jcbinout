<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Yepr\Component\Jcbinout\Administrator\Blueprint\ImportPlanner;
use Yepr\Component\Jcbinout\Administrator\Blueprint\RepositorySource;
use Yepr\Component\Jcbinout\Administrator\Jcb\LocalStore;
use Yepr\Component\Jcbinout\Administrator\Jcb\Locator;
use Yepr\Component\Jcbinout\Administrator\Jcb\Schema;
use Yepr\Component\Jcbinout\Administrator\Lionweb\InstanceExporter;
use Yepr\Component\Jcbinout\Administrator\Lionweb\InstanceImporter;
use Yepr\Component\Jcbinout\Administrator\Lionweb\LanguageBuilder;
use Yepr\Component\Jcbinout\Administrator\Lionweb\LanguageIndex;
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
	public const INSTANCE_FILE  = 'blueprint.instance.lionweb.json';

	private ?Locator $locator = null;

	public function getLocator(): Locator
	{
		return $this->locator ??= new Locator();
	}

	/**
	 * Where derived artefacts are written.
	 *
	 * Deliberately not the same directory as the shipped reference: if it were,
	 * deriving would overwrite the baseline and the view would report shipped
	 * artefacts as though the installed JCB had produced them.
	 */
	public function workPath(): string
	{
		$path = JPATH_ADMINISTRATOR . '/components/com_jcbinout/data/derived';

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

	/** The artefact shipped with the package, for comparison. */
	public function referenceArtefact(string $file): ?array
	{
		$path = $this->shippedPath() . '/' . $file;

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

	/**
	 * Export a blueprint repository as a LionWeb instance chunk.
	 *
	 * @param callable|null $progress fn(string $stage, int $done, int $total)
	 */
	public function exportBlueprint(string $path, ?callable $progress = null): array
	{
		$language = $this->artefact(self::LANGUAGE_FILE);

		if ($language === null)
		{
			throw new \RuntimeException('Build the LionWeb language before exporting.');
		}

		$source = new RepositorySource($path);

		if (!$source->exists())
		{
			throw new \RuntimeException("No blueprint at {$path}: expected a src/ directory.");
		}

		$index = new LanguageIndex($language, $this->artefact(self::ENUM_MAP_FILE) ?? []);

		$exporter = new InstanceExporter($index);

		if ($progress !== null)
		{
			$exporter->onProgress($progress);
		}

		$payloads = $source->payloads();
		$chunk    = $exporter->export($payloads, basename($path));

		$validator = new Validator();

		if (!$validator->validate($chunk))
		{
			throw new \RuntimeException('The exported chunk is not valid: '
				. implode('; ', array_slice($validator->errors(), 0, 3)));
		}

		$this->write(self::INSTANCE_FILE, $chunk);

		return [
			'payloads'    => count($payloads),
			'stats'       => $exporter->stats($chunk),
			'diagnostics' => $exporter->diagnostics(),
		];
	}

	/**
	 * What importing a LionWeb chunk into this JCB would do.
	 *
	 * Planning and writing are separate calls so the candidate list can be
	 * shown before anything is touched.
	 */
	public function planImport(?array $chunk = null, string $mode = ImportPlanner::INITIALIZE): array
	{
		$chunk ??= $this->artefact(self::INSTANCE_FILE);

		if ($chunk === null)
		{
			throw new \RuntimeException('Export a blueprint before importing one.');
		}

		$metamodel = $this->artefact(self::METAMODEL_FILE);

		if ($metamodel === null)
		{
			throw new \RuntimeException('Derive the metamodel before importing.');
		}

		$language = $this->artefact(self::LANGUAGE_FILE);

		if ($language === null)
		{
			throw new \RuntimeException('Build the LionWeb language before importing.');
		}

		$index    = new LanguageIndex($language, $this->artefact(self::ENUM_MAP_FILE) ?? []);
		$importer = new InstanceImporter($index);
		$payloads = $importer->import($chunk);

		$schema  = new Schema($metamodel);
		$store   = new LocalStore($schema);
		$planner = new ImportPlanner($schema);

		$entities = array_map(static fn($p) => $p->entity, $payloads);
		$existing = $store->existingIdentifiers($entities);

		$plan = $planner->plan($payloads, $existing, $mode);

		return [
			'plan'        => $plan,
			'payloads'    => count($payloads),
			'diagnostics' => array_merge(
				$importer->diagnostics(),
				$planner->diagnostics(),
				$store->diagnostics()
			),
		];
	}

	/**
	 * Execute a plan against this installation's JCB tables.
	 */
	public function applyImport(array $plan, ?callable $progress = null): array
	{
		$metamodel = $this->artefact(self::METAMODEL_FILE);

		if ($metamodel === null)
		{
			throw new \RuntimeException('Derive the metamodel before importing.');
		}

		$store  = new LocalStore(new Schema($metamodel));
		$result = $store->apply($plan, $progress);

		return $result + ['diagnostics' => $store->diagnostics()];
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

		$locator = $this->getLocator();

		// Registering is what makes the classes resolvable, so a status that
		// reports before trying says "not loaded" about a working JCB.
		$locator->register();

		$reference = $this->referenceArtefact(self::METAMODEL_FILE);

		return [
			'jcb'       => $locator->report(),
			'reference' => $reference === null ? null : [
				'jcbVersion' => $reference['meta']['jcbVersion'] ?? $reference['meta']['jcbCommit'] ?? null,
				'entities'   => $reference['stats']['entitiesTotal'] ?? null,
				'matches'    => ($reference['meta']['schemaFingerprint'] ?? null)
					=== $locator->schemaFingerprint(),
			],
			'metamodel' => [
				'info'  => $this->artefactInfo(self::METAMODEL_FILE),
				'stats' => $metamodel['stats'] ?? null,
				'meta'  => $metamodel['meta'] ?? null,
			],
			'language'  => [
				'info'   => $this->artefactInfo(self::LANGUAGE_FILE),
				'census' => $language ? Validator::census($language) : null,
			],
			'instance'  => $this->artefactInfo(self::INSTANCE_FILE),
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
