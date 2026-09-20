<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Metamodel;

\defined('_JEXEC') or die;

/**
 * JCB injects a Power-flavoured table service into the remote Config classes.
 * The concrete Table only implements the base contract, so we adapt it.
 * Config only ever calls fields()/titleName()/listViewCodeName(); the rest of
 * the interface is satisfied but deliberately inert.
 */
final class TableAdapter implements \VDM\Joomla\Componentbuilder\Power\Interfaces\TableInterface
{
	public function __construct(private \VDM\Joomla\Componentbuilder\Table $core) {
    }

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
