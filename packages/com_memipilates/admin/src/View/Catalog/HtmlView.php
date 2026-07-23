<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\View\Catalog;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Memi\Component\Memipilates\Administrator\View\AbstractAdminView;

/** Operational editor for the live studio catalogue. */
final class HtmlView extends AbstractAdminView
{
    /** @var array<string,string> */
    public array $entities = [
        'location' => 'Emplacements',
        'room' => 'Salles',
        'instructor' => 'Instructeurs',
        'course_type' => 'Types de cours',
        'course' => 'Cours',
        'session_rule' => 'Horaires récurrents',
        'session' => 'Séance ponctuelle',
        'package' => 'Forfaits',
    ];
    public string $entity = 'course';
    /** @var array<string,mixed>|null */
    public ?array $record = null;
    /** @var list<array<string,mixed>> */
    public array $items = [];
    /** @var list<array<string,mixed>> */
    public array $locations = [];
    /** @var list<array<string,mixed>> */
    public array $rooms = [];
    /** @var list<array<string,mixed>> */
    public array $instructors = [];
    /** @var list<array<string,mixed>> */
    public array $courseTypes = [];
    /** @var list<array<string,mixed>> */
    public array $courses = [];
    /** @var list<array<string,mixed>> */
    public array $packages = [];

    public function display($tpl = null): void
    {
        $this->initialise(['courses.manage', 'schedules.manage', 'instructors.manage', 'rooms.manage', 'packages.manage']);
        $permissions = [
            'location' => 'rooms.manage', 'room' => 'rooms.manage',
            'instructor' => 'instructors.manage',
            'course_type' => 'courses.manage', 'course' => 'courses.manage',
            'session_rule' => 'schedules.manage', 'session' => 'schedules.manage',
            'package' => 'packages.manage',
        ];
        $this->entities = array_filter(
            $this->entities,
            fn (string $key): bool => $this->can($permissions[$key]),
            ARRAY_FILTER_USE_KEY
        );
        $candidate = Factory::getApplication()->input->getCmd('entity', 'course');
        $this->entity = array_key_exists($candidate, $this->entities) ? $candidate : (string) array_key_first($this->entities);
        $this->loadOptions();
        $this->loadRecord();
        $this->loadItems();
        Factory::getApplication()->getDocument()->setTitle('Catalogue du studio');
        parent::display($tpl);
    }

    private function loadOptions(): void
    {
        $this->locations = $this->simpleList('#__memi_locations', ['id', 'title'], 'title ASC');
        $this->instructors = $this->simpleList('#__memi_instructors', ['id', 'display_name AS title'], 'display_name ASC');
        $this->courseTypes = $this->simpleList('#__memi_course_types', ['id', 'title'], 'title ASC');
        $this->courses = $this->simpleList('#__memi_courses', ['id', 'title'], 'title ASC');
        $this->packages = $this->simpleList('#__memi_packages', ['id', 'title'], 'title ASC');
        $query = $this->db->getQuery(true)
            ->select(['r.id', "CONCAT(l.title, ' — ', r.title) AS title", 'r.capacity'])
            ->from($this->db->quoteName('#__memi_rooms', 'r'))
            ->join('INNER', $this->db->quoteName('#__memi_locations', 'l') . ' ON l.id = r.location_id')
            ->where('r.archived_at IS NULL')
            ->where('l.archived_at IS NULL')
            ->order('l.title ASC, r.title ASC');
        $this->db->setQuery($query);
        $this->rooms = $this->db->loadAssocList() ?: [];
    }

    private function loadRecord(): void
    {
        if ($this->entity === 'session') {
            return;
        }
        $id = Factory::getApplication()->input->getInt('id');
        if ($id <= 0) {
            return;
        }
        $table = $this->table();
        $identifier = $id;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName($table))
            ->where($this->db->quoteName('id') . ' = :id')
            ->where($this->db->quoteName('archived_at') . ' IS NULL')
            ->bind(':id', $identifier);
        $this->db->setQuery($query);
        $this->record = $this->db->loadAssoc() ?: null;
    }

    private function loadItems(): void
    {
        $query = match ($this->entity) {
            'location' => $this->db->getQuery(true)
                ->select(['id', 'title', "CONCAT_WS(', ', address_line1, city, province) AS detail", 'published'])
                ->from($this->db->quoteName('#__memi_locations'))
                ->where('archived_at IS NULL')->order('title ASC'),
            'room' => $this->db->getQuery(true)
                ->select(['r.id', 'r.title', "CONCAT(l.title, ' · ', r.capacity, ' places') AS detail", 'r.published'])
                ->from($this->db->quoteName('#__memi_rooms', 'r'))
                ->join('INNER', $this->db->quoteName('#__memi_locations', 'l') . ' ON l.id = r.location_id')
                ->where('r.archived_at IS NULL')->order('l.title ASC, r.title ASC'),
            'instructor' => $this->db->getQuery(true)
                ->select(['id', 'display_name AS title', 'email AS detail', 'published'])
                ->from($this->db->quoteName('#__memi_instructors'))->where('archived_at IS NULL')->order('display_name ASC'),
            'course_type' => $this->db->getQuery(true)
                ->select(['id', 'title', "CONCAT(default_duration_minutes, ' min · ', default_capacity, ' places') AS detail", 'published'])
                ->from($this->db->quoteName('#__memi_course_types'))->where('archived_at IS NULL')->order('title ASC'),
            'course' => $this->db->getQuery(true)
                ->select(['c.id', 'c.title', "CONCAT(ct.title, ' · ', c.duration_minutes, ' min · ', c.capacity, ' places') AS detail", 'c.published'])
                ->from($this->db->quoteName('#__memi_courses', 'c'))
                ->join('INNER', $this->db->quoteName('#__memi_course_types', 'ct') . ' ON ct.id = c.course_type_id')
                ->where('c.archived_at IS NULL')->order('c.title ASC'),
            'session_rule' => $this->db->getQuery(true)
                ->select(['r.id', 'c.title', "CONCAT(r.starts_on, ' · ', LEFT(r.start_time, 5), ' · jour ', r.weekday) AS detail", 'r.published'])
                ->from($this->db->quoteName('#__memi_session_rules', 'r'))
                ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = r.course_id')
                ->where('r.archived_at IS NULL')->order('r.starts_on DESC, r.start_time ASC'),
            'package' => $this->db->getQuery(true)
                ->select(['id', 'title', "CONCAT(credits, ' crédits · ', price_cents, ' ¢') AS detail", 'published'])
                ->from($this->db->quoteName('#__memi_packages'))->where('archived_at IS NULL')->order('title ASC'),
            default => $this->db->getQuery(true)->select(['0 AS id', "'' AS title", "'' AS detail", '0 AS published'])->where('1 = 0'),
        };
        $this->db->setQuery($query, 0, 100);
        $this->items = $this->db->loadAssocList() ?: [];
    }

    /** @return list<array<string,mixed>> */
    private function simpleList(string $table, array $columns, string $order): array
    {
        $query = $this->db->getQuery(true)
            ->select($columns)
            ->from($this->db->quoteName($table))
            ->where('archived_at IS NULL')
            ->order($order);
        $this->db->setQuery($query);

        return $this->db->loadAssocList() ?: [];
    }

    private function table(): string
    {
        return match ($this->entity) {
            'location' => '#__memi_locations',
            'room' => '#__memi_rooms',
            'instructor' => '#__memi_instructors',
            'course_type' => '#__memi_course_types',
            'course' => '#__memi_courses',
            'session_rule' => '#__memi_session_rules',
            'package' => '#__memi_packages',
            default => throw new \LogicException('Unsupported catalogue entity'),
        };
    }
}
