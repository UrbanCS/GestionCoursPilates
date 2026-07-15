<?php
/** @var \Memi\Component\Memipilates\Site\View\Waitlistoffer\HtmlView $this */
defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
?>
<section class="memi-booking memi-waitlist-offer">
    <h1><?= Text::_('COM_MEMIPILATES_WAITLIST_OFFER_TITLE'); ?></h1>
    <p><?= Text::_('COM_MEMIPILATES_WAITLIST_OFFER_DESCRIPTION'); ?></p>
    <form action="<?= htmlspecialchars($this->acceptEndpoint, ENT_QUOTES, 'UTF-8'); ?>" method="post" data-memi-booking-form>
        <input type="hidden" name="waitlist_id" value="<?= (int) $this->waitlistId; ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($this->token, ENT_QUOTES, 'UTF-8'); ?>">
        <?= HTMLHelper::_('form.token'); ?>
        <button class="btn btn-primary" type="submit"><?= Text::_('COM_MEMIPILATES_WAITLIST_OFFER_ACCEPT'); ?></button>
        <p data-memi-booking-result role="status"></p>
    </form>
</section>
