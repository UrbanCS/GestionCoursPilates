<?php
/** @var \Memi\Component\Memipilates\Administrator\View\Bookings\HtmlView $this */
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$filterUrl = $escape(Route::_('index.php?option=com_memipilates&view=bookings', false));
$token = $escape(Session::getFormToken());
?>
<div class="container-fluid memi-admin-bookings">
    <h1><?= $escape($this->label('COM_MEMIPILATES_SUBMENU_BOOKINGS', 'Bookings')); ?></h1>
    <form action="<?= $filterUrl; ?>" method="get" class="row g-3 align-items-end mb-3">
        <input type="hidden" name="option" value="com_memipilates"><input type="hidden" name="view" value="bookings">
        <div class="col-12 col-md-4"><label class="form-label" for="filter-search"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_SEARCH', 'Search')); ?></label><input class="form-control" id="filter-search" name="filter_search" value="<?= $escape($this->filterSearch); ?>" type="search"></div>
        <div class="col-12 col-md-3"><label class="form-label" for="filter-date"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_DATE', 'Date')); ?></label><input class="form-control" id="filter-date" name="filter_date" value="<?= $escape($this->filterDate); ?>" type="date"></div>
        <div class="col-12 col-md-3"><label class="form-label" for="filter-status"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_STATUS', 'Status')); ?></label><select class="form-select" id="filter-status" name="filter_status"><option value=""><?= $escape(Text::_('JALL')); ?></option><?php foreach ($this->statuses as $status) : ?><option value="<?= $escape($status); ?>"<?= $this->filterStatus === $status ? ' selected' : ''; ?>><?= $escape($this->statusLabel($status)); ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-2 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_APPLY', 'Filter')); ?></button><a class="btn btn-outline-secondary" href="<?= $filterUrl; ?>"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_RESET', 'Reset')); ?></a></div>
    </form>
    <div class="table-responsive"><table class="table table-striped table-hover align-middle"><thead><tr><th><?= $escape(Text::_('JGRID_HEADING_ID')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_CUSTOMER', 'Customer')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_SESSION', 'Session')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_BOOKED_AT', 'Booked')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_SOURCE', 'Source')); ?></th><th><?= $escape(Text::_('JSTATUS')); ?></th><th><?= $escape(Text::_('JGLOBAL_ACTIONS')); ?></th></tr></thead><tbody>
        <?php foreach ($this->items as $item) : ?>
            <tr><td><?= (int) $item['id']; ?></td><td><strong><?= $escape($item['customer_name'] ?? ''); ?></strong><br><a href="mailto:<?= $escape($item['customer_email'] ?? ''); ?>"><?= $escape($item['customer_email'] ?? ''); ?></a><?php if (($item['customer_phone'] ?? '') !== '') : ?><br><small><?= $escape($item['customer_phone']); ?></small><?php endif; ?></td><td><strong><?= $escape($item['course_title'] ?? ''); ?></strong><br><small class="text-muted"><?= $escape($this->formatDate((string) ($item['starts_at'] ?? ''))); ?></small></td><td><?= $escape($this->formatDate((string) ($item['booked_at'] ?? ''))); ?></td><td><?= $escape($item['source'] ?? ''); ?></td><td><?= $escape($this->statusLabel((string) ($item['status'] ?? ''))); ?></td><td><?php if ($this->canCancel && in_array((string) ($item['status'] ?? ''), ['pending', 'confirmed'], true)) : ?><form action="<?= $filterUrl; ?>" method="post" class="d-inline"><input type="hidden" name="option" value="com_memipilates"><input type="hidden" name="task" value="display.cancelBooking"><input type="hidden" name="id" value="<?= (int) $item['id']; ?>"><input type="hidden" name="<?= $token; ?>" value="1"><button class="btn btn-sm btn-outline-danger" type="submit"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_ACTION_CANCEL_BOOKING', 'Cancel booking')); ?></button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if ($this->items === []) : ?><tr><td colspan="7" class="text-muted"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_NO_RESULTS', 'No records found.')); ?></td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($this->paginationLinks() !== '') : ?><nav class="mt-3" aria-label="<?= $escape($this->label('COM_MEMIPILATES_ADMIN_PAGINATION', 'Pagination')); ?>"><?= $this->paginationLinks(); ?></nav><?php endif; ?>
</div>
