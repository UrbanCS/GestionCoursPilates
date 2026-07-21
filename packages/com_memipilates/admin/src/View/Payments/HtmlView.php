<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\View\Payments;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Memi\Component\Memipilates\Administrator\View\AbstractAdminView;

/** Read-only, privacy-aware list of online orders and Square payment states. */
final class HtmlView extends AbstractAdminView
{
    /** @var list<array<string,mixed>> */
    public array $items = [];
    /** @var list<string> */
    public array $statuses = ['pending', 'payment_processing', 'payment_failed', 'paid'];

    public function display($tpl = null): void
    {
        $this->initialise(['core.manage', 'payments.view']);
        $this->filterStatus = $this->normaliseStatus(Factory::getApplication()->input->getCmd('filter_status', ''), $this->statuses);
        $this->loadItems();
        Factory::getApplication()->getDocument()->setTitle('Paiements');
        parent::display($tpl);
    }

    private function loadItems(): void
    {
        $count = $this->baseQuery()->select('COUNT(*)');
        $this->applyFilters($count);
        $this->db->setQuery($count);
        $this->setPagination((int) $this->db->loadResult());

        $query = $this->baseQuery()
            ->select([
                'o.id', 'o.status', 'o.currency', 'o.subtotal_cents', 'o.discount_cents', 'o.tax_cents', 'o.total_cents', 'o.created_at', 'o.paid_at', 'o.promotion_code',
                'u.name AS customer_name', 'u.email AS customer_email',
                'p.provider', 'p.status AS payment_status', 'p.provider_payment_id', 'p.receipt_url', 'p.card_brand', 'p.card_last4',
            ])
            ->order('o.created_at DESC, o.id DESC');
        $this->applyFilters($query);
        $this->db->setQuery($query, $this->limitStart, $this->limit);
        $this->items = $this->db->loadAssocList() ?: [];
    }

    private function baseQuery(): mixed
    {
        return $this->db->getQuery(true)
            ->from($this->db->quoteName('#__memi_orders', 'o'))
            ->join('INNER', $this->db->quoteName('#__users', 'u') . ' ON u.id = o.user_id')
            ->leftJoin($this->db->quoteName('#__memi_payments', 'p') . ' ON p.id = (SELECT p2.id FROM #__memi_payments AS p2 WHERE p2.order_id = o.id ORDER BY p2.id DESC LIMIT 1)');
    }

    private function applyFilters(mixed $query): void
    {
        if ($this->filterStatus !== '') {
            $status = $this->filterStatus;
            $query->where('o.status = :filter_status')->bind(':filter_status', $status);
        }
        if ($this->filterSearch !== '') {
            $term = '%' . $this->filterSearch . '%';
            $query->where('(u.name LIKE :filter_search OR u.email LIKE :filter_search OR o.id = :filter_order_id)')
                ->bind(':filter_search', $term)
                ->bind(':filter_order_id', ctype_digit($this->filterSearch) ? (int) $this->filterSearch : -1);
        }
    }
}
