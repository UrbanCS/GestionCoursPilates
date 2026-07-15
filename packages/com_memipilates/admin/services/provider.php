<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Memi\Component\Memipilates\Administrator\Extension\MemipilatesComponent;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new MVCFactory('\\Memi\\Component\\Memipilates'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Memi\\Component\\Memipilates'));

        $container->set(
            ComponentInterface::class,
            static function (Container $container): MemipilatesComponent {
                $component = new MemipilatesComponent($container->get(ComponentDispatcherFactoryInterface::class));
                $component->setMVCFactory($container->get(MVCFactoryInterface::class));

                return $component;
            }
        );
    }
};
