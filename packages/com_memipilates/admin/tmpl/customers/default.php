<?php
/** @var \Memi\Component\Memipilates\Administrator\View\Customers\HtmlView $this */
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$filterUrl = $escape(Route::_('index.php?option=com_memipilates&view=customers', false));
?>
<div class="container-fluid memi-admin-customers">
    <h1><?= $escape($this->label('COM_MEMIPILATES_SUBMENU_CUSTOMERS', 'Customers')); ?></h1>
    <form action="<?= $filterUrl; ?>" method="get" class="row g-3 align-items-end mb-3"><input type="hidden" name="option" value="com_memipilates"><input type="hidden" name="view" value="customers"><div class="col-12 col-md-6"><label class="form-label" for="filter-search"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_SEARCH', 'Search')); ?></label><input class="form-control" id="filter-search" name="filter_search" value="<?= $escape($this->filterSearch); ?>" type="search"></div><div class="col-12 col-md-6 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_APPLY', 'Filter')); ?></button><a class="btn btn-outline-secondary" href="<?= $filterUrl; ?>"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_RESET', 'Reset')); ?></a></div></form>
    <div class="table-responsive"><table class="table table-striped table-hover align-middle"><thead><tr><th><?= $escape(Text::_('JGRID_HEADING_ID')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_CUSTOMER', 'Customer')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_PHONE', 'Phone')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_CREDIT_BALANCE', 'Credit balance')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_POINT_BALANCE', 'Point balance')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_ACTIVE_PACKAGES', 'Active packages')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_JOINED_AT', 'Joined')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_ACCOUNT_STATUS', 'Account status')); ?></th></tr></thead><tbody>
        <?php foreach ($this->items as $item) : ?>
            <tr><td><?= (int) $item['user_id']; ?></td><td><strong><?= $escape($item['name'] ?? ''); ?></strong><br><small><?= $escape($item['email'] ?? ''); ?></small></td><td><?= $escape($item['phone'] ?? ''); ?></td><td><?= (int) ($item['credit_balance'] ?? 0); ?></td><td><?= (int) ($item['point_balance'] ?? 0); ?></td><td><?= (int) ($item['active_packages'] ?? 0); ?></td><td><?= $escape($this->formatDate((string) ($item['joined_at'] ?? ''))); ?></td><td><?= $escape((int) ($item['block'] ?? 0) === 1 ? $this->label('COM_MEMIPILATES_ADMIN_BLOCKED', 'Blocked') : $this->label('COM_MEMIPILATES_ADMIN_ACTIVE', 'Active')); ?></td></tr>
        <?php endforeach; ?>
        <?php if ($this->items === []) : ?><tr><td colspan="8" class="text-muted"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_NO_RESULTS', 'No records found.')); ?></td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($this->paginationLinks() !== '') : ?><nav class="mt-3" aria-label="<?= $escape($this->label('COM_MEMIPILATES_ADMIN_PAGINATION', 'Pagination')); ?>"><?= $this->paginationLinks(); ?></nav><?php endif; ?>
</div>
