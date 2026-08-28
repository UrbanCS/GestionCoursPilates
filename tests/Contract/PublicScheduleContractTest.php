<?php

declare(strict_types=1);

namespace MemiPilates\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class PublicScheduleContractTest extends TestCase
{
    public function testScheduleLoadsAndRendersARealMondayToSundayWeek(): void
    {
        $root = dirname(__DIR__, 2) . '/packages/com_memipilates';
        $view = (string) file_get_contents($root . '/site/src/View/Schedule/HtmlView.php');
        $template = (string) file_get_contents($root . '/site/tmpl/schedule/default.php');

        self::assertStringContainsString("modify('monday this week')", $view);
        self::assertStringContainsString("\$input->getCmd('mode', 'week')", $view);
        self::assertStringContainsString('data-default-view="<?= $escape($this->viewMode); ?>"', $template);
        self::assertStringContainsString('data-memi-schedule-view="week"', $template);
        self::assertStringContainsString('data-memi-schedule-view="day"', $template);
        self::assertStringContainsString('data-memi-schedule-list-heading', $template);
    }

    public function testDatesWithClassesAreMarkedInBothCalendars(): void
    {
        $root = dirname(__DIR__, 2) . '/packages/com_memipilates';
        $view = (string) file_get_contents($root . '/site/src/View/Schedule/HtmlView.php');
        $template = (string) file_get_contents($root . '/site/tmpl/schedule/default.php');
        $script = (string) file_get_contents($root . '/media/js/schedule.js');
        $style = (string) file_get_contents($root . '/media/css/site.css');

        self::assertStringContainsString('$this->calendarSessions = $this->loadCalendarSessions()', $view);
        self::assertStringContainsString("'calendarSessions' => \$this->calendarSessions", $view);
        self::assertStringContainsString("'calendarCoverageStart' => \$this->calendarCoverageStart", $view);
        self::assertStringContainsString('targetMonthStart < this.calendarCoverageStart', $script);
        self::assertStringContainsString('this.navigateToDate(toIsoDate(targetMonthStart)', $script);
        self::assertStringNotContainsString('data-memi-schedule-date-availability', $template);
        self::assertStringNotContainsString('data-memi-schedule-date-count', $template);
        self::assertStringContainsString('updateDayIndicators()', $script);
        self::assertStringContainsString("button.classList.toggle('has-sessions'", $script);
        self::assertStringContainsString('.memi-schedule__calendar-day.has-sessions::after', $style);
        self::assertStringContainsString('.memi-schedule__date.has-sessions:not(.is-active)', $style);
    }

    public function testCourseTypeAccessAndRequestedTypeAreEnforcedServerSide(): void
    {
        $root = dirname(__DIR__, 2) . '/packages/com_memipilates';
        $view = (string) file_get_contents($root . '/site/src/View/Schedule/HtmlView.php');

        self::assertStringContainsString("\$application->getIdentity()->getAuthorisedViewLevels()", $view);
        self::assertStringContainsString("\$input->getInt('type', 0)", $view);
        self::assertGreaterThanOrEqual(
            4,
            substr_count($view, "ct.access IN (' . implode(',', \$this->authorisedViewLevels) . ')'")
        );
        self::assertStringContainsString('private function validateCourseTypeId(int $requestedCourseTypeId): int', $view);
        self::assertStringContainsString("'c.course_type_id = :course_type_id'", $view);
        self::assertStringContainsString("'c.course_type_id = :calendar_course_type_id'", $view);
        self::assertStringContainsString(
            "bind(':course_type_id', \$selectedCourseTypeId, ParameterType::INTEGER)",
            $view
        );
        self::assertStringContainsString(
            "bind(':calendar_course_type_id', \$selectedCourseTypeId, ParameterType::INTEGER)",
            $view
        );
    }

    public function testWeeklyLabelsExistInBothSiteLanguages(): void
    {
        $root = dirname(__DIR__, 2) . '/packages/com_memipilates/site/language';
        $keys = [
            'COM_MEMIPILATES_SCHEDULE_VIEW_OPTIONS',
            'COM_MEMIPILATES_SCHEDULE_WEEK_RANGE',
            'COM_MEMIPILATES_SCHEDULE_WEEK_CLASSES_TITLE',
            'COM_MEMIPILATES_SCHEDULE_DAY_CLASSES_TITLE',
            'COM_MEMIPILATES_SCHEDULE_DAY_CLASS_COUNT',
            'COM_MEMIPILATES_SCHEDULE_NO_RESULTS_WEEK',
        ];

        foreach (['fr-FR', 'en-GB'] as $language) {
            $contents = (string) file_get_contents($root . '/' . $language . '/com_memipilates.ini');
            foreach ($keys as $key) {
                self::assertStringContainsString($key . '=', $contents, $language . ': ' . $key);
            }
        }
    }

    public function testSingleStudioScheduleUsesTheRequestedClientCopyAndNavigation(): void
    {
        $component = dirname(__DIR__, 2) . '/packages/com_memipilates';
        $template = (string) file_get_contents($component . '/site/tmpl/schedule/default.php');
        $french = (string) file_get_contents($component . '/site/language/fr-FR/com_memipilates.ini');

        self::assertStringContainsString('COM_MEMIPILATES_SCHEDULE_PAGE_TITLE="Réservez"', $french);
        self::assertStringContainsString(
            'COM_MEMIPILATES_SCHEDULE_DAY_CLASSES_TITLE="Cours disponibles seulement"',
            $french
        );
        self::assertStringNotContainsString('view=dashboard', $template);
        self::assertStringNotContainsString('view=checkout', $template);
        self::assertStringNotContainsString('data-memi-schedule-filter="location"', $template);
        self::assertStringNotContainsString('data-location=', $template);
        self::assertStringNotContainsString('$locationText', $template);
        self::assertStringContainsString("\$room = trim((string) (\$session['room_title'] ?? ''));", $template);
        self::assertStringContainsString('COM_MEMIPILATES_SCHEDULE_BOOK="RÉSERVEZ"', $french);
        self::assertStringContainsString('COM_MEMIPILATES_SCHEDULE_JOIN_WAITLIST="S\'inscrire à la liste d\'attente"', $french);
        self::assertStringContainsString('COM_MEMIPILATES_SCHEDULE_ONE_PLACE_LEFT', $template);
        self::assertStringNotContainsString("Text::sprintf('COM_MEMIPILATES_SCHEDULE_PLACES_LEFT'", $template);
    }
}
