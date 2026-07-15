<?php
/** @var \Memi\Component\Memipilates\Site\View\Kiosk\HtmlView $this */
defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$tokenName = \Joomla\CMS\Session\Session::getFormToken();
?>
<section
    class="memi-kiosk"
    data-memi-kiosk
    data-auto-reset-ms="<?= (int) $this->settings['auto_reset_ms']; ?>"
    data-default-mode="<?= htmlspecialchars((string) $this->settings['default_mode'], ENT_QUOTES, 'UTF-8'); ?>"
    data-max-token-length="<?= (int) $this->settings['max_token_length']; ?>"
    data-scan-url="<?= htmlspecialchars($this->scanUrl, ENT_QUOTES, 'UTF-8'); ?>"
    data-manual-url="<?= htmlspecialchars($this->manualUrl, ENT_QUOTES, 'UTF-8'); ?>"
    data-manual-search-url="<?= htmlspecialchars(\Joomla\CMS\Router\Route::_('index.php?option=com_memipilates&task=kiosk.search&format=json', false), ENT_QUOTES, 'UTF-8'); ?>"
    data-csrf-token="<?= htmlspecialchars($tokenName, ENT_QUOTES, 'UTF-8'); ?>"
>
    <header class="memi-kiosk__header">
        <div><h1><?= Text::_('COM_MEMIPILATES_KIOSK_TITLE'); ?></h1><p data-memi-kiosk-clock></p></div>
        <button class="btn btn-outline-secondary" type="button" data-memi-kiosk-fullscreen><?= Text::_('COM_MEMIPILATES_KIOSK_FULLSCREEN'); ?></button>
    </header>
    <label class="memi-kiosk__session-label">
        <?= Text::_('COM_MEMIPILATES_KIOSK_SELECT_SESSION'); ?>
        <select data-memi-kiosk-session aria-label="<?= Text::_('COM_MEMIPILATES_KIOSK_SELECT_SESSION'); ?>">
            <option value=""><?= Text::_('COM_MEMIPILATES_KIOSK_SELECT_SESSION'); ?></option>
            <?php foreach ($this->sessions as $session) : ?>
                <option value="<?= (int) $session['id']; ?>"><?= htmlspecialchars($session['course_title'] . ' · ' . $session['starts_at'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <div class="memi-kiosk__modes" role="tablist" aria-label="<?= Text::_('COM_MEMIPILATES_KIOSK_TITLE'); ?>">
        <button type="button" role="tab" data-memi-kiosk-mode="reader"><?= Text::_('COM_MEMIPILATES_KIOSK_SCAN_PROMPT'); ?></button>
        <button type="button" role="tab" data-memi-kiosk-mode="camera"><?= Text::_('COM_MEMIPILATES_KIOSK_CAMERA_SCAN'); ?></button>
        <button type="button" role="tab" data-memi-kiosk-mode="manual"><?= Text::_('COM_MEMIPILATES_KIOSK_MANUAL_SEARCH'); ?></button>
        <button type="button" role="tab" data-memi-kiosk-mode="test"><?= Text::_('COM_MEMIPILATES_KIOSK_TEST_MODE'); ?></button>
    </div>
    <p class="memi-kiosk__status" data-memi-kiosk-status></p>
    <div class="memi-kiosk__result" data-memi-kiosk-result aria-live="polite"></div>
    <section data-memi-kiosk-pane="reader">
        <label for="memi-scan-input"><?= Text::_('COM_MEMIPILATES_KIOSK_SCAN_INPUT_LABEL'); ?></label>
        <input id="memi-scan-input" data-memi-scan-input inputmode="none" autocomplete="off" aria-describedby="memi-scan-help" autofocus>
        <p id="memi-scan-help"><?= Text::_('COM_MEMIPILATES_KIOSK_SCAN_PROMPT'); ?></p>
    </section>
    <section data-memi-kiosk-pane="camera" hidden>
        <video data-memi-camera-video class="memi-kiosk__camera" aria-label="<?= Text::_('COM_MEMIPILATES_KIOSK_CAMERA_SCAN'); ?>"></video>
        <button type="button" class="btn btn-primary" data-memi-camera-start><?= Text::_('COM_MEMIPILATES_KIOSK_CAMERA_START'); ?></button>
        <button type="button" class="btn btn-outline-secondary" data-memi-camera-stop><?= Text::_('COM_MEMIPILATES_KIOSK_CAMERA_STOP'); ?></button>
    </section>
    <section data-memi-kiosk-pane="manual" hidden>
        <form data-memi-manual-form>
            <label><?= Text::_('COM_MEMIPILATES_KIOSK_MANUAL_SEARCH'); ?><input name="query" type="search" autocomplete="off"></label>
            <button class="btn btn-primary" type="submit"><?= Text::_('JSEARCH_FILTER_SUBMIT'); ?></button>
        </form>
        <div data-memi-manual-results></div>
    </section>
    <section data-memi-kiosk-pane="test" hidden>
        <p><?= Text::_('COM_MEMIPILATES_KIOSK_TEST_NO_WRITE'); ?></p>
        <dl data-memi-test-diagnostics></dl>
    </section>
</section>
