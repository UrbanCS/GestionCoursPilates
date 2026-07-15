<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;

/**
 * A small composition root. Keeping dependency creation here means the site,
 * administrator, CLI and task plugin execute the exact same business rules.
 */
final class ComponentServices
{
    public static function database(): DatabaseDriver
    {
        /** @var DatabaseDriver $db */
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        return $db;
    }

    public static function settings(): SettingsService
    {
        return new SettingsService(self::database());
    }

    public static function audit(): AuditLogger
    {
        return new AuditLogger(self::database());
    }

    public static function databaseTools(): DatabaseTools
    {
        return new DatabaseTools(self::database());
    }

    public static function credits(): CreditLedgerService
    {
        return new CreditLedgerService(self::database(), self::databaseTools(), self::audit());
    }

    public static function points(): PointLedgerService
    {
        return new PointLedgerService(self::database(), self::databaseTools(), self::audit());
    }

    public static function loyalty(): LoyaltyService
    {
        return new LoyaltyService(
            self::database(),
            self::databaseTools(),
            self::points(),
            self::credits(),
            self::audit()
        );
    }

    public static function qrTokens(): QrTokenService
    {
        return new QrTokenService(self::database(), self::databaseTools(), self::audit());
    }

    public static function notifications(): NotificationService
    {
        return new NotificationService(self::database(), self::settings(), self::audit());
    }

    public static function bookings(): BookingService
    {
        return new BookingService(
            self::database(),
            self::databaseTools(),
            self::settings(),
            self::credits(),
            self::audit(),
            self::notifications()
        );
    }

    public static function waitlist(): WaitlistService
    {
        return new WaitlistService(
            self::database(),
            self::databaseTools(),
            self::settings(),
            self::credits(),
            self::audit(),
            self::notifications()
        );
    }

    public static function attendance(): AttendanceService
    {
        return new AttendanceService(
            self::database(),
            self::databaseTools(),
            self::settings(),
            self::qrTokens(),
            self::points(),
            self::audit()
        );
    }

    public static function payments(): PaymentService
    {
        return new PaymentService(
            self::database(),
            self::databaseTools(),
            self::settings(),
            self::credits(),
            self::points(),
            self::audit(),
            self::notifications()
        );
    }

    public static function scheduler(): SchedulerService
    {
        return new SchedulerService(
            self::database(),
            self::databaseTools(),
            self::settings(),
            self::credits(),
            self::waitlist(),
            self::notifications(),
            self::audit()
        );
    }
}
