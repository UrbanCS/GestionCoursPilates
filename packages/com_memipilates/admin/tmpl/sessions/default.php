<?php
/** @var \Memi\Component\Memipilates\Administrator\View\Sessions\HtmlView $this */
defined('_JEXEC') or die;

use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Language\Text;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$filterUrl = $escape(Route::_('index.php?option=com_memipilates&view=sessions', false));
$postUrl = $filterUrl;
$token = $escape(Session::getFormToken());
?>
<div class="container-fluid memi-admin-sessions">
    <h1><?= $escape($this->label('COM_MEMIPILATES_SUBMENU_SESSIONS', 'Sessions')); ?></h1>
    <form action="<?= $filterUrl; ?>" method="get" class="row g-3 align-items-end mb-3">
        <input type="hidden" name="option" value="com_memipilates">
        <input type="hidden" name="view" value="sessions">
        <div class="col-12 col-md-4"><label class="form-label" for="filter-search"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_SEARCH', 'Search')); ?></label><input class="form-control" id="filter-search" name="filter_search" value="<?= $escape($this->filterSearch); ?>" type="search"></div>
        <div class="col-12 col-md-3"><label class="form-label" for="filter-date"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_DATE', 'Date')); ?></label><input class="form-control" id="filter-date" name="filter_date" value="<?= $escape($this->filterDate); ?>" type="date"></div>
        <div class="col-12 col-md-3"><label class="form-label" for="filter-status"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_STATUS', 'Status')); ?></label><select class="form-select" id="filter-status" name="filter_status"><option value=""><?= $escape(Text::_('JALL')); ?></option><?php foreach ($this->statuses as $status) : ?><option value="<?= $escape($status); ?>"<?= $this->filterStatus === $status ? ' selected' : ''; ?>><?= $escape($this->statusLabel($status)); ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-2 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_APPLY', 'Filter')); ?></button><a class="btn btn-outline-secondary" href="<?= $filterUrl; ?>"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_RESET', 'Reset')); ?></a></div>
    </form>
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle">
            <thead><tr><th><?= $escape(Text::_('JGRID_HEADING_ID')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_COURSE', 'Course')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_STARTS_AT', 'Starts')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_INSTRUCTOR', 'Instructor')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_ROOM', 'Room')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_CAPACITY', 'Capacity')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_AVAILABLE', 'Available')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_WAITLIST', 'Waitlist')); ?></th><th><?= $escape(Text::_('JSTATUS')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_ACTIONS', 'Actions')); ?></th></tr></thead>
            <tbody>
            <?php foreach ($this->items as $item) : ?>
                <tr>
                    <td><?= (int) $item['id']; ?></td><td><strong><?= $escape($item['course_title'] ?? ''); ?></strong><br><small class="text-muted"><?= (int) ($item['credits_required'] ?? 0); ?> <?= $escape($this->label('COM_MEMIPILATES_ADMIN_CREDITS', 'credits')); ?></small></td><td><?= $escape($this->formatDate((string) ($item['starts_at'] ?? ''))); ?><br><small class="text-muted"><?= $escape($this->formatDate((string) ($item['ends_at'] ?? ''))); ?></small></td><td><?= $escape($item['instructor_name'] ?? ''); ?></td><td><?= $escape($item['room_title'] ?? ''); ?></td><td><?= (int) ($item['reserved_count'] ?? 0); ?> / <?= (int) ($item['capacity'] ?? 0); ?></td><td><?= (int) ($item['available_places'] ?? 0); ?></td><td><?= (int) ($item['waitlist_count'] ?? 0); ?></td><td><?= $escape($this->statusLabel((string) ($item['status'] ?? ''))); ?></td>
                    <td><?php if ($this->canOfferWaitlist && (int) ($item['available_places'] ?? 0) > 0 && (int) ($item['waitlist_count'] ?? 0) > 0) : ?><form action="<?= $postUrl; ?>" method="post" class="d-inline"><input type="hidden" name="option" value="com_memipilates"><input type="hidden" name="task" value="display.offerWaitlist"><input type="hidden" name="id" value="<?= (int) $item['id']; ?>"><input type="hidden" name="<?= $token; ?>" value="1"><button class="btn btn-sm btn-outline-primary" type="submit"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_ACTION_OFFER_WAITLIST', 'Offer waitlist place')); ?></button></form><?php endif; ?><?php if ($this->canCancel && !in_array((string) ($item['status'] ?? ''), ['cancelled', 'completed'], true)) : ?><form action="<?= $postUrl; ?>" method="post" class="d-inline"><input type="hidden" name="option" value="com_memipilates"><input type="hidden" name="task" value="display.cancelSession"><input type="hidden" name="id" value="<?= (int) $item['id']; ?>"><input type="hidden" name="<?= $token; ?>" value="1"><button class="btn btn-sm btn-outline-danger" type="submit"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_ACTION_CANCEL_SESSION', 'Cancel session')); ?></button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($this->items === []) : ?><tr><td colspan="10" class="text-muted"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_NO_RESULTS', 'No records found.')); ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($this->paginationLinks() !== '') : ?><nav class="mt-3" aria-label="<?= $escape($this->label('COM_MEMIPILATES_ADMIN_PAGINATION', 'Pagination')); ?>"><?= $this->paginationLinks(); ?></nav><?php endif; ?>
</div>
