<?php

declare(strict_types=1);

namespace MemiPilates\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class CourseEligibilityContractTest extends TestCase
{
    public function testMigrationAddsAConfigurablePrerequisiteAndAuditedOverrides(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 2) . '/packages/com_memipilates/admin/sql/updates/mysql/1.8.16.sql'
        );

        self::assertStringContainsString('prerequisite_course_type_id', $migration);
        self::assertStringContainsString('prerequisite_attendance_count', $migration);
        self::assertStringContainsString('#__memi_course_eligibility_overrides', $migration);
        self::assertStringContainsString('granted_by', $migration);
        self::assertStringContainsString('revoked_by', $migration);
    }

    public function testEligibilityUsesConfirmedNonVoidedDistinctAttendances(): void
    {
        $service = (string) file_get_contents(
            dirname(__DIR__, 2) . '/packages/com_memipilates/admin/src/Service/EligibilityService.php'
        );

        self::assertStringContainsString('COUNT(DISTINCT a.session_id)', $service);
        self::assertStringContainsString("->where('a.status = :status')", $service);
        self::assertStringContainsString("->where('a.voided_at IS NULL')", $service);
        self::assertStringContainsString('hasActiveOverride', $service);
        self::assertStringContainsString("'eligibility.override.grant'", $service);
        self::assertStringContainsString("'eligibility.override.revoke'", $service);
    }

    public function testEveryBookingPathEnforcesEligibilityServerSide(): void
    {
        $services = dirname(__DIR__, 2) . '/packages/com_memipilates/admin/src/Service';

        foreach (['BookingService.php', 'PaymentService.php', 'WaitlistService.php'] as $file) {
            $source = (string) file_get_contents($services . '/' . $file);
            self::assertStringContainsString('assertEligibleForSession', $source, $file);
        }
    }

    public function testFrontendStudioPortalConfiguresAndOverridesAccess(): void
    {
        $component = dirname(__DIR__, 2) . '/packages/com_memipilates';
        $catalogue = (string) file_get_contents($component . '/admin/tmpl/catalog/default.php');
        $customers = (string) file_get_contents($component . '/admin/tmpl/customers/default.php');
        $controller = (string) file_get_contents($component . '/admin/src/Controller/ManagementController.php');
        $booking = (string) file_get_contents($component . '/site/tmpl/booking/default.php');

        self::assertStringContainsString('name="prerequisite_course_type_id"', $catalogue);
        self::assertStringContainsString('name="prerequisite_attendance_count"', $catalogue);
        self::assertStringContainsString('management.grantCourseEligibility', $customers);
        self::assertStringContainsString('management.revokeCourseEligibility', $customers);
        self::assertStringContainsString('grantCourseEligibility', $controller);
        self::assertStringContainsString('revokeCourseEligibility', $controller);
        self::assertStringContainsString('COM_MEMIPILATES_BOOKING_PREREQUISITE_PROGRESS', $booking);
        self::assertStringContainsString('COM_MEMIPILATES_BOOKING_PREREQUISITE_EXTERNAL', $booking);
    }

    public function testEmptySquareCardContainerIsHidden(): void
    {
        $styles = (string) file_get_contents(
            dirname(__DIR__, 2) . '/packages/com_memipilates/media/css/site.css'
        );

        self::assertStringContainsString('[data-memi-square-card]:empty', $styles);
        self::assertStringContainsString('display: none', $styles);
    }
}
