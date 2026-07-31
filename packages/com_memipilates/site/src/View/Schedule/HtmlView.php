<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\View\Schedule;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\Database\ParameterType;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;
use Memi\Component\Memipilates\Site\Service\PortalAccess;

final class HtmlView extends BaseHtmlView
{
    /** @var list<array<string,mixed>> */
    public array $sessions = [];
    /** @var array<string,list<array<string,mixed>>> */
    public array $filters = [];
    /** @var list<array{date:string,courseType:string,instructor:string,location:string}> */
    public array $calendarSessions = [];
    public string $calendarCoverageStart = '';
    public string $calendarCoverageEnd = '';
    public string $startDate = '';
    public string $selectedDate = '';
    public string $viewMode = 'week';
    public string $locale = 'fr-FR';
    public bool $canManageStudio = false;
    public string $managementLandingView = 'manage';
    /** @var list<int> */
    private array $authorisedViewLevels = [1];
    private int $selectedCourseTypeId = 0;

    public function display($tpl = null): void
    {
        $application = Factory::getApplication();
        $input = $application->input;
        $authorisedViewLevels = array_values(array_unique(array_map(
            'intval',
            $application->getIdentity()->getAuthorisedViewLevels()
        )));
        $this->authorisedViewLevels = $authorisedViewLevels !== [] ? $authorisedViewLevels : [1];
        $this->selectedCourseTypeId = $this->validateCourseTypeId($input->getInt('type', 0));
        $timezone = ComponentServices::settings()->timezone();
        $today = (new \DateTimeImmutable('now', $timezone))->format('Y-m-d');
        $candidate = $input->getString('date', $today);
        $parsedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $candidate)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $candidate, $timezone)
            : false;
        $selectedDate = $parsedDate instanceof \DateTimeImmutable && $parsedDate->format('Y-m-d') === $candidate
            ? $parsedDate
            : new \DateTimeImmutable($today, $timezone);
        $this->selectedDate = $selectedDate->format('Y-m-d');
        $this->startDate = $selectedDate->modify('monday this week')->format('Y-m-d');
        $requestedMode = $input->getCmd('mode', 'week');
        $this->viewMode = in_array($requestedMode, ['day', 'week'], true) ? $requestedMode : 'week';
        $this->locale = $application->getLanguage()->getTag() ?: 'fr-FR';
        $managementLandingView = PortalAccess::landingView($application->getIdentity());
        $this->canManageStudio = $managementLandingView !== null;
        $this->managementLandingView = $managementLandingView ?? 'manage';
        $this->sessions = $this->loadSessions();
        $this->calendarSessions = $this->loadCalendarSessions();
        $this->filters = $this->loadFilters();

        $document = $application->getDocument();
        $document->getWebAssetManager()
            ->useStyle('com_memipilates.site')
            ->useScript('com_memipilates.schedule');
        $document->addScriptOptions('com_memipilates.schedule', [
            'calendarSessions' => $this->calendarSessions,
            'calendarCoverageStart' => $this->calendarCoverageStart,
            'calendarCoverageEnd' => $this->calendarCoverageEnd,
            'messages' => [
                'COM_MEMIPILATES_SCHEDULE_VISIBLE_COUNT' => Text::_('COM_MEMIPILATES_SCHEDULE_VISIBLE_COUNT'),
                'COM_MEMIPILATES_SCHEDULE_DAY_CLASS_COUNT' => Text::_('COM_MEMIPILATES_SCHEDULE_DAY_CLASS_COUNT'),
                'COM_MEMIPILATES_SCHEDULE_WEEK_RANGE' => Text::_('COM_MEMIPILATES_SCHEDULE_WEEK_RANGE'),
                'COM_MEMIPILATES_SCHEDULE_WEEK_CLASSES_TITLE' => Text::_('COM_MEMIPILATES_SCHEDULE_WEEK_CLASSES_TITLE'),
                'COM_MEMIPILATES_SCHEDULE_DAY_CLASSES_TITLE' => Text::_('COM_MEMIPILATES_SCHEDULE_DAY_CLASSES_TITLE'),
            ],
        ]);
        foreach ([
            'COM_MEMIPILATES_SCHEDULE_VISIBLE_COUNT',
            'COM_MEMIPILATES_SCHEDULE_DAY_CLASS_COUNT',
            'COM_MEMIPILATES_SCHEDULE_WEEK_RANGE',
            'COM_MEMIPILATES_SCHEDULE_WEEK_CLASSES_TITLE',
            'COM_MEMIPILATES_SCHEDULE_DAY_CLASSES_TITLE',
        ] as $key) {
            Text::script($key);
        }

        parent::display($tpl);
    }

    /** @return list<array{date:string,courseType:string,instructor:string,location:string}> */
    private function loadCalendarSessions(): array
    {
        $db = ComponentServices::database();
        $settings = ComponentServices::settings();
        $timezone = $settings->timezone();
        $selected = new \DateTimeImmutable($this->selectedDate . ' 00:00:00', $timezone);
        $horizonDays = max(90, min(730, $settings->getInt('session_generation_lookahead_days', 90) + 45));
        $start = $selected->modify('first day of this month')->modify('-7 days');
        $end = $selected->modify('+' . $horizonDays . ' days')->modify('last day of this month')->modify('+8 days');
        $this->calendarCoverageStart = $start->format('Y-m-d');
        $this->calendarCoverageEnd = $end->modify('-1 day')->format('Y-m-d');
        $startUtc = $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $endUtc = $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $query = $db->getQuery(true)
            ->select([
                's.starts_at',
                'c.course_type_id',
                's.instructor_id',
                'l.id AS location_id',
            ])
            ->from($db->quoteName('#__memi_sessions', 's'))
            ->join('INNER', $db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('INNER', $db->quoteName('#__memi_course_types', 'ct') . ' ON ct.id = c.course_type_id')
            ->join('LEFT', $db->quoteName('#__memi_rooms', 'r') . ' ON r.id = s.room_id')
            ->join('LEFT', $db->quoteName('#__memi_locations', 'l') . ' ON l.id = r.location_id')
            ->where('s.starts_at >= :calendar_start')
            ->where('s.starts_at < :calendar_end')
            ->where('s.archived_at IS NULL')
            ->where('s.status IN (' . $db->quote('published') . ', ' . $db->quote('open') . ')')
            ->where('c.published = 1')
            ->where('c.archived_at IS NULL')
            ->where('ct.published = 1')
            ->where('ct.archived_at IS NULL')
            ->where('ct.access IN (' . implode(',', $this->authorisedViewLevels) . ')')
            ->order('s.starts_at ASC');
        $query
            ->bind(':calendar_start', $startUtc)
            ->bind(':calendar_end', $endUtc);
        if ($this->selectedCourseTypeId > 0) {
            $selectedCourseTypeId = $this->selectedCourseTypeId;
            $query
                ->where('c.course_type_id = :calendar_course_type_id')
                ->bind(':calendar_course_type_id', $selectedCourseTypeId, ParameterType::INTEGER);
        }
        $db->setQuery($query);
        $rows = $db->loadAssocList() ?: [];
        $sessions = [];

        foreach ($rows as $row) {
            $sessions[] = [
                'date' => (new \DateTimeImmutable((string) $row['starts_at'], new \DateTimeZone('UTC')))
                    ->setTimezone($timezone)
                    ->format('Y-m-d'),
                'courseType' => (string) (int) $row['course_type_id'],
                'instructor' => (string) (int) $row['instructor_id'],
                'location' => (string) (int) ($row['location_id'] ?? 0),
            ];
        }

        return $sessions;
    }

    /** @return list<array<string,mixed>> */
    private function loadSessions(): array
    {
        $db = ComponentServices::database();
        $start = new \DateTimeImmutable($this->startDate . ' 00:00:00', ComponentServices::settings()->timezone());
        $end = $start->modify('+7 days');
        $startUtc = $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $endUtc = $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $query = $db->getQuery(true)
            ->select([
                's.*', 'c.course_type_id AS course_type_id', 'c.title AS course_title', 'c.description AS course_description',
                'ct.title AS course_type_title', 'ct.level',
                'i.display_name AS instructor_name', 'r.title AS room_title', 'l.id AS location_id', 'l.title AS location_title',
            ])
            ->from($db->quoteName('#__memi_sessions', 's'))
            ->join('INNER', $db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('LEFT', $db->quoteName('#__memi_course_types', 'ct') . ' ON ct.id = c.course_type_id')
            ->join('LEFT', $db->quoteName('#__memi_instructors', 'i') . ' ON i.id = s.instructor_id')
            ->join('LEFT', $db->quoteName('#__memi_rooms', 'r') . ' ON r.id = s.room_id')
            ->join('LEFT', $db->quoteName('#__memi_locations', 'l') . ' ON l.id = r.location_id')
            ->where('s.starts_at >= :start_at')
            ->where('s.starts_at < :end_at')
            ->where('s.archived_at IS NULL')
            ->where('s.status IN (' . $db->quote('published') . ', ' . $db->quote('open') . ')')
            ->where('c.published = 1')
            ->where('c.archived_at IS NULL')
            ->where('ct.published = 1')
            ->where('ct.archived_at IS NULL')
            ->where('ct.access IN (' . implode(',', $this->authorisedViewLevels) . ')')
            ->order('s.starts_at ASC');
        $query->bind(':start_at', $startUtc)->bind(':end_at', $endUtc);
        if ($this->selectedCourseTypeId > 0) {
            $selectedCourseTypeId = $this->selectedCourseTypeId;
            $query
                ->where('c.course_type_id = :course_type_id')
                ->bind(':course_type_id', $selectedCourseTypeId, ParameterType::INTEGER);
        }
        $db->setQuery($query);

        return $db->loadAssocList() ?: [];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function loadFilters(): array
    {
        $db = ComponentServices::database();
        $queries = [
            'types' => $db->getQuery(true)
                ->select(['ct.id', 'ct.title'])
                ->from($db->quoteName('#__memi_course_types', 'ct'))
                ->where('ct.published = 1')
                ->where('ct.archived_at IS NULL')
                ->where('ct.access IN (' . implode(',', $this->authorisedViewLevels) . ')')
                ->order('ct.ordering, ct.title'),
            'instructors' => $db->getQuery(true)->select(['id', 'display_name AS title'])->from($db->quoteName('#__memi_instructors'))->where('published = 1')->where('archived_at IS NULL')->order('ordering, display_name'),
            'locations' => $db->getQuery(true)->select(['id', 'title'])->from($db->quoteName('#__memi_locations'))->where('published = 1')->where('archived_at IS NULL')->order('ordering, title'),
        ];
        $result = [];
        foreach ($queries as $key => $query) {
            $db->setQuery($query);
            $result[$key] = $db->loadAssocList() ?: [];
        }

        return $result;
    }

    private function validateCourseTypeId(int $requestedCourseTypeId): int
    {
        if ($requestedCourseTypeId <= 0) {
            return 0;
        }

        $db = ComponentServices::database();
        $query = $db->getQuery(true)
            ->select('ct.id')
            ->from($db->quoteName('#__memi_course_types', 'ct'))
            ->where('ct.id = :requested_course_type_id')
            ->where('ct.published = 1')
            ->where('ct.archived_at IS NULL')
            ->where('ct.access IN (' . implode(',', $this->authorisedViewLevels) . ')')
            ->bind(':requested_course_type_id', $requestedCourseTypeId, ParameterType::INTEGER);
        $db->setQuery($query);

        return (int) $db->loadResult() === $requestedCourseTypeId ? $requestedCourseTypeId : 0;
    }
}
