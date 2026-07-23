<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\View\Packages;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Memi\Component\Memipilates\Administrator\View\AbstractAdminView;

/** Product list with real active-customer and outstanding-credit counts. */
final class HtmlView extends AbstractAdminView
{
    /** @var list<array<string, mixed>> */
    public array $items = [];
    /** @var list<string> */
    public array $statuses = ['published', 'unpublished'];

    public function display($tpl = null): void
    {
        $this->initialise(['packages.manage']);
        $this->filterStatus = $this->normaliseStatus(Factory::getApplication()->input->getCmd('filter_status', ''), $this->statuses);
        $this->loadItems();
        Factory::getApplication()->getDocument()->setTitle($this->label('COM_MEMIPILATES_SUBMENU_PACKAGES', 'Packages'));
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
        $activeStatusCredits = 'active';
        $restoredStatus = 'restored';
        $restoredStatusCredits = 'restored';
        $query = $this->baseQuery()
            ->select([
                'p.id', 'p.title', 'p.alias', 'p.price_cents', 'p.credits', 'p.validity_days',
                'p.fixed_expiry_at', 'p.maximum_bookings', 'p.bonus_points', 'p.published', 'p.ordering',
                '(SELECT COUNT(*) FROM #__memi_customer_packages AS cpk'
                    . ' WHERE cpk.package_id = p.id'
                    . ' AND ((cpk.status = :active_status AND (cpk.expires_at IS NULL OR cpk.expires_at > :package_now))'
                    . ' OR (cpk.status = :restored_status AND cpk.remaining_credits > 0))'
                    . ' AND cpk.archived_at IS NULL) AS active_customers',
                '(SELECT COALESCE(SUM(cpk.remaining_credits), 0) FROM #__memi_customer_packages AS cpk'
                    . ' WHERE cpk.package_id = p.id'
                    . ' AND ((cpk.status = :active_status_credits AND (cpk.expires_at IS NULL OR cpk.expires_at > :package_credit_now))'
                    . ' OR (cpk.status = :restored_status_credits AND cpk.remaining_credits > 0))'
                    . ' AND cpk.archived_at IS NULL) AS outstanding_credits',
            ])
            ->order('p.ordering ASC, p.title ASC')
            ->bind(':active_status', $activeStatus)
            ->bind(':restored_status', $restoredStatus)
            ->bind(':package_now', $now)
            ->bind(':active_status_credits', $activeStatusCredits)
            ->bind(':restored_status_credits', $restoredStatusCredits)
            ->bind(':package_credit_now', $now);
        $this->applyFilters($query);
        $this->db->setQuery($query, $this->limitStart, $this->limit);
        $this->items = $this->db->loadAssocList() ?: [];
    }

    private function baseQuery(): mixed
    {
        return $this->db->getQuery(true)
            ->from($this->db->quoteName('#__memi_packages', 'p'))
            ->where('p.archived_at IS NULL');
    }

    private function applyFilters(mixed $query): void
    {
        if ($this->filterStatus !== '') {
            $published = $this->filterStatus === 'published' ? 1 : 0;
            $query->where('p.published = :filter_published')
                ->bind(':filter_published', $published);
        }
        if ($this->filterSearch !== '') {
            $term = '%' . $this->filterSearch . '%';
            $query->where('(p.title LIKE :filter_search OR p.alias LIKE :filter_search)')
                ->bind(':filter_search', $term);
        }
    }
}
