<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\View\Schedule;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;

final class HtmlView extends BaseHtmlView
{
    /** @var list<array<string,mixed>> */
    public array $sessions = [];
    /** @var array<string,list<array<string,mixed>>> */
    public array $filters = [];
    public string $startDate = '';
    public string $viewMode = 'week';
    public string $locale = 'fr-FR';

    public function display($tpl = null): void
    {
        $application = Factory::getApplication();
        $input = $application->input;
        $timezone = ComponentServices::settings()->timezone();
        $today = (new \DateTimeImmutable('now', $timezone))->format('Y-m-d');
        $candidate = $input->getString('date', $today);
        $parsedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $candidate)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $candidate, $timezone)
            : false;
        $this->startDate = $parsedDate instanceof \DateTimeImmutable && $parsedDate->format('Y-m-d') === $candidate
            ? $candidate
            : $today;
        $this->viewMode = 'week';
        $this->locale = $application->getLanguage()->getTag() ?: 'fr-FR';
        $this->sessions = $this->loadSessions();
        $this->filters = $this->loadFilters();

        $document = $application->getDocument();
        $document->getWebAssetManager()
            ->useStyle('com_memipilates.site')
            ->useScript('com_memipilates.schedule');
        $document->addScriptOptions('com_memipilates.schedule', [
            'messages' => [
                'COM_MEMIPILATES_SCHEDULE_VISIBLE_COUNT' => Text::_('COM_MEMIPILATES_SCHEDULE_VISIBLE_COUNT'),
            ],
        ]);
        Text::script('COM_MEMIPILATES_SCHEDULE_VISIBLE_COUNT');

        parent::display($tpl);
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
            ->order('s.starts_at ASC');
        $query->bind(':start_at', $startUtc)->bind(':end_at', $endUtc);
        $db->setQuery($query);

        return $db->loadAssocList() ?: [];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function loadFilters(): array
    {
        $db = ComponentServices::database();
        $queries = [
            'types' => $db->getQuery(true)->select(['id', 'title'])->from($db->quoteName('#__memi_course_types'))->where('published = 1')->where('archived_at IS NULL')->order('ordering, title'),
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
}
