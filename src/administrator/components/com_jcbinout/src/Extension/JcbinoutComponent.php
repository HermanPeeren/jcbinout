<?php

/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later
 */

namespace Yepr\Component\Jcbinout\Administrator\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\BootableExtensionInterface;
use Joomla\CMS\Extension\MVCComponent;
use Psr\Container\ContainerInterface;

/**
 * JcbInOut: LionWeb-compatible import and export for JCB blueprints.
 *
 * @since 1.0.0
 */
class JcbinoutComponent extends MVCComponent implements BootableExtensionInterface
{
	public function boot(ContainerInterface $container): void
	{
		// The component reads the installed JCB by reflection; the locator
		// registers its namespace on demand rather than at boot, so that a
		// site without JCB still loads this component and can explain why.
	}
}
