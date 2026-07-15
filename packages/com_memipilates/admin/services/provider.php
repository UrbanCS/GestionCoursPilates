<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Extension\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\Extension\Service\Provider\RouterFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Router\RouterFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Registry\Registry;
use Memi\Component\Memipilates\Administrator\Extension\MemipilatesComponent;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new MVCFactory('\\Memi\\Component\\Memipilates'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Memi\\Component\\Memipilates'));
        $container->registerServiceProvider(new RouterFactory('\\Memi\\Component\\Memipilates'));

        $container->set(
            ComponentInterface::class,
            static function (Container $container): MemipilatesComponent {
                $component = new MemipilatesComponent($container->get(ComponentDispatcherFactoryInterface::class));
                $component->setRegistry($container->get(Registry::class));
                $component->setDatabase($container->get(DatabaseInterface::class));
                $component->setMVCFactory($container->get(MVCFactoryInterface::class));
                $component->setRouterFactory($container->get(RouterFactoryInterface::class));

                return $component;
            }
        );
    }
};
