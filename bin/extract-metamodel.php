<?php
/**
 * JcbInOut - Phase 1: JCB metamodel extractor
 *
 * Derives an explicit, neutral description of JCB's implicit metamodel from
 * its own sources:
 *
 *   - Componentbuilder/Table.php          -> entity properties (schema + relations)
 *   - Componentbuilder/Factory.php        -> canonical portable entity catalogue
 *   - Package/<Area>/Remote/Config.php    -> transport projection per entity
 *
 * Reads the real classes by reflection (never by parsing PHP text), so the
 * result tracks JCB upstream instead of drifting from it.
 *
 * Output: jcb-metamodel.json  (input to Phase 2, the LionWeb language)
 */

declare(strict_types=1);

const GENERATOR         = 'jcbinout-metamodel-extractor';
const GENERATOR_VERSION = '0.1.0';

// ---------------------------------------------------------------------------
// Bootstrap: minimal autoloader over the vendored JCB sources.
// ---------------------------------------------------------------------------

final class Bootstrap
{
	private string $root;
	/** @var array<string,string> FQCN => absolute file */
	private array $map;

	public function __construct(string $root)
	{
		$this->root = rtrim($root, '/\\');
		$this->map  = [
			'VDM\\Joomla\\Interfaces\\TableInterface'
				=> $this->root . '/deps/Interfaces_TableInterface.php',
			'VDM\\Joomla\\Interfaces\\Remote\\ConfigInterface'
				=> $this->root . '/deps/Interfaces_Remote_ConfigInterface.php',
			'VDM\\Joomla\\Componentbuilder\\Power\\Interfaces\\TableInterface'
				=> $this->root . '/deps/Componentbuilder_Power_Interfaces_TableInterface.php',
			'VDM\\Joomla\\Abstraction\\BaseTable'
				=> $this->root . '/deps/Abstraction_BaseTable.php',
			'VDM\\Joomla\\Abstraction\\Remote\\Config'
				=> $this->root . '/AbstractConfig.php',
			'VDM\\Joomla\\Componentbuilder\\Table'
				=> $this->root . '/Table.php',
			'VDM\\Joomla\\Componentbuilder\\Factory'
				=> $this->root . '/Factory.php',
		];
	}

	public function register(): void
	{
		spl_autoload_register(function (string $class): void {
			if (isset($this->map[$class]) && is_file($this->map[$class]))
			{
				require_once $this->map[$class];
				return;
			}

			// Remote configs live in two places:
			//   VDM\Joomla\Componentbuilder\Package\<Area>\Remote\Config  (application entities)
			//   VDM\Joomla\Componentbuilder\<Area>\Remote\Config          (reusable/distribution entities:
			//                                                             Power, JoomlaPower, Fieldtype,
			//                                                             Snippet, Repository)
			if (preg_match('#^VDM\\\\Joomla\\\\Componentbuilder\\\\(?:Package\\\\)?([A-Za-z]+)\\\\Remote\\\\Config$#', $class, $m))
			{
				$file = $this->root . '/configs/' . $m[1] . '.php';

				if (is_file($file))
				{
					require_once $file;
				}
			}
		});
	}
}

// Resolve sources and register the autoloader *before* any class below
// declares an interface that lives in the JCB tree: PHP resolves `implements`
// at declaration time, which happens as this file executes top to bottom.

$root    = $argv[1] ?? (__DIR__ . '/../../jcbsrc');
$outFile = $argv[2] ?? (__DIR__ . '/../jcb-metamodel.json');

if (!is_dir($root))
{
	fwrite(STDERR, "Source root not found: {$root}\n");
	exit(1);
}

(new Bootstrap($root))->register();

/**
 * JCB injects a Power-flavoured table service into the remote Config classes.
 * The concrete Table only implements the base contract, so we adapt it.
 * Config only ever calls fields()/titleName()/listViewCodeName(); the rest of
 * the interface is satisfied but deliberately inert.
 */
final class TableAdapter implements \VDM\Joomla\Componentbuilder\Power\Interfaces\TableInterface
{
	public function __construct(private \VDM\Joomla\Componentbuilder\Table $core) {}

	// --- base contract, delegated -------------------------------------------
	public function get(?string $table = null, ?string $field = null, ?string $key = null)
	{
		return $this->core->get($table, $field, $key);
	}

	public function title(string $table): ?array
	{
		return $this->core->title($table);
	}

	public function titleName(string $table): string
	{
		return $this->core->titleName($table);
	}

	public function tables(): array
	{
		return $this->core->tables();
	}

	public function exist(string $table, ?string $field = null): bool
	{
		return $this->core->exist($table, $field);
	}

	public function fields(string $table, bool $default = false, bool $details = false): ?array
	{
		return $this->core->fields($table, $default, $details);
	}

	// --- Power extensions, not exercised during extraction ------------------
	public function parents(string $table): array
	{
		return [];
	}

	public function children(string $entity, ?array $direct = null): array
	{
		return [];
	}

	public function search(string $table, string $area): array
	{
		return [];
	}

	public function listViewCodeName(string $table): ?string
	{
		// Derived from the entity's own 'list' property in Table.php.
		$fields = $this->core->fields($table, false, true) ?? [];

		foreach ($fields as $f)
		{
			if (is_array($f) && !empty($f['list']))
			{
				return $f['list'];
			}
		}

		return null;
	}
}

/**
 * Harvests enumeration literals for `list` properties.
 *
 * Table.php records that a property is a list but not what its members are.
 * The members live in JCB's own admin form XML (admin/forms/<entity>.xml),
 * with labels as language keys resolved against the en-GB ini.
 */
final class EnumHarvester
{
	/** @var array<string,string> language key => English text */
	private array $lang = [];
	private string $formsDir;

	public function __construct(string $root)
	{
		$this->formsDir = $root . '/forms';
		$ini = $root . '/en-GB.com_componentbuilder.ini';

		if (is_file($ini))
		{
			foreach (file($ini, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line)
			{
				if ($line === '' || $line[0] === ';')
				{
					continue;
				}

				if (preg_match('#^([A-Z0-9_]+)\s*=\s*"(.*)"\s*$#', $line, $m))
				{
					$this->lang[$m[1]] = $m[2];
				}
			}
		}
	}

	public function hasForm(string $entity): bool
	{
		return is_file($this->formsDir . '/' . $entity . '.xml');
	}

	/**
	 * @return array{literals:array,allowsEmpty:bool,allNumeric:bool}|null
	 */
	public function harvest(string $entity, string $property): ?array
	{
		$file = $this->formsDir . '/' . $entity . '.xml';

		if (!is_file($file))
		{
			return null;
		}

		$xml = @simplexml_load_file($file);

		if ($xml === false)
		{
			return null;
		}

		$nodes = $xml->xpath(sprintf('//field[@name="%s"]', $property)) ?: [];

		foreach ($nodes as $node)
		{
			$options = $node->xpath('option') ?: [];

			if ($options === [])
			{
				continue;
			}

			$literals    = [];
			$allowsEmpty = false;
			$allNumeric  = true;

			foreach ($options as $opt)
			{
				$value = (string) $opt['value'];
				$key   = trim((string) $opt);

				// An empty value means "not set", not a member of the enumeration.
				if ($value === '')
				{
					$allowsEmpty = true;
					continue;
				}

				if (!is_numeric($value))
				{
					$allNumeric = false;
				}

				$label = $this->lang[$key] ?? $key;

				$literals[] = [
					'value' => $value,
					'name'  => $this->literalName($value, $label),
					'label' => $label,
				];
			}

			if ($literals === [])
			{
				continue;
			}

			return [
				'literals'    => $literals,
				'allowsEmpty' => $allowsEmpty,
				'allNumeric'  => $allNumeric,
			];
		}

		return null;
	}

	/**
	 * A stable, readable literal name: prefer the value when it is already a
	 * usable identifier (VARCHAR, TEXT), otherwise derive it from the label.
	 */
	private function literalName(string $value, string $label): string
	{
		$fromValue = (string) preg_replace('#[^A-Za-z0-9]+#', '_', $value);

		if ($fromValue !== '' && preg_match('#^[A-Za-z]#', $fromValue))
		{
			return strtoupper(trim($fromValue, '_'));
		}

		$fromLabel = strtoupper(trim((string) preg_replace('#[^A-Za-z0-9]+#', '_', $label), '_'));

		if ($fromLabel !== '' && preg_match('#^[A-Za-z]#', $fromLabel))
		{
			return $fromLabel;
		}

		return 'V' . preg_replace('#[^A-Za-z0-9]+#', '_', $value);
	}
}

// ---------------------------------------------------------------------------
// Property classification: the rules that Phase 2 turns into LionWeb features.
// ---------------------------------------------------------------------------

final class Classifier
{
	/** JCB field types that are scalar regardless of db type. */
	private const BOOLEAN_TYPES = ['radio', 'checkbox'];
	private const NUMERIC_TYPES = ['integer', 'number'];

	/**
	 * Classify one property into a feature kind plus a datatype hint.
	 *
	 * kind is one of:
	 *   property     - plain value
	 *   reference    - points at another entity by portable guid
	 *   reference_local - points at another entity by local numeric id (not portable)
	 *   containment  - subform; rows become their own concept
	 *   structured   - json blob with no declared row shape (lossy risk)
	 *   secret       - encrypted, installation-local (never portable)
	 */
	public function classify(array $prop): array
	{
		$type  = $prop['type']  ?? null;
		$store = $prop['store'] ?? null;
		$link  = $prop['link']  ?? null;
		$db    = $prop['db']    ?? [];

		if ($store === 'basic_encryption')
		{
			return ['kind' => 'secret', 'datatype' => 'String', 'portable' => false];
		}

		if (is_array($link) && $link !== [])
		{
			$key = $link['key'] ?? '';

			if ($key === 'guid')
			{
				return [
					'kind'      => 'reference',
					'datatype'  => null,
					'portable'  => true,
					'target'    => $link['entity'] ?? null,
					'targetKey' => 'guid',
				];
			}

			// key '' or 'id' -> installation-local realisation, not a portable identity
			return [
				'kind'      => 'reference_local',
				'datatype'  => null,
				'portable'  => false,
				'target'    => $link['entity'] ?? null,
				'targetKey' => $key === '' ? '(unspecified)' : $key,
			];
		}

		if ($type === 'subform')
		{
			$rows = $prop['fields'] ?? null;

			return [
				'kind'      => 'containment',
				'datatype'  => null,
				'portable'  => true,
				'hasRowShape' => is_array($rows) && $rows !== [],
			];
		}

		if ($store === 'json')
		{
			return [
				'kind'        => 'structured',
				'datatype'    => 'JsonBlob',
				'portable'    => true,
				'hasRowShape' => false,
			];
		}

		return [
			'kind'     => 'property',
			'datatype' => $this->datatype($type, $db),
			'portable' => true,
			'encoding' => $store === 'base64' ? 'base64' : null,
		];
	}

	private function datatype(?string $type, array $db): string
	{
		$dbType = strtoupper((string) ($db['type'] ?? ''));

		if (in_array($type, self::BOOLEAN_TYPES, true))
		{
			// radio is only boolean when the column is a narrow int
			if (preg_match('#^(TINYINT|SMALLINT|INT)#', $dbType))
			{
				return 'Boolean';
			}
		}

		if (in_array($type, self::NUMERIC_TYPES, true)
			|| preg_match('#^(TINYINT|SMALLINT|MEDIUMINT|INT|BIGINT)#', $dbType))
		{
			return 'Integer';
		}

		if ($type === 'list')
		{
			return 'Enumeration?';   // options are not declared in Table.php - see diagnostics
		}

		return 'String';
	}

	/** Classify the inner fields of a subform row. */
	public function classifyRow(array $rowFields): array
	{
		$out = [];

		foreach ($rowFields as $name => $def)
		{
			$link = $def['link'] ?? null;

			if (is_array($link) && $link !== [])
			{
				$key = $link['key'] ?? '';
				$out[$name] = [
					'name'      => $def['name'] ?? $name,
					'jcbType'   => $def['type'] ?? null,
					'kind'      => $key === 'guid' ? 'reference' : 'reference_local',
					'target'    => $link['entity'] ?? null,
					'targetKey' => $key === '' ? '(unspecified)' : $key,
				];
				continue;
			}

			$out[$name] = [
				'name'    => $def['name'] ?? $name,
				'jcbType' => $def['type'] ?? null,
				'kind'    => 'property',
				// Row fields carry no db metadata in Table.php; Phase 2 must infer
				// from observed instance data or default to String.
				'datatype' => 'String?',
			];
		}

		return $out;
	}
}

// ---------------------------------------------------------------------------
// Extractor
// ---------------------------------------------------------------------------

final class Extractor
{
	private array $tables;        // from Table.php
	private array $entityMap;     // from Factory.php
	private array $defaults;      // shared Joomla columns present on every table
	private Classifier $classifier;
	private EnumHarvester $enums;
	private array $enumerations = [];
	private array $diagnostics = [];

	public function __construct(string $root)
	{
		$this->classifier = new Classifier();
		$this->enums      = new EnumHarvester($root);
		$this->tables     = $this->readTables();
		$this->entityMap  = $this->readEntityMap();
		$this->defaults   = $this->readDefaults();
	}

	/** Read the protected $tables array out of the real Table class. */
	private function readTables(): array
	{
		$rc   = new ReflectionClass(\VDM\Joomla\Componentbuilder\Table::class);
		$prop = $rc->getProperty('tables');
		$prop->setAccessible(true);

		return $prop->getValue($rc->newInstanceWithoutConstructor());
	}

	/** Read the protected $defaults array: columns implicit on every entity. */
	private function readDefaults(): array
	{
		$rc   = new ReflectionClass(\VDM\Joomla\Componentbuilder\Table::class);
		$prop = $rc->getProperty('defaults');
		$prop->setAccessible(true);

		return $prop->getValue($rc->newInstanceWithoutConstructor());
	}

	public function defaults(): array
	{
		return $this->defaults;
	}

	/** Read the private static $entityMap out of the real Factory class. */
	private function readEntityMap(): array
	{
		$rc   = new ReflectionClass(\VDM\Joomla\Componentbuilder\Factory::class);
		$prop = $rc->getProperty('entityMap');
		$prop->setAccessible(true);

		return $prop->getValue();
	}

	private function diag(string $severity, string $code, string $message, array $ctx = []): void
	{
		$this->diagnostics[] = array_filter([
			'severity' => $severity,
			'code'     => $code,
			'message'  => $message,
			'context'  => $ctx ?: null,
		], static fn($v) => $v !== null);
	}

	/** Instantiate an entity's remote Config, if one exists. */
	private function transportFor(string $entity, ?string $area): ?array
	{
		if ($area === null)
		{
			return null;
		}

		// Factory areas may be dotted ('Joomla.Power'); the namespace segment is not.
		$segment = str_replace('.', '', $area);

		// 'Joomla.Fieldtype' is namespaced as Fieldtype, while 'Joomla.Power'
		// is namespaced as JoomlaPower - try both readings of a dotted area.
		$tail = str_contains($area, '.')
			? substr($area, (int) strrpos($area, '.') + 1)
			: null;

		$candidates = array_values(array_unique(array_filter([
			"VDM\\Joomla\\Componentbuilder\\Package\\{$segment}\\Remote\\Config",
			"VDM\\Joomla\\Componentbuilder\\{$segment}\\Remote\\Config",
			$tail ? "VDM\\Joomla\\Componentbuilder\\Package\\{$tail}\\Remote\\Config" : null,
			$tail ? "VDM\\Joomla\\Componentbuilder\\{$tail}\\Remote\\Config" : null,
		])));

		$class = null;

		foreach ($candidates as $candidate)
		{
			if (class_exists($candidate))
			{
				$class = $candidate;
				break;
			}
		}

		if ($class === null)
		{
			$this->diag('warning', 'CONFIG_MISSING',
				"No remote Config class for entity '{$entity}' (area '{$area}').",
				['entity' => $entity, 'area' => $area, 'tried' => $candidates]);

			return null;
		}

		// Config's constructor wants the Power table contract; adapt the real Table.
		$config = new $class(new TableAdapter(new \VDM\Joomla\Componentbuilder\Table()));

		$get = static function (object $o, string $method) {
			try
			{
				return method_exists($o, $method) ? $o->$method() : null;
			}
			catch (\Throwable $e)
			{
				return null;
			}
		};

		// Several transport properties are protected with no accessor -
		// $ignore in particular, which defines the portable projection.
		$raw = static function (object $o, string $property) {
			try
			{
				$p = new ReflectionProperty($o, $property);
				$p->setAccessible(true);

				return $p->isInitialized($o) ? $p->getValue($o) : null;
			}
			catch (\Throwable $e)
			{
				return null;
			}
		};

		return array_filter([
			'configClass'     => $class,
			'ignore'          => $raw($config, 'ignore'),
			'placeholders'    => $raw($config, 'placeholders') ?: null,
			'prefixKey'       => $raw($config, 'prefix_key') ?: null,
			'suffixKey'       => $raw($config, 'suffix_key') ?: null,
			'guidField'       => $get($config, 'getGuidField'),
			'guidHelperField' => $get($config, 'getGuidHelperField'),
			'indexPath'       => $get($config, 'getIndexPath'),
			'srcPath'         => $get($config, 'getSrcPath'),
			'settingsName'    => $get($config, 'getSettingsName'),
			'mainReadmePath'  => $get($config, 'getMainReadmePath'),
			'children'        => $get($config, 'getChildren'),
			'files'           => $get($config, 'getFiles'),
			'folders'         => $get($config, 'getFolders'),
			'titleName'       => $get($config, 'getTitleName'),
			'indexHeader'     => $get($config, 'getIndexHeader'),
		], static fn($v) => $v !== null);
	}

	public function run(): array
	{
		$entities     = [];
		$portableSet  = array_keys($this->entityMap);

		foreach ($this->tables as $entity => $properties)
		{
			$area     = $this->entityMap[$entity]['area'] ?? null;
			$portable = isset($this->entityMap[$entity]);

			if (!$portable)
			{
				$this->diag('info', 'NOT_PORTABLE',
					"Entity '{$entity}' exists in Table.php but is not in the transport catalogue; "
					. 'it is installation-local and will not appear in a blueprint.',
					['entity' => $entity]);
			}

			$transport = $portable ? $this->transportFor($entity, $area) : null;
			$ignore    = $transport['ignore'] ?? [];

			$props = [];

			foreach ($properties as $name => $prop)
			{
				if (!is_array($prop))
				{
					continue;
				}

				$c = $this->classifier->classify($prop);
				$inIgnore = in_array($name, $ignore, true);

				$entry = [
					'name'     => $prop['name'] ?? $name,
					'guid'     => $prop['guid'] ?? null,
					'label'    => $prop['label'] ?? null,
					'jcbType'  => $prop['type'] ?? null,
					'store'    => $prop['store'] ?? null,
					'kind'     => $c['kind'],
					'portable' => $c['portable'] && !$inIgnore,
				];

				if ($inIgnore)
				{
					$entry['excludedBy'] = 'transport-ignore';
				}

				if (!empty($prop['db']) && is_array($prop['db']))
				{
					$entry['db'] = [
						'type'      => $prop['db']['type'] ?? null,
						'default'   => $prop['db']['default'] ?? null,
						'null'      => $prop['db']['null_switch'] ?? null,
						'key'       => $prop['db']['key'] ?? null,
						'uniqueKey' => $prop['db']['unique_key'] ?? null,
					];
				}

				foreach (['datatype', 'encoding', 'target', 'targetKey'] as $k)
				{
					if (!empty($c[$k]))
					{
						$entry[$k] = $c[$k];
					}
				}

				// ---- integrity checks -------------------------------------
				if ($entry['guid'] === null && $entry['portable'])
				{
					$this->diag('warning', 'PROPERTY_NO_GUID',
						"Portable property '{$entity}.{$name}' has no GUID; "
						. 'Phase 2 must synthesise a stable feature key.',
						['entity' => $entity, 'property' => $name]);
				}

				if (isset($c['target']) && $c['target'] !== null
					&& !isset($this->tables[$c['target']]))
				{
					$this->diag('error', 'LINK_TARGET_UNKNOWN',
						"Property '{$entity}.{$name}' links to unknown entity '{$c['target']}'.",
						['entity' => $entity, 'property' => $name, 'target' => $c['target']]);
				}

				if ($c['kind'] === 'reference_local' && $entry['portable'])
				{
					$this->diag('warning', 'LOCAL_REFERENCE_PORTABLE',
						"Property '{$entity}.{$name}' references '" . ($c['target'] ?? '?')
						. "' by local key '" . ($c['targetKey'] ?? '?')
						. "' but is not in the ignore list; it cannot round-trip between installations.",
						['entity' => $entity, 'property' => $name, 'target' => $c['target']]);
				}

				if ($c['kind'] === 'containment')
				{
					if (!empty($c['hasRowShape']))
					{
						$entry['subform'] = [
							'rowConcept' => $this->rowConceptName($entity, $name),
							'fields'     => $this->classifier->classifyRow($prop['fields']),
						];
					}
					else
					{
						$entry['subform'] = ['rowConcept' => null, 'fields' => []];
						$this->diag('warning', 'SUBFORM_NO_SHAPE',
							"Subform '{$entity}.{$name}' declares no row shape in Table.php; "
							. 'Phase 2 must fall back to a JSON blob property (lossy).',
							['entity' => $entity, 'property' => $name]);
					}
				}

				if ($c['kind'] === 'structured')
				{
					$this->diag('info', 'JSON_NO_SHAPE',
						"Property '{$entity}.{$name}' is stored as JSON with no declared shape; "
						. 'treated as an opaque blob.',
						['entity' => $entity, 'property' => $name]);
				}

				if (($c['datatype'] ?? null) === 'Enumeration?')
				{
					$harvested = $this->enums->harvest($entity, $name);

					if ($harvested === null)
					{
						$entry['datatype'] = 'String';
						$this->diag('warning', 'ENUM_OPTIONS_UNRESOLVED',
							"Property '{$entity}.{$name}' is a list but no options were found in "
							. "admin/forms/{$entity}.xml; falling back to String.",
							['entity' => $entity, 'property' => $name]);
					}
					else
					{
						$enumName = $this->enumName($entity, $name);

						$this->enumerations[$enumName] = [
							'name'        => $enumName,
							'origin'      => ['entity' => $entity, 'property' => $name,
								'source' => "admin/forms/{$entity}.xml"],
							'allowsEmpty' => $harvested['allowsEmpty'],
							'literals'    => $harvested['literals'],
						];

						$entry['datatype']    = 'Enumeration';
						$entry['enumeration'] = $enumName;
						$entry['optional']    = $harvested['allowsEmpty'];

						if ($harvested['allNumeric'])
						{
							$this->diag('info', 'ENUM_NUMERIC_VALUES',
								"Enumeration '{$enumName}' ({$entity}.{$name}) has only numeric "
								. 'values; confirm it is a closed set and not a constrained number.',
								['entity' => $entity, 'property' => $name,
								 'values' => array_column($harvested['literals'], 'value')]);
						}
					}
				}

				$props[$name] = $entry;
			}

			$entities[$entity] = array_filter([
				'name'       => $entity,
				'area'       => $area,
				'portable'   => $portable,
				'superpower' => $this->entityMap[$entity]['superpower'] ?? null,
				'transport'  => $transport,
				'properties' => $props,
			], static fn($v) => $v !== null);
		}

		// Catalogue entries with no schema at all.
		foreach ($portableSet as $entity)
		{
			if (!isset($this->tables[$entity]))
			{
				$this->diag('error', 'CATALOGUE_NO_SCHEMA',
					"Entity '{$entity}' is in the transport catalogue but has no definition in Table.php.",
					['entity' => $entity]);
			}
		}

		// Cross-check declared children against actual inbound references.
		$this->checkChildren($entities);

		return $entities;
	}

	private function enumName(string $entity, string $property): string
	{
		$camel = str_replace(' ', '', ucwords(str_replace('_', ' ', $entity)));
		$prop  = str_replace(' ', '', ucwords(str_replace('_', ' ', $property)));

		return $camel . $prop;
	}

	public function enumerations(): array
	{
		ksort($this->enumerations);

		return $this->enumerations;
	}

	private function rowConceptName(string $entity, string $property): string
	{
		$camel = str_replace(' ', '', ucwords(str_replace('_', ' ', $entity)));
		$prop  = str_replace(' ', '', ucwords(str_replace('_', ' ', $property)));

		return $camel . $prop . 'Row';
	}

	/**
	 * A declared child should have some property pointing back at its parent.
	 * Where it does not, transport and schema disagree - worth knowing early.
	 */
	private function checkChildren(array $entities): void
	{
		foreach ($entities as $entity => $def)
		{
			foreach ($def['transport']['children'] ?? [] as $child)
			{
				if (!isset($entities[$child]))
				{
					$this->diag('error', 'CHILD_UNKNOWN',
						"Entity '{$entity}' declares child '{$child}' which has no definition.",
						['entity' => $entity, 'child' => $child]);
					continue;
				}

				$backRef = false;

				foreach ($entities[$child]['properties'] ?? [] as $p)
				{
					if (($p['target'] ?? null) === $entity)
					{
						$backRef = true;
						break;
					}
				}

				if (!$backRef)
				{
					$this->diag('warning', 'CHILD_NO_BACKREF',
						"Child '{$child}' of '{$entity}' has no property linking back to its parent; "
						. 'the containment edge is implied by transport config only.',
						['entity' => $entity, 'child' => $child]);
				}
			}
		}
	}

	public function diagnostics(): array
	{
		return $this->diagnostics;
	}
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$extractor = new Extractor($root);
$entities  = $extractor->run();
$diags     = $extractor->diagnostics();

// ---- statistics -----------------------------------------------------------

$stats = [
	'entitiesTotal'    => count($entities),
	'entitiesPortable' => count(array_filter($entities, static fn($e) => $e['portable'])),
	'propertiesTotal'  => 0,
	'byKind'           => [],
	'diagnosticsBySeverity' => [],
	'enumerations'     => 0,
	'enumerationLiterals' => 0,
];

foreach ($entities as $e)
{
	foreach ($e['properties'] ?? [] as $p)
	{
		$stats['propertiesTotal']++;
		$k = $p['kind'];
		$stats['byKind'][$k] = ($stats['byKind'][$k] ?? 0) + 1;
	}
}

$stats['enumerations'] = count($extractor->enumerations());

foreach ($extractor->enumerations() as $e)
{
	$stats['enumerationLiterals'] += count($e['literals']);
}

foreach ($diags as $d)
{
	$s = $d['severity'];
	$stats['diagnosticsBySeverity'][$s] = ($stats['diagnosticsBySeverity'][$s] ?? 0) + 1;
}

ksort($stats['byKind']);

$hash = static function (string $f): ?string {
	return is_file($f) ? hash_file('sha256', $f) : null;
};

$document = [
	'meta' => [
		'generator'        => GENERATOR,
		'generatorVersion' => GENERATOR_VERSION,
		'jcbCommit'        => getenv('JCB_COMMIT') ?: 'bca4a1520484f3e2c2fbd12964a5995b0d058de1',
		'extractedAt'      => gmdate('c'),
		'sourceHashes'     => array_filter([
			'Table.php'   => $hash($root . '/Table.php'),
			'Factory.php' => $hash($root . '/Factory.php'),
		]),
	],
	'stats'         => $stats,
	'enumerations'  => $extractor->enumerations(),
	'entities'      => $entities,
	'diagnostics' => $diags,
];

file_put_contents(
	$outFile,
	json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

fwrite(STDERR, sprintf(
	"Wrote %s\n  entities: %d (%d portable)\n  properties: %d\n  kinds: %s\n  diagnostics: %s\n",
	$outFile,
	$stats['entitiesTotal'],
	$stats['entitiesPortable'],
	$stats['propertiesTotal'],
	json_encode($stats['byKind']),
	json_encode($stats['diagnosticsBySeverity'])
));
