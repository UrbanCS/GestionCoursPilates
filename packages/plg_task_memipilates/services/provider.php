<?php
/**
 * @package     Memi.Plugin
 * @subpackage  Task.Memipilates
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Memi\Plugin\Task\Memipilates\Extension\Memipilates;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container): PluginInterface {
                $plugin = new Memipilates((array) PluginHelper::getPlugin('task', 'memipilates'));
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
