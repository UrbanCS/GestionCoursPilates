<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\View\Setup;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Memi\Component\Memipilates\Administrator\View\AbstractAdminView;

/** Protected first-time catalogue setup screen. */
class HtmlView extends AbstractAdminView
{
    /** @var list<array<string, mixed>> */
    public array $locations = [];
    /** @var list<array<string, mixed>> */
    public array $rooms = [];
    /** @var list<array<string, mixed>> */
    public array $instructors = [];
    /** @var list<array<string, mixed>> */
    public array $courseTypes = [];
    /** @var list<array<string, mixed>> */
    public array $courses = [];
    /** @var list<array<string, mixed>> */
    public array $packages = [];
    /** @var list<array<string, mixed>> */
    public array $rules = [];
    public bool $hasCatalog = false;
    public bool $canResetCatalog = false;
    public string $today = '';
    public string $defaultSessionStart = '';
    public int $defaultWeekday = 1;

    public function display($tpl = null): void
    {
        // This combined first-run screen exposes every catalogue domain. Keep
        // it behind core.admin; delegated staff use the filtered Catalog view.
        $this->initialise(['core.admin']);
        $now = new \DateTimeImmutable('now', $this->timezone);
        $this->today = $now->format('Y-m-d');
        $this->defaultSessionStart = $now->modify('+7 days')->setTime(9, 0)->format('Y-m-d\\TH:i');
        $this->defaultWeekday = (int) $now->format('N');
        $this->locations = $this->records('#__memi_locations', ['id', 'title', 'city'], 'title ASC');
        $this->rooms = $this->loadRooms();
        $this->instructors = $this->records('#__memi_instructors', ['id', 'display_name', 'email'], 'display_name ASC');
        $this->courseTypes = $this->records('#__memi_course_types', ['id', 'title', 'default_duration_minutes', 'default_capacity', 'default_credits_required', 'default_price_cents'], 'title ASC');
        $this->courses = $this->loadCourses();
        $this->packages = $this->records('#__memi_packages', ['id', 'title', 'credits', 'price_cents'], 'title ASC');
        $this->rules = $this->loadRules();
        $this->hasCatalog = $this->locations !== []
            || $this->rooms !== []
            || $this->instructors !== []
            || $this->courseTypes !== []
            || $this->courses !== []
            || $this->packages !== []
            || $this->rules !== [];
        $identity = Factory::getApplication()->getIdentity();
        $this->canResetCatalog = (bool) $identity->authorise('core.admin', 'com_memipilates');
        Factory::getApplication()->getDocument()->setTitle($this->label('COM_MEMIPILATES_SUBMENU_SETUP', 'Studio setup'));

        parent::display($tpl);
    }

    /** @param list<string> $fields
     *  @return list<array<string, mixed>> */
    private function records(string $table, array $fields, string $order): array
    {
        $query = $this->db->getQuery(true)
            ->select(array_map(fn (string $field): string => $this->db->quoteName($field), $fields))
            ->from($this->db->quoteName($table))
            ->where($this->db->quoteName('archived_at') . ' IS NULL')
            ->order($order);
        $this->db->setQuery($query);

        return $this->db->loadAssocList() ?: [];
    }

    /** @return list<array<string, mixed>> */
    private function loadRooms(): array
    {
        $query = $this->db->getQuery(true)
            ->select(['r.id', 'r.title', 'r.capacity', 'l.title AS location_title'])
            ->from($this->db->quoteName('#__memi_rooms', 'r'))
            ->join('INNER', $this->db->quoteName('#__memi_locations', 'l') . ' ON l.id = r.location_id')
            ->where('r.archived_at IS NULL')
            ->where('l.archived_at IS NULL')
            ->order('l.title ASC, r.title ASC');
        $this->db->setQuery($query);

        return $this->db->loadAssocList() ?: [];
    }

    /** @return list<array<string, mixed>> */
    private function loadCourses(): array
    {
        $query = $this->db->getQuery(true)
            ->select(['c.id', 'c.title', 'c.duration_minutes', 'c.capacity', 'ct.title AS course_type_title'])
            ->from($this->db->quoteName('#__memi_courses', 'c'))
            ->join('INNER', $this->db->quoteName('#__memi_course_types', 'ct') . ' ON ct.id = c.course_type_id')
            ->where('c.archived_at IS NULL')
            ->where('ct.archived_at IS NULL')
            ->order('ct.title ASC, c.title ASC');
        $this->db->setQuery($query);

        return $this->db->loadAssocList() ?: [];
    }

    /** @return list<array<string, mixed>> */
    private function loadRules(): array
    {
        $query = $this->db->getQuery(true)
            ->select(['r.id', 'r.weekday', 'r.start_time', 'r.starts_on', 'r.ends_on', 'c.title AS course_title'])
            ->from($this->db->quoteName('#__memi_session_rules', 'r'))
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = r.course_id')
            ->where('r.archived_at IS NULL')
            ->where('c.archived_at IS NULL')
            ->order('r.weekday ASC, r.start_time ASC');
        $this->db->setQuery($query);

        return $this->db->loadAssocList() ?: [];
    }
}
