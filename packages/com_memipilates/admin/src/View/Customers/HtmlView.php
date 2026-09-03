<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\View\Customers;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Memi\Component\Memipilates\Administrator\View\AbstractAdminView;

/** Customer list enriched with live ledger and active-package summaries. */
class HtmlView extends AbstractAdminView
{
    /** @var list<array<string, mixed>> */
    public array $items = [];
    public bool $canCreateClient = false;
    public bool $canManageQr = false;
    public bool $canViewClientDetails = false;
    public bool $canManageEligibility = false;
    /** @var list<array<string,mixed>> */
    public array $restrictedCourseTypes = [];
    /** @var array<int,list<array<string,mixed>>> */
    public array $eligibilityOverrides = [];

    public function display($tpl = null): void
    {
        $this->initialise(['clients.manage', 'qr.manage'], ['clients.manage', 'qr.manage']);
        $this->canCreateClient = $this->can('clients.manage');
        $this->canManageQr = $this->can('qr.manage');
        $this->canViewClientDetails = $this->can('clients.manage');
        $this->canManageEligibility = $this->can('clients.manage');
        $this->loadItems();
        $this->loadEligibility();
        Factory::getApplication()->getDocument()->setTitle($this->label('COM_MEMIPILATES_SUBMENU_CUSTOMERS', 'Customers'));
        parent::display($tpl);
    }

    private function loadItems(): void
    {
        $count = $this->baseQuery()->select('COUNT(*)');
        $this->applyFilters($count);
        $this->db->setQuery($count);
        $this->setPagination((int) $this->db->loadResult());

        $query = $this->baseQuery()
            ->select(['cp.id AS client_id', 'cp.user_id', 'u.name']);

        if ($this->canViewClientDetails) {
            $now = gmdate('Y-m-d H:i:s');
            $activeStatus = 'active';
            $restoredStatus = 'restored';
            $query->select([
                'cp.phone', 'cp.preferred_locale', 'cp.created_at AS joined_at',
                'u.username', 'u.email', 'u.block',
                '(SELECT COALESCE(SUM(cl.credits_delta), 0) FROM #__memi_credit_ledger AS cl'
                    . ' WHERE cl.client_id = cp.id AND (cl.expires_at IS NULL OR cl.expires_at > :credit_now)) AS credit_balance',
                '(SELECT COALESCE(SUM(pl.points_delta), 0) FROM #__memi_points_ledger AS pl'
                    . ' WHERE pl.client_id = cp.id AND (pl.expires_at IS NULL OR pl.expires_at > :point_now)) AS point_balance',
                '(SELECT COUNT(*) FROM #__memi_customer_packages AS cpk'
                    . ' WHERE cpk.client_id = cp.id'
                    . ' AND ((cpk.status = :active_status AND (cpk.expires_at IS NULL OR cpk.expires_at > :package_now))'
                    . ' OR (cpk.status = :restored_status AND cpk.remaining_credits > 0))'
                    . ' AND cpk.archived_at IS NULL) AS active_packages',
            ])
                ->bind(':credit_now', $now)
                ->bind(':point_now', $now)
                ->bind(':active_status', $activeStatus)
                ->bind(':restored_status', $restoredStatus)
                ->bind(':package_now', $now);
        }

        if ($this->canManageQr) {
            $query->select(
                '(SELECT qt.id FROM #__memi_qr_tokens AS qt'
                    . ' WHERE qt.client_id = cp.id AND qt.revoked_at IS NULL'
                    . ' AND (qt.expires_at IS NULL OR qt.expires_at > UTC_TIMESTAMP())'
                    . ' ORDER BY qt.id DESC LIMIT 1) AS qr_token_id'
            );
        }

        $query->order('u.name ASC, u.id ASC');
        $this->applyFilters($query);
        $this->db->setQuery($query, $this->limitStart, $this->limit);
        $this->items = $this->db->loadAssocList() ?: [];
    }

    private function loadEligibility(): void
    {
        if (!$this->canManageEligibility) {
            return;
        }

        $query = $this->db->getQuery(true)
            ->select([
                'ct.id', 'ct.title', 'ct.prerequisite_attendance_count',
                'prerequisite.title AS prerequisite_title',
            ])
            ->from($this->db->quoteName('#__memi_course_types', 'ct'))
            ->join('INNER', $this->db->quoteName('#__memi_course_types', 'prerequisite') . ' ON prerequisite.id = ct.prerequisite_course_type_id')
            ->where('ct.prerequisite_attendance_count > 0')
            ->where('ct.archived_at IS NULL')
            ->where('prerequisite.archived_at IS NULL')
            ->order('ct.title ASC');
        $this->db->setQuery($query);
        $this->restrictedCourseTypes = $this->db->loadAssocList() ?: [];

        $userIds = array_values(array_filter(array_map(
            static fn (array $item): int => (int) ($item['user_id'] ?? 0),
            $this->items
        )));
        if ($userIds === []) {
            return;
        }

        $query = $this->db->getQuery(true)
            ->select(['o.user_id', 'o.course_type_id', 'o.reason', 'o.granted_at', 'ct.title AS course_type_title'])
            ->from($this->db->quoteName('#__memi_course_eligibility_overrides', 'o'))
            ->join('INNER', $this->db->quoteName('#__memi_course_types', 'ct') . ' ON ct.id = o.course_type_id')
            ->where('o.revoked_at IS NULL')
            ->where('o.user_id IN (' . implode(',', array_map('intval', $userIds)) . ')')
            ->order('ct.title ASC');
        $this->db->setQuery($query);
        foreach ($this->db->loadAssocList() ?: [] as $override) {
            $this->eligibilityOverrides[(int) $override['user_id']][] = $override;
        }
    }

    private function baseQuery(): mixed
    {
        return $this->db->getQuery(true)
            ->from($this->db->quoteName('#__memi_client_profiles', 'cp'))
            ->join('INNER', $this->db->quoteName('#__users', 'u') . ' ON u.id = cp.user_id')
            ->where('cp.archived_at IS NULL');
    }

    private function applyFilters(mixed $query): void
    {
        if ($this->filterSearch === '') {
            return;
        }

        $term = '%' . $this->filterSearch . '%';
        $columns = $this->canViewClientDetails
            ? 'u.name LIKE :filter_search OR u.username LIKE :filter_search OR u.email LIKE :filter_search OR cp.phone LIKE :filter_search'
            : 'u.name LIKE :filter_search';
        $query->where('(' . $columns . ')')
            ->bind(':filter_search', $term);
    }
}
