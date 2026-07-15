<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\View\Customers;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Memi\Component\Memipilates\Administrator\View\AbstractAdminView;

/** Customer list enriched with live ledger and active-package summaries. */
final class HtmlView extends AbstractAdminView
{
    /** @var list<array<string, mixed>> */
    public array $items = [];

    public function display($tpl = null): void
    {
        $this->initialise(['core.manage', 'clients.manage']);
        $this->loadItems();
        Factory::getApplication()->getDocument()->setTitle($this->label('COM_MEMIPILATES_SUBMENU_CUSTOMERS', 'Customers'));
        parent::display($tpl);
    }

    private function loadItems(): void
    {
        $count = $this->baseQuery()->select('COUNT(*)');
        $this->applyFilters($count);
        $this->db->setQuery($count);
        $this->setPagination((int) $this->db->loadResult());

        $now = gmdate('Y-m-d H:i:s');
        $activeStatus = 'active';
        $query = $this->baseQuery()
            ->select([
                'cp.id AS client_id', 'cp.user_id', 'cp.phone', 'cp.preferred_locale', 'cp.created_at AS joined_at',
                'u.name', 'u.username', 'u.email', 'u.block',
                '(SELECT COALESCE(SUM(cl.credits_delta), 0) FROM #__memi_credit_ledger AS cl'
                    . ' WHERE cl.client_id = cp.id AND (cl.expires_at IS NULL OR cl.expires_at > :credit_now)) AS credit_balance',
                '(SELECT COALESCE(SUM(pl.points_delta), 0) FROM #__memi_points_ledger AS pl'
                    . ' WHERE pl.client_id = cp.id AND (pl.expires_at IS NULL OR pl.expires_at > :point_now)) AS point_balance',
                '(SELECT COUNT(*) FROM #__memi_customer_packages AS cpk'
                    . ' WHERE cpk.client_id = cp.id AND cpk.status = :active_status'
                    . ' AND cpk.archived_at IS NULL AND (cpk.expires_at IS NULL OR cpk.expires_at > :package_now)) AS active_packages',
            ])
            ->order('u.name ASC, u.id ASC')
            ->bind(':credit_now', $now)
            ->bind(':point_now', $now)
            ->bind(':active_status', $activeStatus)
            ->bind(':package_now', $now);
        $this->applyFilters($query);
        $this->db->setQuery($query, $this->limitStart, $this->limit);
        $this->items = $this->db->loadAssocList() ?: [];
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
        $query->where('(u.name LIKE :filter_search OR u.username LIKE :filter_search OR u.email LIKE :filter_search OR cp.phone LIKE :filter_search)')
            ->bind(':filter_search', $term);
    }
}
