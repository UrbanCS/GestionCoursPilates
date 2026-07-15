<?php
/** @var \Memi\Component\Memipilates\Administrator\View\Packages\HtmlView $this */
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$filterUrl = $escape(Route::_('index.php?option=com_memipilates&view=packages', false));
?>
<div class="container-fluid memi-admin-packages">
    <h1><?= $escape($this->label('COM_MEMIPILATES_SUBMENU_PACKAGES', 'Packages')); ?></h1>
    <form action="<?= $filterUrl; ?>" method="get" class="row g-3 align-items-end mb-3"><input type="hidden" name="option" value="com_memipilates"><input type="hidden" name="view" value="packages"><div class="col-12 col-md-5"><label class="form-label" for="filter-search"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_SEARCH', 'Search')); ?></label><input class="form-control" id="filter-search" name="filter_search" value="<?= $escape($this->filterSearch); ?>" type="search"></div><div class="col-12 col-md-3"><label class="form-label" for="filter-status"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_STATUS', 'Status')); ?></label><select class="form-select" id="filter-status" name="filter_status"><option value=""><?= $escape(Text::_('JALL')); ?></option><?php foreach ($this->statuses as $status) : ?><option value="<?= $escape($status); ?>"<?= $this->filterStatus === $status ? ' selected' : ''; ?>><?= $escape($this->label($status === 'published' ? 'JYES' : 'JNO', $status === 'published' ? 'Published' : 'Unpublished')); ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-4 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_APPLY', 'Filter')); ?></button><a class="btn btn-outline-secondary" href="<?= $filterUrl; ?>"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_RESET', 'Reset')); ?></a></div></form>
    <div class="table-responsive"><table class="table table-striped table-hover align-middle"><thead><tr><th><?= $escape(Text::_('JGRID_HEADING_ID')); ?></th><th><?= $escape(Text::_('JGLOBAL_TITLE')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_PRICE', 'Price')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_CREDITS', 'Credits')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_VALIDITY', 'Validity')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_ACTIVE_CUSTOMERS', 'Active customers')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_OUTSTANDING_CREDITS', 'Outstanding credits')); ?></th><th><?= $escape(Text::_('JSTATUS')); ?></th></tr></thead><tbody>
        <?php foreach ($this->items as $item) : ?>
            <tr><td><?= (int) $item['id']; ?></td><td><strong><?= $escape($item['title'] ?? ''); ?></strong><br><small class="text-muted"><?= $escape($item['alias'] ?? ''); ?></small></td><td><?= $escape($this->formatMoney((int) ($item['price_cents'] ?? 0))); ?></td><td><?= (int) ($item['credits'] ?? 0); ?></td><td><?php if ((int) ($item['validity_days'] ?? 0) > 0) : ?><?= (int) $item['validity_days']; ?> <?= $escape($this->label('COM_MEMIPILATES_ADMIN_DAYS', 'days')); ?><?php elseif (($item['fixed_expiry_at'] ?? '') !== '') : ?><?= $escape($this->formatDate((string) $item['fixed_expiry_at'])); ?><?php else : ?>—<?php endif; ?></td><td><?= (int) ($item['active_customers'] ?? 0); ?></td><td><?= (int) ($item['outstanding_credits'] ?? 0); ?></td><td><?= $escape((int) ($item['published'] ?? 0) === 1 ? $this->label('JYES', 'Published') : $this->label('JNO', 'Unpublished')); ?></td></tr>
        <?php endforeach; ?>
        <?php if ($this->items === []) : ?><tr><td colspan="8" class="text-muted"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_NO_RESULTS', 'No records found.')); ?></td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($this->paginationLinks() !== '') : ?><nav class="mt-3" aria-label="<?= $escape($this->label('COM_MEMIPILATES_ADMIN_PAGINATION', 'Pagination')); ?>"><?= $this->paginationLinks(); ?></nav><?php endif; ?>
</div>
