<?php
/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

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
