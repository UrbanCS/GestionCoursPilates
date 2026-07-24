<?php
/** @var \Memi\Component\Memipilates\Site\View\Checkout\HtmlView $this */
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
$sessionTotal = $this->session
    ? (int) $this->session['price_cents']
        + (int) round((int) $this->session['price_cents'] * (int) $this->session['tax_rate_basis_points'] / 10000)
    : 0;
?>
<section
    class="memi-checkout"
    data-memi-checkout
    data-create-url="<?= htmlspecialchars($this->createEndpoint, ENT_QUOTES, 'UTF-8'); ?>"
    data-pay-url="<?= htmlspecialchars($this->payEndpoint, ENT_QUOTES, 'UTF-8'); ?>"
    data-square-environment="<?= htmlspecialchars($this->square['environment'], ENT_QUOTES, 'UTF-8'); ?>"
    data-session-id="<?= $this->sessionId; ?>"
    data-buyer-given-name="<?= htmlspecialchars($this->buyerGivenName, ENT_QUOTES, 'UTF-8'); ?>"
    data-buyer-family-name="<?= htmlspecialchars($this->buyerFamilyName, ENT_QUOTES, 'UTF-8'); ?>"
    data-buyer-email="<?= htmlspecialchars($this->buyerEmail, ENT_QUOTES, 'UTF-8'); ?>"
    data-message-select-package="<?= htmlspecialchars(Text::_('COM_MEMIPILATES_PAYMENT_SELECT_PACKAGE'), ENT_QUOTES, 'UTF-8'); ?>"
    data-message-preparing="<?= htmlspecialchars(Text::_('COM_MEMIPILATES_PAYMENT_PREPARING'), ENT_QUOTES, 'UTF-8'); ?>"
    data-message-card-ready="<?= htmlspecialchars(Text::_('COM_MEMIPILATES_PAYMENT_CARD_READY'), ENT_QUOTES, 'UTF-8'); ?>"
    data-message-processing="<?= htmlspecialchars(Text::_('COM_MEMIPILATES_PAYMENT_PROCESSING'), ENT_QUOTES, 'UTF-8'); ?>"
    data-message-reconciling="<?= htmlspecialchars(Text::_('COM_MEMIPILATES_PAYMENT_RECONCILING'), ENT_QUOTES, 'UTF-8'); ?>"
    data-message-config="<?= htmlspecialchars(Text::_('COM_MEMIPILATES_ERROR_SQUARE_NOT_CONFIGURED'), ENT_QUOTES, 'UTF-8'); ?>"
    data-message-success="<?= htmlspecialchars(Text::_($this->session ? 'COM_MEMIPILATES_PAYMENT_SESSION_SUCCESS' : 'COM_MEMIPILATES_PAYMENT_PACKAGE_SUCCESS'), ENT_QUOTES, 'UTF-8'); ?>"
    data-message-failed="<?= htmlspecialchars(Text::_('COM_MEMIPILATES_PAYMENT_FAILED_CLIENT'), ENT_QUOTES, 'UTF-8'); ?>"
>
    <div hidden aria-hidden="true"><?= HTMLHelper::_('form.token'); ?></div>
    <header class="memi-checkout__header">
        <h1><?= Text::_('COM_MEMIPILATES_PAYMENT'); ?></h1>
        <nav class="memi-checkout__header-actions" aria-label="<?= Text::_('COM_MEMIPILATES_PAYMENT'); ?>">
            <a class="btn btn-outline-primary" href="<?= Route::_('index.php?option=com_memipilates&view=dashboard'); ?>"><?= Text::_('COM_MEMIPILATES_ACCOUNT'); ?></a>
            <a class="btn btn-outline-primary" href="<?= Route::_('index.php?option=com_memipilates&view=schedule'); ?>"><?= Text::_('COM_MEMIPILATES_SCHEDULE'); ?></a>
        </nav>
    </header>
    <?php if ($this->session) : ?>
        <article class="memi-checkout__session">
            <h2><?= htmlspecialchars((string) $this->session['course_title'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <p>
                <?= htmlspecialchars($this->formatDate((string) $this->session['starts_at']), ENT_QUOTES, 'UTF-8'); ?>
                <?php if ((string) ($this->session['instructor_name'] ?? '') !== '') : ?>
                    · <?= htmlspecialchars((string) $this->session['instructor_name'], ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
                <?php if ((string) ($this->session['room_title'] ?? '') !== '') : ?>
                    · <?= htmlspecialchars((string) $this->session['room_title'], ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
            </p>
            <p><strong><?= Text::_('COM_MEMIPILATES_PAYMENT_TOTAL'); ?>:</strong> <?= number_format($sessionTotal / 100, 2); ?> <?= htmlspecialchars($this->currency, ENT_QUOTES, 'UTF-8'); ?></p>
            <p><?= Text::_('COM_MEMIPILATES_PAYMENT_SESSION_HOLD_NOTICE'); ?></p>
        </article>
    <?php else : ?>
        <label><?= Text::_('COM_MEMIPILATES_ACCOUNT_PACKAGES'); ?>
            <select data-memi-package-select>
                <option value=""><?= Text::_('COM_MEMIPILATES_SELECT'); ?></option>
                <?php foreach ($this->packages as $package) : ?>
                    <option value="<?= (int) $package['id']; ?>"><?= htmlspecialchars($package['title'], ENT_QUOTES, 'UTF-8'); ?> — <?= number_format((int) $package['price_cents'] / 100, 2); ?> <?= htmlspecialchars($this->currency, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?= Text::_('COM_MEMIPILATES_BOOKING_PROMO_CODE'); ?><input type="text" name="promotion_code" maxlength="64" autocomplete="off"></label>
    <?php endif; ?>
    <button class="btn btn-primary" type="button" data-memi-payment-start><?= Text::_('COM_MEMIPILATES_PAYMENT_PAY_NOW'); ?></button>
    <div data-memi-square-card></div>
    <button class="btn btn-primary" type="button" data-memi-payment-submit hidden><?= Text::_('COM_MEMIPILATES_PAYMENT_PAY_NOW'); ?></button>
    <p data-memi-checkout-status role="status"></p>
</section>
