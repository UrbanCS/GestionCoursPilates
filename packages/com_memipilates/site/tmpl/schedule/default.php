<?php
/** @var \Memi\Component\Memipilates\Site\View\Schedule\HtmlView $this */
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\HTML\HTMLHelper;

$tz = \Memi\Component\Memipilates\Administrator\Service\ComponentServices::settings()->timezone();
$date = new DateTimeImmutable($this->startDate, $tz);
$previous = $date->modify($this->viewMode === 'week' ? '-7 days' : '-1 day')->format('Y-m-d');
$next = $date->modify($this->viewMode === 'week' ? '+7 days' : '+1 day')->format('Y-m-d');
?>
<section class="memi-schedule" data-memi-schedule data-date="<?= htmlspecialchars($this->startDate, ENT_QUOTES, 'UTF-8'); ?>" data-default-view="<?= htmlspecialchars($this->viewMode, ENT_QUOTES, 'UTF-8'); ?>">
    <header class="memi-schedule__header">
        <div>
            <h1><?= Text::_('COM_MEMIPILATES_SCHEDULE'); ?></h1>
            <p><?= htmlspecialchars($date->format($this->viewMode === 'week' ? 'd M Y' : 'l d F Y'), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <nav class="memi-schedule__nav" aria-label="<?= Text::_('COM_MEMIPILATES_SCHEDULE'); ?>">
            <a class="btn btn-outline-secondary" href="<?= Route::_('index.php?option=com_memipilates&view=schedule&mode=' . $this->viewMode . '&date=' . $previous); ?>"><?= Text::_('COM_MEMIPILATES_SCHEDULE_PREVIOUS'); ?></a>
            <a class="btn btn-outline-secondary" href="<?= Route::_('index.php?option=com_memipilates&view=schedule&mode=' . $this->viewMode . '&date=' . gmdate('Y-m-d')); ?>"><?= Text::_('COM_MEMIPILATES_SCHEDULE_TODAY'); ?></a>
            <a class="btn btn-outline-secondary" href="<?= Route::_('index.php?option=com_memipilates&view=schedule&mode=' . $this->viewMode . '&date=' . $next); ?>"><?= Text::_('COM_MEMIPILATES_SCHEDULE_NEXT'); ?></a>
        </nav>
    </header>

    <form class="memi-schedule__filters" data-memi-schedule-filters>
        <label><?= Text::_('COM_MEMIPILATES_SCHEDULE_FILTER_TYPE'); ?><select data-memi-schedule-filter="type"><option value=""><?= Text::_('JALL'); ?></option><?php foreach ($this->filters['types'] as $item) : ?><option value="<?= (int) $item['id']; ?>"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
        <label><?= Text::_('COM_MEMIPILATES_SCHEDULE_FILTER_INSTRUCTOR'); ?><select data-memi-schedule-filter="instructor"><option value=""><?= Text::_('JALL'); ?></option><?php foreach ($this->filters['instructors'] as $item) : ?><option value="<?= (int) $item['id']; ?>"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
        <label><?= Text::_('COM_MEMIPILATES_SCHEDULE_FILTER_LOCATION'); ?><select data-memi-schedule-filter="location"><option value=""><?= Text::_('JALL'); ?></option><?php foreach ($this->filters['locations'] as $item) : ?><option value="<?= (int) $item['id']; ?>"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
        <button type="reset" class="btn btn-link" data-memi-clear-filters><?= Text::_('COM_MEMIPILATES_SCHEDULE_CLEAR_FILTERS'); ?></button>
    </form>
    <p data-memi-schedule-count aria-live="polite"></p>
    <div class="memi-schedule__grid" data-memi-schedule-list>
        <?php foreach ($this->sessions as $session) :
            $remaining = max(0, (int) $session['capacity'] - (int) $session['reserved_count']);
            $state = $session['status'] === 'cancelled' ? 'cancelled' : ($remaining === 0 ? 'full' : ($remaining <= 2 ? 'almost-full' : 'available'));
            $statusKey = $state === 'full' ? 'COM_MEMIPILATES_SCHEDULE_FULL' : ($state === 'cancelled' ? 'COM_MEMIPILATES_SCHEDULE_CANCELLED' : ($state === 'almost-full' ? 'COM_MEMIPILATES_SCHEDULE_ALMOST_FULL' : 'COM_MEMIPILATES_SCHEDULE_AVAILABLE'));
            $start = (new DateTimeImmutable($session['starts_at'], new DateTimeZone('UTC')))->setTimezone($tz);
        ?>
            <article class="memi-class-card" data-memi-schedule-card data-course-type="<?= (int) $session['course_type_id']; ?>" data-instructor="<?= (int) $session['instructor_id']; ?>" data-location="<?= (int) ($session['location_id'] ?? 0); ?>" data-session-date="<?= htmlspecialchars($start->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" data-status="<?= $state; ?>">
                <span class="memi-class-card__time"><?= htmlspecialchars($start->format('H:i'), ENT_QUOTES, 'UTF-8'); ?></span>
                <div class="memi-class-card__main">
                    <h2><?= htmlspecialchars($session['course_title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p><?= htmlspecialchars(trim(($session['instructor_name'] ?: '') . ' · ' . ($session['room_title'] ?: '')), ENT_QUOTES, 'UTF-8'); ?></p>
                    <p><span class="memi-status memi-status--<?= $state; ?>"><?= Text::_($statusKey); ?></span> · <?= Text::sprintf('COM_MEMIPILATES_SCHEDULE_PLACES_LEFT', $remaining); ?></p>
                </div>
                <div class="memi-class-card__actions">
                    <?php if ($state === 'full') : ?>
                        <a class="btn btn-outline-primary" href="<?= Route::_('index.php?option=com_memipilates&view=booking&session_id=' . (int) $session['id']); ?>"><?= Text::_('COM_MEMIPILATES_SCHEDULE_JOIN_WAITLIST'); ?></a>
                    <?php elseif ($state !== 'cancelled') : ?>
                        <a class="btn btn-primary" href="<?= Route::_('index.php?option=com_memipilates&view=booking&session_id=' . (int) $session['id']); ?>"><?= Text::_('COM_MEMIPILATES_SCHEDULE_BOOK'); ?></a>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <p class="memi-empty-state" data-memi-schedule-empty hidden><?= Text::_('COM_MEMIPILATES_SCHEDULE_NO_RESULTS'); ?></p>
</section>
