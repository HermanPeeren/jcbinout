<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Metamodel;

\defined('_JEXEC') or die;

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

	/**
	 * @param string      $formsDir  directory holding JCB's admin form XML
	 * @param string|null $ini       en-GB language ini, for readable literal names
	 */
	public function __construct(string $formsDir, ?string $ini = null)
	{
		$this->formsDir = $formsDir;

		if ($ini !== null && is_file($ini))
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
