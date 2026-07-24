<?php
/** @var \Memi\Component\Memipilates\Site\View\Booking\HtmlView $this */
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\HTML\HTMLHelper;
$remaining = max(0, (int) $this->session['capacity'] - (int) $this->session['reserved_count']);
$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$startsUtc = new DateTimeImmutable((string) $this->session['starts_at'], new DateTimeZone('UTC'));
$registrationNotOpen = !empty($this->session['registration_opens_at'])
    && $nowUtc < new DateTimeImmutable((string) $this->session['registration_opens_at'], new DateTimeZone('UTC'));
$registrationClosed = $nowUtc >= $startsUtc
    || (!empty($this->session['registration_closes_at'])
        && $nowUtc >= new DateTimeImmutable((string) $this->session['registration_closes_at'], new DateTimeZone('UTC')));
$directPaymentAvailable = (int) $this->session['price_cents'] > 0;
$directPaymentTotal = (int) $this->session['price_cents']
    + (int) round((int) $this->session['price_cents'] * (int) $this->session['tax_rate_basis_points'] / 10000);
?>
<section class="memi-booking">
    <a href="<?= Route::_('index.php?option=com_memipilates&view=schedule'); ?>"><?= Text::_('COM_MEMIPILATES_SCHEDULE'); ?></a>
    <h1><?= htmlspecialchars($this->session['course_title'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <p><?= htmlspecialchars($this->formatDate((string) $this->session['starts_at']), ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars($this->session['instructor_name'] ?: '', ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars($this->session['room_title'] ?: '', ENT_QUOTES, 'UTF-8'); ?></p>
    <p><?= htmlspecialchars($this->session['description'] ?: '', ENT_QUOTES, 'UTF-8'); ?></p>
    <?php if ($this->userId <= 0) : ?>
        <p><?= Text::_('COM_MEMIPILATES_BOOKING_LOGIN_REQUIRED'); ?></p>
        <a class="btn btn-primary" href="<?= Route::_('index.php?option=com_users&view=login'); ?>"><?= Text::_('JLOGIN'); ?></a>
    <?php elseif ($this->session['status'] === 'cancelled') : ?>
        <p><?= Text::_('COM_MEMIPILATES_SCHEDULE_CANCELLED'); ?></p>
    <?php elseif ($registrationNotOpen || $registrationClosed) : ?>
        <p><?= Text::_($registrationNotOpen ? 'COM_MEMIPILATES_SCHEDULE_REGISTRATION_NOT_OPEN' : 'COM_MEMIPILATES_SCHEDULE_REGISTRATION_CLOSED'); ?></p>
    <?php elseif ($remaining <= 0) : ?>
        <form action="<?= htmlspecialchars($this->waitlistEndpoint, ENT_QUOTES, 'UTF-8'); ?>" method="post" data-memi-booking-form>
            <input type="hidden" name="session_id" value="<?= (int) $this->session['id']; ?>">
            <?= HTMLHelper::_('form.token'); ?>
            <button class="btn btn-outline-primary" type="submit"><?= Text::_('COM_MEMIPILATES_SCHEDULE_JOIN_WAITLIST'); ?></button>
            <p data-memi-booking-result role="status"></p>
        </form>
    <?php elseif ($this->creditBalance >= (int) $this->session['credits_required']) : ?>
        <form action="<?= htmlspecialchars($this->reserveEndpoint, ENT_QUOTES, 'UTF-8'); ?>" method="post" data-memi-booking-form>
            <input type="hidden" name="session_id" value="<?= (int) $this->session['id']; ?>">
            <input type="hidden" name="use_credit" value="1">
            <?= HTMLHelper::_('form.token'); ?>
            <p><?= Text::sprintf('COM_MEMIPILATES_BOOKING_CREDIT_BALANCE', $this->creditBalance); ?></p>
            <button class="btn btn-primary" type="submit"><?= Text::_('COM_MEMIPILATES_BOOKING_CONFIRM'); ?></button>
            <?php if ($directPaymentAvailable) : ?>
                <a class="btn btn-outline-primary" href="<?= htmlspecialchars($this->checkoutUrl, ENT_QUOTES, 'UTF-8'); ?>"><?= Text::sprintf('COM_MEMIPILATES_BOOKING_PAY_DIRECT', number_format($directPaymentTotal / 100, 2), $this->currency); ?></a>
            <?php endif; ?>
            <p data-memi-booking-result role="status"></p>
        </form>
    <?php else : ?>
        <p><?= Text::_('COM_MEMIPILATES_ERROR_INSUFFICIENT_CREDITS'); ?></p>
        <?php if ($directPaymentAvailable) : ?>
            <a class="btn btn-primary" href="<?= htmlspecialchars($this->checkoutUrl, ENT_QUOTES, 'UTF-8'); ?>"><?= Text::sprintf('COM_MEMIPILATES_BOOKING_PAY_DIRECT', number_format($directPaymentTotal / 100, 2), $this->currency); ?></a>
            <a class="btn btn-outline-primary" href="<?= Route::_('index.php?option=com_memipilates&view=checkout'); ?>"><?= Text::_('COM_MEMIPILATES_BOOKING_BUY_PACKAGE'); ?></a>
        <?php else : ?>
            <a class="btn btn-primary" href="<?= Route::_('index.php?option=com_memipilates&view=checkout'); ?>"><?= Text::_('COM_MEMIPILATES_BOOKING_BUY_PACKAGE'); ?></a>
        <?php endif; ?>
    <?php endif; ?>
</section>
