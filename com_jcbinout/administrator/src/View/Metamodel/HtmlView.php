<?php
/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\View\Metamodel;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * @since 1.0.0
 */
class HtmlView extends BaseHtmlView
{
	protected array $status = [];

	public function display($tpl = null): void
	{
		/** @var \Yepr\Component\Jcbinout\Administrator\Model\MetamodelModel $model */
		$model        = $this->getModel();
		$this->status = $model->getStatus();

		$this->addToolbar();

		parent::display($tpl);
	}

	private function addToolbar(): void
	{
		ToolbarHelper::title(Text::_('COM_JCBINOUT_METAMODEL'), 'puzzle');

		$toolbar  = Toolbar::getInstance('toolbar');
		$jcbReady = $this->status['jcb']['classesAvailable'] ?? false;

		if ($jcbReady || ($this->status['jcb']['sourcePath'] ?? null) !== null)
		{
			$toolbar->standardButton('refresh', 'COM_JCBINOUT_DERIVE', 'metamodel.derive')
				->icon('icon-refresh');
		}

		if ($this->status['metamodel']['info']['exists'] ?? false)
		{
			$toolbar->standardButton('cog', 'COM_JCBINOUT_BUILD', 'metamodel.build')
				->icon('icon-cog');
		}

		if ($this->status['language']['info']['exists'] ?? false)
		{
			$toolbar->standardButton('checkmark', 'COM_JCBINOUT_VALIDATE', 'metamodel.validate')
				->icon('icon-checkmark');
		}
	}
}
