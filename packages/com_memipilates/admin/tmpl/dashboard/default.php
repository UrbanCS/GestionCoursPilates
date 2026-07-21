<?php
/** @var \Memi\Component\Memipilates\Administrator\View\Dashboard\HtmlView $this */
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$link = static fn (string $view): string => $escape(Route::_('index.php?option=com_memipilates&view=' . $view, false));
$metrics = $this->metrics;
?>
<div class="container-fluid memi-admin-dashboard">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="mb-0"><?= $escape($this->label('COM_MEMIPILATES_SUBMENU_DASHBOARD', 'Dashboard')); ?></h1>
        <?php if ($this->can('courses.manage') || $this->can('rooms.manage') || $this->can('schedules.manage')) : ?>
            <a class="btn btn-primary" href="<?= $link('setup'); ?>"><?= $escape($this->label('COM_MEMIPILATES_SUBMENU_SETUP', 'Studio setup')); ?></a>
        <?php endif; ?>
    </div>

    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3 mb-4">
        <div class="col"><div class="card h-100"><div class="card-body"><div class="text-muted small"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_TODAY_SESSIONS', 'Today\'s sessions')); ?></div><div class="fs-2 fw-bold"><?= (int) ($metrics['today_sessions'] ?? 0); ?></div></div></div></div>
        <div class="col"><div class="card h-100"><div class="card-body"><div class="text-muted small"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_TODAY_PARTICIPANTS', 'Today\'s participants')); ?></div><div class="fs-2 fw-bold"><?= (int) ($metrics['today_participants'] ?? 0); ?></div></div></div></div>
        <div class="col"><div class="card h-100"><div class="card-body"><div class="text-muted small"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_AVAILABLE_SEATS', 'Available seats')); ?></div><div class="fs-2 fw-bold"><?= (int) ($metrics['remaining_seats'] ?? 0); ?></div></div></div></div>
        <div class="col"><div class="card h-100"><div class="card-body"><div class="text-muted small"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_ACTIVE_WAITLIST', 'Active waitlist')); ?></div><div class="fs-2 fw-bold"><?= (int) ($metrics['waitlist'] ?? 0); ?></div></div></div></div>
        <div class="col"><div class="card h-100"><div class="card-body"><div class="text-muted small"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_NEW_CUSTOMERS_30_DAYS', 'New customers (30 days)')); ?></div><div class="fs-2 fw-bold"><?= (int) ($metrics['new_customers'] ?? 0); ?></div></div></div></div>
        <div class="col"><div class="card h-100"><div class="card-body"><div class="text-muted small"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_REVENUE_30_DAYS', 'Revenue (30 days)')); ?></div><div class="fs-2 fw-bold"><?= $escape($this->formatMoney((int) ($metrics['revenue_cents'] ?? 0))); ?></div></div></div></div>
        <div class="col"><div class="card h-100"><div class="card-body"><div class="text-muted small"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FAILED_PAYMENTS_30_DAYS', 'Failed payments (30 days)')); ?></div><div class="fs-2 fw-bold"><?= (int) ($metrics['failed_payments'] ?? 0); ?></div></div></div></div>
    </div>

    <div class="row g-4">
        <section class="col-12 col-xl-6" aria-labelledby="memi-upcoming-sessions">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0" id="memi-upcoming-sessions"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_UPCOMING_SESSIONS', 'Upcoming sessions')); ?></h2>
                    <a class="btn btn-sm btn-outline-primary" href="<?= $link('sessions'); ?>"><?= $escape($this->label('COM_MEMIPILATES_SUBMENU_SESSIONS', 'Sessions')); ?></a>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead><tr><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_COURSE', 'Course')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_STARTS_AT', 'Starts')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_AVAILABLE', 'Available')); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($this->upcomingSessions as $session) : ?>
                            <tr>
                                <td><strong><?= $escape($session['course_title'] ?? ''); ?></strong><br><small class="text-muted"><?= $escape(trim((string) (($session['instructor_name'] ?? '') . ' · ' . ($session['room_title'] ?? '')))); ?></small></td>
                                <td><?= $escape($this->formatDate((string) ($session['starts_at'] ?? ''))); ?></td>
                                <td><?= max(0, (int) ($session['capacity'] ?? 0) - (int) ($session['reserved_count'] ?? 0)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($this->upcomingSessions === []) : ?>
                            <tr><td colspan="3" class="text-muted"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_NO_RESULTS', 'No records found.')); ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="col-12 col-xl-6" aria-labelledby="memi-recent-bookings">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0" id="memi-recent-bookings"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_RECENT_BOOKINGS', 'Recent bookings')); ?></h2>
                    <a class="btn btn-sm btn-outline-primary" href="<?= $link('bookings'); ?>"><?= $escape($this->label('COM_MEMIPILATES_SUBMENU_BOOKINGS', 'Bookings')); ?></a>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead><tr><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_CUSTOMER', 'Customer')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_COURSE', 'Course')); ?></th><th><?= $escape(Text::_('JSTATUS')); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($this->recentBookings as $booking) : ?>
                            <tr>
                                <td><?= $escape($booking['customer_name'] ?? ''); ?></td>
                                <td><strong><?= $escape($booking['course_title'] ?? ''); ?></strong><br><small class="text-muted"><?= $escape($this->formatDate((string) ($booking['starts_at'] ?? ''))); ?></small></td>
                                <td><?= $escape($this->statusLabel((string) ($booking['status'] ?? ''))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($this->recentBookings === []) : ?>
                            <tr><td colspan="3" class="text-muted"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_NO_RESULTS', 'No records found.')); ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="col-12" aria-labelledby="memi-waitlist">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0" id="memi-waitlist"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_WAITLIST', 'Waitlist')); ?></h2>
                    <a class="btn btn-sm btn-outline-primary" href="<?= $link('bookings'); ?>"><?= $escape($this->label('COM_MEMIPILATES_SUBMENU_BOOKINGS', 'Bookings')); ?></a>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead><tr><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_CUSTOMER', 'Customer')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_COURSE', 'Course')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_STARTS_AT', 'Starts')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_POSITION', 'Position')); ?></th><th><?= $escape(Text::_('JSTATUS')); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($this->waitlist as $item) : ?>
                            <tr><td><?= $escape($item['customer_name'] ?? ''); ?></td><td><?= $escape($item['course_title'] ?? ''); ?></td><td><?= $escape($this->formatDate((string) ($item['starts_at'] ?? ''))); ?></td><td><?= (int) ($item['position'] ?? 0); ?></td><td><?= $escape($this->statusLabel((string) ($item['status'] ?? ''))); ?></td></tr>
                        <?php endforeach; ?>
                        <?php if ($this->waitlist === []) : ?>
                            <tr><td colspan="5" class="text-muted"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_NO_RESULTS', 'No records found.')); ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>
