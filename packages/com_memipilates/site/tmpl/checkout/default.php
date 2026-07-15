<?php
/** @var \Memi\Component\Memipilates\Site\View\Checkout\HtmlView $this */
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;
?>
<section class="memi-checkout" data-memi-checkout data-create-url="<?= htmlspecialchars($this->createEndpoint, ENT_QUOTES, 'UTF-8'); ?>" data-pay-url="<?= htmlspecialchars($this->payEndpoint, ENT_QUOTES, 'UTF-8'); ?>" data-square-environment="<?= htmlspecialchars($this->square['environment'], ENT_QUOTES, 'UTF-8'); ?>">
    <div hidden aria-hidden="true"><?= HTMLHelper::_('form.token'); ?></div>
    <h1><?= Text::_('COM_MEMIPILATES_PAYMENT'); ?></h1>
    <label><?= Text::_('COM_MEMIPILATES_ACCOUNT_PACKAGES'); ?>
        <select data-memi-package-select>
            <option value=""><?= Text::_('JOPTION_SELECT'); ?></option>
            <?php foreach ($this->packages as $package) : ?>
                <option value="<?= (int) $package['id']; ?>"><?= htmlspecialchars($package['title'], ENT_QUOTES, 'UTF-8'); ?> — <?= number_format((int) $package['price_cents'] / 100, 2); ?> CAD</option>
            <?php endforeach; ?>
        </select>
    </label>
    <label><?= Text::_('COM_MEMIPILATES_BOOKING_PROMO_CODE'); ?><input type="text" name="promotion_code" maxlength="64" autocomplete="off"></label>
    <button class="btn btn-primary" type="button" data-memi-payment-start><?= Text::_('COM_MEMIPILATES_PAYMENT_PAY_NOW'); ?></button>
    <div data-memi-square-card></div>
    <button class="btn btn-primary" type="button" data-memi-payment-submit hidden><?= Text::_('COM_MEMIPILATES_PAYMENT_PAY_NOW'); ?></button>
    <p data-memi-checkout-status role="status"></p>
</section>
