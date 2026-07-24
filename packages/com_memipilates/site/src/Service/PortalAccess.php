<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\Service;

defined('_JEXEC') or die;

/** Central permission map for every protected front-end management screen. */
final class PortalAccess
{
    /** @var array<string, list<string>> */
    private const VIEW_PERMISSIONS = [
        'manage' => ['reports.view'],
        'setup' => ['core.admin'],
        'catalog' => ['courses.manage', 'schedules.manage', 'instructors.manage', 'rooms.manage', 'packages.manage'],
        'sessions' => ['schedules.manage', 'courses.manage', 'waitlist.manage'],
        'bookings' => ['bookings.manage', 'bookings.manual'],
        'customers' => ['clients.manage', 'qr.manage'],
        'packages' => ['packages.manage'],
        'offers' => ['promotions.manage', 'loyalty.adjust'],
        'payments' => ['payments.view'],
        'attendance' => ['attendance.manual', 'attendance.undo'],
        'settings' => ['core.admin'],
    ];

    public static function isManagementView(string $view): bool
    {
        return isset(self::VIEW_PERMISSIONS[$view]);
    }

    /** @return list<string> */
    public static function permissionsFor(string $view): array
    {
        return self::VIEW_PERMISSIONS[$view] ?? [];
    }

    public static function canAccess(object $identity, string $view): bool
    {
        if ((bool) $identity->authorise('core.admin', 'com_memipilates')) {
            return true;
        }

        foreach (self::permissionsFor($view) as $permission) {
            if ((bool) $identity->authorise($permission, 'com_memipilates')) {
                return true;
            }
        }

        return false;
    }

    public static function canManage(object $identity): bool
    {
        return self::landingView($identity) !== null;
    }

    public static function landingView(object $identity): ?string
    {
        foreach (array_keys(self::VIEW_PERMISSIONS) as $view) {
            if (self::canAccess($identity, $view)) {
                return $view;
            }
        }

        return null;
    }
}
