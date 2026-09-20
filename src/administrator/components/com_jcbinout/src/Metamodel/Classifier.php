<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Metamodel;

\defined('_JEXEC') or die;

final class Classifier
{
	/** Field types that are numeric even where no column type says so. */
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

		// The column decides, not the widget.
		//
		// Nothing here is Boolean. A JCB radio backed by TINYINT(1) looks like
		// a yes/no and is not one: MySQL's (1) is a display width, the column
		// holds -128..127, and JCB really does store 2 and 3 in these -
		// component_router.mode_methods and joomla_component.update_server_target
		// both do. Calling them Boolean turned a 3 into false.
		//
		// Nor is every radio numeric. joomla_component.add_namespace_prefix is
		// a radio on CHAR(1) whose default is '', and reading that as an
		// integer loses the empty value the same way.
		if (preg_match('#^(TINYINT|SMALLINT|MEDIUMINT|INT|BIGINT)#', $dbType))
		{
			return 'Integer';
		}

		if (in_array($type, self::NUMERIC_TYPES, true) && $dbType === '')
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
