<?php
/**
 * @package    JcbInOut
 * @copyright  Copyright (C) 2026 Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Yepr\Component\Jcbinout\Administrator\Extension\JcbinoutComponent;

return new class implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->registerServiceProvider(new MVCFactory('\Yepr\Component\Jcbinout'));
		$container->registerServiceProvider(new ComponentDispatcherFactory('\Yepr\Component\Jcbinout'));

		$container->set(
			ComponentInterface::class,
			function (Container $container) {
				$component = new JcbinoutComponent(
					$container->get(ComponentDispatcherFactoryInterface::class)
				);
				$component->setMVCFactory($container->get(MVCFactoryInterface::class));

				return $component;
			}
		);
	}
};
