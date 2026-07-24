<?php
/** @var \Memi\Component\Memipilates\Site\View\Dashboard\HtmlView $this */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\HTML\HTMLHelper;
?>
<section
    class="memi-dashboard"
    data-memi-client-dashboard
    data-cancel-url="<?= htmlspecialchars($this->cancelEndpoint, ENT_QUOTES, 'UTF-8'); ?>"
    data-leave-waitlist-url="<?= htmlspecialchars($this->leaveWaitlistEndpoint, ENT_QUOTES, 'UTF-8'); ?>"
>
    <div hidden aria-hidden="true"><?= HTMLHelper::_('form.token'); ?></div>
    <header class="memi-dashboard__header">
        <h1><?= Text::_('COM_MEMIPILATES_ACCOUNT'); ?></h1>
        <nav class="memi-dashboard__header-actions" aria-label="<?= Text::_('COM_MEMIPILATES_ACCOUNT'); ?>">
            <?php if ($this->canManageStudio) : ?>
                <a class="btn btn-outline-secondary" href="<?= Route::_('index.php?option=com_memipilates&view=' . rawurlencode($this->managementLandingView)); ?>">
                    <?= Text::_('COM_MEMIPILATES_PORTAL_OPEN'); ?>
                </a>
            <?php endif; ?>
            <a class="btn btn-outline-primary" href="<?= Route::_('index.php?option=com_memipilates&view=checkout'); ?>">
                <?= Text::_('COM_MEMIPILATES_BOOKING_BUY_PACKAGE'); ?>
            </a>
            <a class="btn btn-primary" href="<?= Route::_('index.php?option=com_memipilates&view=schedule'); ?>">
                <?= Text::_('COM_MEMIPILATES_SCHEDULE_BOOK'); ?>
            </a>
        </nav>
    </header>

    <div class="memi-dashboard__metrics">
        <article><h2><?= Text::_('COM_MEMIPILATES_ACCOUNT_CREDITS'); ?></h2><p><?= (int) $this->creditBalance; ?></p></article>
        <article><h2><?= Text::_('COM_MEMIPILATES_ACCOUNT_POINTS'); ?></h2><p><?= (int) $this->pointBalance; ?></p></article>
    </div>

    <section>
        <h2><?= Text::_('COM_MEMIPILATES_ACCOUNT_UPCOMING'); ?></h2>
        <ul class="memi-dashboard__list">
            <?php foreach ($this->upcoming as $booking) : ?>
                <li>
                    <strong><?= htmlspecialchars((string) $booking['course_title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    — <?= htmlspecialchars($this->formatDate((string) $booking['starts_at']), ENT_QUOTES, 'UTF-8'); ?>
                    <span><?= htmlspecialchars($this->statusLabel((string) $booking['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if (in_array((string) $booking['status'], ['confirmed', 'pending'], true)) : ?>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-memi-cancel-booking data-booking-id="<?= (int) $booking['id']; ?>">
                            <?= Text::_('COM_MEMIPILATES_BOOKING_CANCEL'); ?>
                        </button>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section>
        <h2><?= Text::_('COM_MEMIPILATES_ACCOUNT_PACKAGES'); ?></h2>
        <ul class="memi-dashboard__list">
            <?php foreach ($this->activePackages as $package) : ?>
                <li>
                    <strong><?= htmlspecialchars((string) $package['package_title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    — <?= (int) $package['remaining_credits']; ?> <?= Text::_('COM_MEMIPILATES_ACCOUNT_CREDITS'); ?>
                    <?php if ((string) $package['status'] === 'restored') : ?>
                        — <?= Text::_('COM_MEMIPILATES_ACCOUNT_RESTORED_CREDIT'); ?>
                    <?php elseif (!empty($package['expires_at'])) : ?>
                        — <?= Text::sprintf('COM_MEMIPILATES_ACCOUNT_EXPIRES_AT', htmlspecialchars($this->formatDate((string) $package['expires_at']), ENT_QUOTES, 'UTF-8')); ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section>
        <h2><?= Text::_('COM_MEMIPILATES_ACCOUNT_WAITLIST'); ?></h2>
        <ul class="memi-dashboard__list">
            <?php foreach ($this->waitlist as $entry) : ?>
                <li>
                    <strong><?= htmlspecialchars((string) $entry['course_title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    — <?= htmlspecialchars($this->formatDate((string) $entry['starts_at']), ENT_QUOTES, 'UTF-8'); ?>
                    — <?= Text::sprintf('COM_MEMIPILATES_BOOKING_WAITLIST_POSITION', (int) $entry['position']); ?>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-memi-leave-waitlist data-waitlist-id="<?= (int) $entry['id']; ?>">
                        <?= Text::_('COM_MEMIPILATES_WAITLIST_LEAVE'); ?>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section
        class="memi-dashboard__qr-card"
        data-memi-qr-dashboard
        data-qr-endpoint="<?= htmlspecialchars($this->qrEndpoint, ENT_QUOTES, 'UTF-8'); ?>"
        data-qr-token="<?= htmlspecialchars((string) ($this->qrToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
    >
        <div class="memi-qr-print-only">
            <p class="memi-qr-print-brand">Memi Studio</p>
            <h2><?= Text::_('COM_MEMIPILATES_QR_PRINT_TITLE'); ?></h2>
            <p><?= Text::_('COM_MEMIPILATES_QR_PRINT_INSTRUCTIONS'); ?></p>
        </div>
        <h2><?= Text::_('COM_MEMIPILATES_ACCOUNT_QR_CODE'); ?></h2>
        <p><?= Text::_('COM_MEMIPILATES_QR_PRIVACY_NOTICE'); ?></p>
        <div class="memi-qr" data-memi-qr-image aria-label="<?= Text::_('COM_MEMIPILATES_ACCOUNT_QR_CODE'); ?>"></div>
        <p data-memi-qr-result role="status"></p>
        <div data-memi-qr-controls>
            <button type="button" class="btn btn-primary" data-memi-qr-generate><?= Text::_('COM_MEMIPILATES_ACCOUNT_REGENERATE_QR'); ?></button>
            <button type="button" class="btn btn-outline-secondary" data-memi-qr-print disabled><?= Text::_('COM_MEMIPILATES_QR_PRINT'); ?></button>
        </div>
    </section>

    <section data-memi-loyalty data-loyalty-endpoint="<?= htmlspecialchars($this->loyaltyEndpoint, ENT_QUOTES, 'UTF-8'); ?>">
        <h2><?= Text::_('COM_MEMIPILATES_ACCOUNT_REWARDS'); ?></h2>
        <ul class="memi-dashboard__list">
            <?php foreach ($this->rewards as $reward) : ?>
                <?php $canRedeem = (int) $reward['points_cost'] <= $this->pointBalance; ?>
                <li>
                    <strong><?= htmlspecialchars((string) $reward['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    — <?= Text::sprintf('COM_MEMIPILATES_LOYALTY_POINTS_COST', (int) $reward['points_cost']); ?>
                    <?php if (!empty($reward['description'])) : ?>
                        <div><?= htmlspecialchars((string) $reward['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <button
                        class="btn btn-sm btn-outline-primary"
                        type="button"
                        data-memi-redeem-reward
                        data-reward-id="<?= (int) $reward['id']; ?>"
                        <?= $canRedeem ? '' : 'disabled'; ?>
                    ><?= Text::_('COM_MEMIPILATES_LOYALTY_REDEEM'); ?></button>
                </li>
            <?php endforeach; ?>
        </ul>
        <p data-memi-loyalty-result role="status"></p>
    </section>

    <section>
        <h2><?= Text::_('COM_MEMIPILATES_ACCOUNT_POINT_HISTORY'); ?></h2>
        <ul class="memi-dashboard__list">
            <?php foreach ($this->pointHistory as $entry) : ?>
                <li>
                    <strong><?= (int) $entry['points_delta']; ?></strong>
                    — <?= htmlspecialchars((string) $entry['description'], ENT_QUOTES, 'UTF-8'); ?>
                    — <?= htmlspecialchars($this->formatDate((string) $entry['created_at']), ENT_QUOTES, 'UTF-8'); ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section>
        <h2><?= Text::_('COM_MEMIPILATES_ACCOUNT_HISTORY'); ?></h2>
        <ul class="memi-dashboard__list">
            <?php foreach ($this->history as $booking) : ?>
                <li>
                    <strong><?= htmlspecialchars((string) $booking['course_title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    — <?= htmlspecialchars($this->formatDate((string) $booking['starts_at']), ENT_QUOTES, 'UTF-8'); ?>
                    <span><?= htmlspecialchars($this->statusLabel((string) $booking['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section>
        <h2><?= Text::_('COM_MEMIPILATES_ACCOUNT_PAYMENTS'); ?></h2>
        <ul class="memi-dashboard__list">
            <?php foreach ($this->payments as $payment) : ?>
                <li>
                    <?= htmlspecialchars((string) ($payment['order_number'] ?: ('#' . $payment['order_id'])), ENT_QUOTES, 'UTF-8'); ?>
                    — <?= htmlspecialchars($this->formatMoney((int) $payment['amount_cents'], (string) $payment['currency']), ENT_QUOTES, 'UTF-8'); ?>
                    <?php if (!empty($payment['receipt_url'])) : ?>
                        <a href="<?= htmlspecialchars((string) $payment['receipt_url'], ENT_QUOTES, 'UTF-8'); ?>" rel="noopener" target="_blank"><?= Text::_('COM_MEMIPILATES_PAYMENT_RECEIPT'); ?></a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <p data-memi-dashboard-result role="status"></p>
</section>
