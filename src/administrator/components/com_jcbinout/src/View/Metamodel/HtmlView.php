<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\View\Metamodel;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Yepr\Component\Jcbinout\Administrator\Blueprint\ImportRun;

/**
 * @since 1.0.0
 */
class HtmlView extends BaseHtmlView
{
	protected array $status = [];

	/**
	 * A path to start from. The fixture shipped for testing is the only
	 * blueprint a fresh install is guaranteed to have.
	 */
	protected string $defaultBlueprint = '';

	/** The plan awaiting a decision, if one has been made. */
	protected ?array $importPlan = null;

	/** The import under way, if one has been started and not finished. */
	protected ?ImportRun $importRun = null;

	public function display($tpl = null): void
	{
		/** @var \Yepr\Component\Jcbinout\Administrator\Model\MetamodelModel $model */
		$model        = $this->getModel();
		$this->status = $model->getStatus();

		$fixture = JPATH_ADMINISTRATOR . '/components/com_jcbinout/data/hello-world';
		$this->defaultBlueprint = is_dir($fixture . '/src') ? $fixture : '';

		$app     = Factory::getApplication();
		$pending = $app instanceof CMSApplication
			? $app->getUserState('com_jcbinout.import.plan')
			: null;

		$this->importPlan = is_array($pending) ? $pending : null;
		$this->importRun  = $app instanceof CMSApplication
			? ImportRun::fromArray($app->getUserState(ImportRun::STATE_KEY))
			: null;

		$this->addToolbar();

		parent::display($tpl);
	}

	private function addToolbar(): void
	{
		ToolbarHelper::title(Text::_('COM_JCBINOUT_METAMODEL'), 'puzzle');

		$document = $this->getDocument();

		// The document's toolbar is the one the template renders; a toolbar
		// built from the factory is a new, detached instance whose buttons
		// never appear. Only an HTML document has one - in a JSON or CLI
		// context there is nothing to add buttons to.
		if (!$document instanceof HtmlDocument)
		{
			return;
		}

		$toolbar = $document->getToolbar();

		// A run under way is the only thing on offer until it is dealt with.
		// Everything else here reads or rewrites the artefacts the run is in
		// the middle of applying, and planning a second import over a
		// half-written one would plan against an installation that is still
		// changing. Finish it or stop it first.
		if ($this->importRun !== null && !$this->importRun->isFinished())
		{
			$toolbar->standardButton('play', 'COM_JCBINOUT_CONTINUE', 'metamodel.step')
				->icon('icon-play');

			$toolbar->standardButton('cancel', 'COM_JCBINOUT_CANCEL_RUN', 'metamodel.cancel')
				->icon('icon-cancel');

			return;
		}

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

			$toolbar->standardButton('upload', 'COM_JCBINOUT_EXPORT', 'metamodel.export')
				->icon('icon-upload');

			// The models this site actually holds, which is the only way to
			// export a component still being built in JCB's own interface.
			$toolbar->standardButton('database', 'COM_JCBINOUT_EXPORT_INSTALLED',
				'metamodel.exportInstalled')->icon('icon-database');
		}

		if ($this->status['instance']['exists'] ?? false)
		{
			$toolbar->standardButton('search', 'COM_JCBINOUT_PLAN', 'metamodel.plan')
				->icon('icon-search');
		}

		// Applying is offered only once there is something to apply, so the
		// write is always a decision taken about a plan that was shown.
		if ($this->importPlan !== null)
		{
			$toolbar->standardButton('save', 'COM_JCBINOUT_APPLY', 'metamodel.apply')
				->icon('icon-save');
		}
	}
}
