<?php
/** Shared shell for the protected front-end studio portal. */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Memi\Component\Memipilates\Site\Service\PortalAccess;

$application = Factory::getApplication();
$identity = $application->getIdentity();
$portalView = isset($portalView) ? (string) $portalView : 'manage';
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$portalItems = [
    ['view' => 'manage', 'label' => 'COM_MEMIPILATES_SUBMENU_DASHBOARD'],
    ['view' => 'setup', 'label' => 'COM_MEMIPILATES_SUBMENU_SETUP'],
    ['view' => 'catalog', 'label' => 'COM_MEMIPILATES_SUBMENU_CATALOG'],
    ['view' => 'sessions', 'label' => 'COM_MEMIPILATES_SUBMENU_SESSIONS'],
    ['view' => 'bookings', 'label' => 'COM_MEMIPILATES_SUBMENU_BOOKINGS'],
    ['view' => 'customers', 'label' => 'COM_MEMIPILATES_SUBMENU_CUSTOMERS'],
    ['view' => 'packages', 'label' => 'COM_MEMIPILATES_SUBMENU_PACKAGES'],
    ['view' => 'offers', 'label' => 'COM_MEMIPILATES_SUBMENU_OFFERS'],
    ['view' => 'payments', 'label' => 'COM_MEMIPILATES_SUBMENU_PAYMENTS'],
    ['view' => 'attendance', 'label' => 'COM_MEMIPILATES_SUBMENU_ATTENDANCE'],
    ['view' => 'settings', 'label' => 'COM_MEMIPILATES_PORTAL_SETTINGS'],
];
?>
<section class="memi-management-portal">
    <aside class="memi-management-sidebar">
        <div class="memi-management-sidebar__heading">
            <span class="memi-management-sidebar__eyebrow"><?= Text::_('COM_MEMIPILATES'); ?></span>
            <h1><?= Text::_('COM_MEMIPILATES_PORTAL_TITLE'); ?></h1>
        </div>
        <nav aria-label="<?= $escape(Text::_('COM_MEMIPILATES_PORTAL_TITLE')); ?>">
            <ul>
                <?php foreach ($portalItems as $item) : ?>
                    <?php if (PortalAccess::canAccess($identity, $item['view'])) : ?>
                        <li>
                            <a
                                href="<?= Route::_('index.php?option=com_memipilates&view=' . $item['view']); ?>"
                                class="<?= $portalView === $item['view'] ? 'is-active' : ''; ?>"
                                <?= $portalView === $item['view'] ? 'aria-current="page"' : ''; ?>
                            ><?= Text::_($item['label']); ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </nav>
        <div class="memi-management-sidebar__footer">
            <a href="<?= Route::_('index.php?option=com_memipilates&view=schedule'); ?>">
                <?= Text::_('COM_MEMIPILATES_PORTAL_RETURN_SCHEDULE'); ?>
            </a>
            <a href="<?= Route::_('index.php?option=com_memipilates&view=dashboard'); ?>">
                <?= Text::_('COM_MEMIPILATES_PORTAL_RETURN_ACCOUNT'); ?>
            </a>
        </div>
    </aside>
    <main class="memi-management-content">
