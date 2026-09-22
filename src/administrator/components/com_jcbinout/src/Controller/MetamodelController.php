<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Yepr\Component\Jcbinout\Administrator\Blueprint\ImportPlanner;
use Yepr\Component\Jcbinout\Administrator\Blueprint\ImportRun;

/**
 * Actions that derive the metamodel and build the LionWeb language.
 *
 * Each step reports what it did rather than only whether it succeeded: a run
 * can complete and still have findings worth reading.
 *
 * @since 1.0.0
 */
class MetamodelController extends BaseController
{
	/**
	 * User state lives on CMSApplication rather than on the interface the
	 * controller is handed, so it is asked for explicitly. A console or API
	 * application has nowhere to keep a pending plan, and saying so is better
	 * than assuming the web one.
	 */
	private function session(): ?CMSApplication
	{
		return $this->app instanceof CMSApplication ? $this->app : null;
	}

	private function done(string $msg, string $type = 'message'): void
	{
		$this->app->enqueueMessage($msg, $type);
		$this->setRedirect(Route::_('index.php?option=com_jcbinout&view=metamodel', false));
	}

	/**
	 * Read the installed JCB and write a fresh metamodel.
	 */
	public function derive(): void
	{
		Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));

		/** @var \Yepr\Component\Jcbinout\Administrator\Model\MetamodelModel $model */
		$model = $this->getModel('Metamodel');

		try
		{
			$document = $model->deriveMetamodel();
			$stats    = $document['stats'];

			$this->app->enqueueMessage(Text::sprintf(
				'COM_JCBINOUT_DERIVED_SUMMARY',
				$stats['entitiesTotal'], $stats['entitiesPortable'],
				$stats['propertiesTotal'], $stats['enumerations'],
				$stats['enumerationLiterals']
			));

			foreach ($stats['diagnosticsBySeverity'] ?? [] as $severity => $count)
			{
				if ($severity === 'error')
				{
					$this->app->enqueueMessage(
						Text::sprintf('COM_JCBINOUT_DIAGNOSTICS_ERRORS', $count), 'warning');
				}
			}

			$this->done(Text::_('COM_JCBINOUT_DERIVE_OK'));
		}
		catch (\Throwable $e)
		{
			$this->done($e->getMessage(), 'error');
		}
	}

	/**
	 * Build the LionWeb language from the derived metamodel, then validate it.
	 */
	public function build(): void
	{
		Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));

		/** @var \Yepr\Component\Jcbinout\Administrator\Model\MetamodelModel $model */
		$model = $this->getModel('Metamodel');

		try
		{
			$result = $model->buildLanguage();
			$census = $result['census'];

			$this->app->enqueueMessage(Text::sprintf(
				'COM_JCBINOUT_BUILT_SUMMARY',
				array_sum($census),
				$census['Concept'] ?? 0,
				$census['Interface'] ?? 0,
				$census['Enumeration'] ?? 0
			));

			$check = $model->validateLanguage($result['chunk']);

			if ($check['valid'])
			{
				$this->done(Text::_('COM_JCBINOUT_BUILD_OK'));
			}
			else
			{
				foreach (array_slice($check['errors'], 0, 5) as $err)
				{
					$this->app->enqueueMessage($err, 'error');
				}

				$this->done(Text::_('COM_JCBINOUT_BUILD_INVALID'), 'error');
			}
		}
		catch (\Throwable $e)
		{
			$this->done($e->getMessage(), 'error');
		}
	}

	/**
	 * Export a blueprint repository as a LionWeb instance chunk.
	 *
	 * The path comes from the request so a site can point at any blueprint it
	 * has on disk; it defaults to the fixture shipped for testing.
	 */
	public function export(): void
	{
		Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));

		/** @var \Yepr\Component\Jcbinout\Administrator\Model\MetamodelModel $model */
		$model = $this->getModel('Metamodel');

		$path = (string) $this->input->getString('blueprint', '');

		if ($path === '')
		{
			$this->done(Text::_('COM_JCBINOUT_EXPORT_NO_PATH'), 'warning');

			return;
		}

		try
		{
			$result = $model->exportBlueprint($path);
			$stats  = $result['stats'];

			$this->app->enqueueMessage(Text::sprintf(
				'COM_JCBINOUT_EXPORTED_SUMMARY',
				$result['payloads'], $stats['nodes'],
				$stats['properties'], $stats['references']
			));

			foreach ($result['diagnostics'] as $d)
			{
				if ($d['severity'] !== 'info')
				{
					$this->app->enqueueMessage($d['message'],
						$d['severity'] === 'error' ? 'error' : 'warning');
				}
			}

			$this->done(Text::_('COM_JCBINOUT_EXPORT_OK'));
		}
		catch (\Throwable $e)
		{
			$this->done($e->getMessage(), 'error');
		}
	}

	/**
	 * Show what importing the exported blueprint into this JCB would do.
	 *
	 * Planning never writes. The plan is a candidate list, and applying it is a
	 * separate, deliberate action.
	 */
	public function plan(): void
	{
		Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));

		/** @var \Yepr\Component\Jcbinout\Administrator\Model\MetamodelModel $model */
		$model = $this->getModel('Metamodel');
		$mode  = $this->input->getString('mode', ImportPlanner::INITIALIZE);

		try
		{
			$result = $model->planImport(null, $mode);
			$counts = $result['plan']['counts'];

			$this->session()?->setUserState('com_jcbinout.import.plan', $result['plan']);

			$this->app->enqueueMessage(Text::sprintf(
				'COM_JCBINOUT_PLANNED_SUMMARY',
				$result['payloads'],
				$counts[ImportPlanner::INSERT],
				$counts[ImportPlanner::UPDATE],
				$counts[ImportPlanner::SKIP]
			));

			foreach ($result['diagnostics'] as $d)
			{
				if (($d['severity'] ?? '') === 'error')
				{
					$this->app->enqueueMessage($d['message'], 'error');
				}
			}

			$this->done(Text::_('COM_JCBINOUT_PLAN_OK'));
		}
		catch (\Throwable $e)
		{
			$this->done($e->getMessage(), 'error');
		}
	}

	/**
	 * Begin writing the planned import into JCB's tables.
	 *
	 * A plan of any size is applied in slices, each one its own request, and
	 * this is the first of them. Not for the progress bar's sake: a blueprint
	 * big enough to matter will not finish inside one request, and an import is
	 * not transactional, so the difference between slicing it and not is
	 * whether anything knows how far it got.
	 */
	public function apply(): void
	{
		Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));

		$plan = $this->session()?->getUserState('com_jcbinout.import.plan');

		if (!is_array($plan) || empty($plan['operations']))
		{
			$this->done(Text::_('COM_JCBINOUT_NO_PLAN'), 'warning');

			return;
		}

		// The plan described the installation as it was before the write, so it
		// is spent the moment the first row lands. The run carries on from here.
		$this->session()?->setUserState('com_jcbinout.import.plan', null);

		// A ceiling the operator asked for, kept with the run so every slice of
		// this import honours it.
		$rows = max(0, (int) $this->input->getInt('rows', 0));

		$this->advance(new ImportRun($plan, limit: $rows));
	}

	/**
	 * Write the next slice of a run already under way.
	 */
	public function step(): void
	{
		Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));

		$run = ImportRun::fromArray($this->session()?->getUserState(ImportRun::STATE_KEY));

		if ($run === null || $run->isFinished())
		{
			$this->done(Text::_('COM_JCBINOUT_NO_RUN'), 'warning');

			return;
		}

		$this->advance($run);
	}

	/**
	 * Abandon a run, leaving what it has already written in place.
	 *
	 * Which is everything it has written: there is no undo here, and offering
	 * one that only looked like an undo would be worse than saying so.
	 */
	public function cancel(): void
	{
		Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));

		$run = ImportRun::fromArray($this->session()?->getUserState(ImportRun::STATE_KEY));

		$this->session()?->setUserState(ImportRun::STATE_KEY, null);

		$this->done($run === null
			? Text::_('COM_JCBINOUT_NO_RUN')
			: Text::sprintf('COM_JCBINOUT_RUN_CANCELLED',
				$run->position(), $run->total(), $run->counts()['applied']),
			'warning');
	}

	/**
	 * Write one slice and say where that leaves the run.
	 */
	private function advance(ImportRun $run): void
	{
		/** @var \Yepr\Component\Jcbinout\Administrator\Model\MetamodelModel $model */
		$model = $this->getModel('Metamodel');

		try
		{
			// A request killed mid-slice never got to report what it wrote, so
			// its checkpoint is ahead of the cursor the session kept. Trusting
			// the cursor instead would write those rows a second time.
			$checkpoint = $model->readCheckpoint();

			if ($checkpoint !== null && $checkpoint > $run->position())
			{
				$run->checkpoint($checkpoint);

				$this->app->enqueueMessage(
					Text::sprintf('COM_JCBINOUT_RESUMED_AT', $checkpoint), 'warning');
			}

			$result = $model->advanceImport($run);
			$counts = $run->counts();

			foreach (array_slice($result['diagnostics'], 0, 10) as $d)
			{
				$this->app->enqueueMessage($d['message'], 'error');
			}

			if ($run->isFinished())
			{
				$this->session()?->setUserState(ImportRun::STATE_KEY, null);

				$this->app->enqueueMessage(Text::sprintf(
					'COM_JCBINOUT_APPLIED_SUMMARY',
					$counts['applied'], $counts['skipped'], $counts['failed']
				), $counts['failed'] > 0 ? 'warning' : 'message');

				$this->done(Text::_('COM_JCBINOUT_APPLY_OK'));

				return;
			}

			$this->session()?->setUserState(ImportRun::STATE_KEY, $run->toArray());

			$this->done(Text::sprintf('COM_JCBINOUT_APPLY_CONTINUES',
				$run->position(), $run->total()));
		}
		catch (\Throwable $e)
		{
			// The run stays where it is: whatever went wrong, the rows already
			// written are written, and the cursor is the only record of which.
			$this->session()?->setUserState(
				ImportRun::STATE_KEY, $run->isFinished() ? null : $run->toArray());

			$this->done($e->getMessage(), 'error');
		}
	}

	/**
	 * Re-validate what is already on disk.
	 */
	public function validate(): void
	{
		Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));

		/** @var \Yepr\Component\Jcbinout\Administrator\Model\MetamodelModel $model */
		$model = $this->getModel('Metamodel');

		try
		{
			$check = $model->validateLanguage();

			foreach ($check['checks'] as $ok)
			{
				$this->app->enqueueMessage($ok, 'message');
			}

			foreach (array_slice($check['errors'], 0, 10) as $err)
			{
				$this->app->enqueueMessage($err, 'error');
			}

			$this->done($check['valid']
				? Text::_('COM_JCBINOUT_VALID')
				: Text::_('COM_JCBINOUT_INVALID'),
				$check['valid'] ? 'message' : 'error');
		}
		catch (\Throwable $e)
		{
			$this->done($e->getMessage(), 'error');
		}
	}
}
