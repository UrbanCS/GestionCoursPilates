<?php
/** @var \Memi\Component\Memipilates\Site\View\Schedule\HtmlView $this */
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$tz = \Memi\Component\Memipilates\Administrator\Service\ComponentServices::settings()->timezone();
$rangeStart = new DateTimeImmutable($this->startDate, $tz);
$today = new DateTimeImmutable('today', $tz);
$previous = $rangeStart->modify('-7 days')->format('Y-m-d');
$next = $rangeStart->modify('+7 days')->format('Y-m-d');
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$weekdaysShort = [
    1 => Text::_('COM_MEMIPILATES_SCHEDULE_MONDAY_SHORT'),
    2 => Text::_('COM_MEMIPILATES_SCHEDULE_TUESDAY_SHORT'),
    3 => Text::_('COM_MEMIPILATES_SCHEDULE_WEDNESDAY_SHORT'),
    4 => Text::_('COM_MEMIPILATES_SCHEDULE_THURSDAY_SHORT'),
    5 => Text::_('COM_MEMIPILATES_SCHEDULE_FRIDAY_SHORT'),
    6 => Text::_('COM_MEMIPILATES_SCHEDULE_SATURDAY_SHORT'),
    7 => Text::_('COM_MEMIPILATES_SCHEDULE_SUNDAY_SHORT'),
];
$weekdaysLong = [
    1 => Text::_('COM_MEMIPILATES_SCHEDULE_MONDAY'),
    2 => Text::_('COM_MEMIPILATES_SCHEDULE_TUESDAY'),
    3 => Text::_('COM_MEMIPILATES_SCHEDULE_WEDNESDAY'),
    4 => Text::_('COM_MEMIPILATES_SCHEDULE_THURSDAY'),
    5 => Text::_('COM_MEMIPILATES_SCHEDULE_FRIDAY'),
    6 => Text::_('COM_MEMIPILATES_SCHEDULE_SATURDAY'),
    7 => Text::_('COM_MEMIPILATES_SCHEDULE_SUNDAY'),
];
$monthsLong = [
    1 => Text::_('COM_MEMIPILATES_SCHEDULE_JANUARY'),
    2 => Text::_('COM_MEMIPILATES_SCHEDULE_FEBRUARY'),
    3 => Text::_('COM_MEMIPILATES_SCHEDULE_MARCH'),
    4 => Text::_('COM_MEMIPILATES_SCHEDULE_APRIL'),
    5 => Text::_('COM_MEMIPILATES_SCHEDULE_MAY'),
    6 => Text::_('COM_MEMIPILATES_SCHEDULE_JUNE'),
    7 => Text::_('COM_MEMIPILATES_SCHEDULE_JULY'),
    8 => Text::_('COM_MEMIPILATES_SCHEDULE_AUGUST'),
    9 => Text::_('COM_MEMIPILATES_SCHEDULE_SEPTEMBER'),
    10 => Text::_('COM_MEMIPILATES_SCHEDULE_OCTOBER'),
    11 => Text::_('COM_MEMIPILATES_SCHEDULE_NOVEMBER'),
    12 => Text::_('COM_MEMIPILATES_SCHEDULE_DECEMBER'),
];
$formatDateHeading = static function (DateTimeImmutable $date) use ($weekdaysLong, $monthsLong): string {
    return Text::sprintf(
        'COM_MEMIPILATES_SCHEDULE_DATE_HEADING',
        $weekdaysLong[(int) $date->format('N')],
        $date->format('j'),
        $monthsLong[(int) $date->format('n')],
        $date->format('Y')
    );
};

$days = [];
for ($offset = 0; $offset < 7; $offset++) {
    $days[] = $rangeStart->modify('+' . $offset . ' days');
}

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$selectedSessionCount = 0;
foreach ($this->sessions as $session) {
    $sessionDate = (new DateTimeImmutable((string) $session['starts_at'], new DateTimeZone('UTC')))
        ->setTimezone($tz)
        ->format('Y-m-d');
    if ($sessionDate === $this->startDate) {
        $selectedSessionCount++;
    }
}
?>
<section
    class="memi-schedule"
    data-memi-schedule
    data-date="<?= $escape($this->startDate); ?>"
    data-today="<?= $escape($today->format('Y-m-d')); ?>"
    data-range-start="<?= $escape($this->startDate); ?>"
    data-default-view="day"
    data-locale="<?= $escape($this->locale); ?>"
    data-url-sync="true"
>
    <header class="memi-schedule__header">
        <h1 class="memi-schedule__title"><?= Text::_('COM_MEMIPILATES_SCHEDULE_PAGE_TITLE'); ?></h1>
        <nav class="memi-schedule__header-actions" aria-label="<?= $escape(Text::_('COM_MEMIPILATES_ACCOUNT')); ?>">
            <?php if ($this->canManageStudio) : ?>
                <a class="btn btn-outline-secondary" href="<?= Route::_('index.php?option=com_memipilates&view=' . rawurlencode($this->managementLandingView)); ?>">
                    <?= Text::_('COM_MEMIPILATES_PORTAL_OPEN'); ?>
                </a>
            <?php endif; ?>
            <a class="btn btn-outline-primary" href="<?= Route::_('index.php?option=com_memipilates&view=dashboard'); ?>">
                <?= Text::_('COM_MEMIPILATES_ACCOUNT'); ?>
            </a>
            <a class="btn btn-primary" href="<?= Route::_('index.php?option=com_memipilates&view=checkout'); ?>">
                <?= Text::_('COM_MEMIPILATES_BOOKING_BUY_PACKAGE'); ?>
            </a>
        </nav>
    </header>

    <div class="memi-schedule__panel">
        <div class="memi-schedule__panel-top">
            <h2><?= Text::_('COM_MEMIPILATES_SCHEDULE_FIND_CLASS'); ?></h2>

            <form class="memi-schedule__filters" data-memi-schedule-filters aria-label="<?= $escape(Text::_('COM_MEMIPILATES_SCHEDULE_FILTERS')); ?>">
                <label>
                    <span class="memi-visually-hidden"><?= Text::_('COM_MEMIPILATES_SCHEDULE_FILTER_LOCATION'); ?></span>
                    <select data-memi-schedule-filter="location" aria-label="<?= $escape(Text::_('COM_MEMIPILATES_SCHEDULE_FILTER_LOCATION')); ?>">
                        <option value=""><?= Text::_('COM_MEMIPILATES_SCHEDULE_ALL_LOCATIONS'); ?></option>
                        <?php foreach ($this->filters['locations'] as $item) : ?>
                            <option value="<?= (int) $item['id']; ?>"><?= $escape($item['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span class="memi-visually-hidden"><?= Text::_('COM_MEMIPILATES_SCHEDULE_FILTER_TYPE'); ?></span>
                    <select data-memi-schedule-filter="type" aria-label="<?= $escape(Text::_('COM_MEMIPILATES_SCHEDULE_FILTER_TYPE')); ?>">
                        <option value=""><?= Text::_('COM_MEMIPILATES_SCHEDULE_ALL_TYPES'); ?></option>
                        <?php foreach ($this->filters['types'] as $item) : ?>
                            <option value="<?= (int) $item['id']; ?>"><?= $escape($item['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span class="memi-visually-hidden"><?= Text::_('COM_MEMIPILATES_SCHEDULE_FILTER_INSTRUCTOR'); ?></span>
                    <select data-memi-schedule-filter="instructor" aria-label="<?= $escape(Text::_('COM_MEMIPILATES_SCHEDULE_FILTER_INSTRUCTOR')); ?>">
                        <option value=""><?= Text::_('COM_MEMIPILATES_SCHEDULE_ALL_INSTRUCTORS'); ?></option>
                        <?php foreach ($this->filters['instructors'] as $item) : ?>
                            <option value="<?= (int) $item['id']; ?>"><?= $escape($item['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="reset" class="memi-schedule__clear" data-memi-clear-filters><?= Text::_('COM_MEMIPILATES_SCHEDULE_CLEAR_FILTERS'); ?></button>
            </form>
        </div>

        <div class="memi-schedule__calendar-toolbar">
            <h3 data-memi-schedule-month-label><?= $escape($monthsLong[(int) $rangeStart->format('n')]); ?></h3>
            <div class="memi-schedule__calendar-wrap" data-memi-schedule-calendar-wrap>
                <button
                    type="button"
                    class="memi-schedule__calendar-picker"
                    data-memi-schedule-calendar-toggle
                    aria-haspopup="dialog"
                    aria-expanded="false"
                    aria-controls="memi-schedule-calendar"
                >
                    <span class="memi-schedule__calendar-icon" aria-hidden="true"></span>
                    <span><?= Text::_('COM_MEMIPILATES_SCHEDULE_FULL_CALENDAR'); ?></span>
                </button>

                <div
                    class="memi-schedule__calendar-popover"
                    id="memi-schedule-calendar"
                    data-memi-schedule-calendar
                    role="dialog"
                    aria-labelledby="memi-schedule-calendar-title"
                    hidden
                >
                    <div class="memi-schedule__calendar-header">
                        <button
                            type="button"
                            class="memi-schedule__calendar-month-arrow"
                            data-memi-schedule-calendar-prev
                            aria-label="<?= $escape(Text::_('COM_MEMIPILATES_SCHEDULE_PREVIOUS_MONTH')); ?>"
                        ><span aria-hidden="true">&#8249;</span></button>
                        <h4 id="memi-schedule-calendar-title" data-memi-schedule-calendar-title aria-live="polite"></h4>
                        <button
                            type="button"
                            class="memi-schedule__calendar-month-arrow"
                            data-memi-schedule-calendar-next
                            aria-label="<?= $escape(Text::_('COM_MEMIPILATES_SCHEDULE_NEXT_MONTH')); ?>"
                        ><span aria-hidden="true">&#8250;</span></button>
                    </div>

                    <div class="memi-schedule__calendar-weekdays" aria-hidden="true">
                        <?php foreach ($weekdaysShort as $weekday) : ?>
                            <span><?= $escape($weekday); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="memi-schedule__calendar-grid" data-memi-schedule-calendar-grid role="grid"></div>

                    <div class="memi-schedule__calendar-footer">
                        <button type="button" data-memi-schedule-calendar-today><?= Text::_('COM_MEMIPILATES_SCHEDULE_TODAY'); ?></button>
                        <button type="button" data-memi-schedule-calendar-close><?= Text::_('COM_MEMIPILATES_SCHEDULE_CLOSE_CALENDAR'); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <nav class="memi-schedule__week" aria-label="<?= $escape(Text::_('COM_MEMIPILATES_SCHEDULE_DATE_NAVIGATION')); ?>">
            <a
                class="memi-schedule__week-arrow"
                href="<?= Route::_('index.php?option=com_memipilates&view=schedule&date=' . $previous); ?>"
                data-memi-schedule-range-date="<?= $escape($previous); ?>"
                aria-label="<?= $escape(Text::_('COM_MEMIPILATES_SCHEDULE_PREVIOUS')); ?>"
            ><span aria-hidden="true">&#8249;</span></a>

            <div class="memi-schedule__dates">
                <?php foreach ($days as $index => $day) :
                    $isoDate = $day->format('Y-m-d');
                    $isToday = $isoDate === $today->format('Y-m-d');
                    $isSelected = $index === 0;
                    $dayLabel = $isToday ? Text::_('COM_MEMIPILATES_SCHEDULE_TODAY') : $weekdaysShort[(int) $day->format('N')];
                ?>
                    <a
                        class="memi-schedule__date<?= $isSelected ? ' is-active' : ''; ?><?= $isToday ? ' is-today' : ''; ?>"
                        href="<?= Route::_('index.php?option=com_memipilates&view=schedule&date=' . $isoDate); ?>"
                        data-memi-schedule-date-choice="<?= $escape($isoDate); ?>"
                        data-date-heading="<?= $escape($formatDateHeading($day)); ?>"
                        aria-label="<?= $escape($formatDateHeading($day)); ?>"
                        <?= $isSelected ? 'aria-current="date"' : ''; ?>
                    >
                        <span class="memi-schedule__date-weekday"><?= $escape($dayLabel); ?></span>
                        <span class="memi-schedule__date-number"><?= $escape($day->format('j')); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <a
                class="memi-schedule__week-arrow"
                href="<?= Route::_('index.php?option=com_memipilates&view=schedule&date=' . $next); ?>"
                data-memi-schedule-range-date="<?= $escape($next); ?>"
                aria-label="<?= $escape(Text::_('COM_MEMIPILATES_SCHEDULE_NEXT')); ?>"
            ><span aria-hidden="true">&#8250;</span></a>
        </nav>

        <div class="memi-schedule__selected-date">
            <h3 data-memi-schedule-date-label><?= $escape($formatDateHeading($rangeStart)); ?></h3>
            <p><?= Text::_('COM_MEMIPILATES_SCHEDULE_TIMEZONE_NOTE'); ?></p>
        </div>

        <p class="memi-visually-hidden" data-memi-schedule-count aria-live="polite">
            <?= Text::sprintf('COM_MEMIPILATES_SCHEDULE_VISIBLE_COUNT', $selectedSessionCount); ?>
        </p>

        <div class="memi-schedule__list" data-memi-schedule-list role="list">
            <?php foreach ($this->sessions as $session) :
                $remaining = max(0, (int) $session['capacity'] - (int) $session['reserved_count']);
                $state = $session['status'] === 'cancelled'
                    ? 'cancelled'
                    : ($remaining === 0 ? 'full' : ($remaining <= 2 ? 'almost-full' : 'available'));
                $statusKey = $state === 'full'
                    ? 'COM_MEMIPILATES_SCHEDULE_FULL'
                    : ($state === 'cancelled'
                        ? 'COM_MEMIPILATES_SCHEDULE_CANCELLED'
                        : ($state === 'almost-full'
                            ? 'COM_MEMIPILATES_SCHEDULE_ALMOST_FULL'
                            : 'COM_MEMIPILATES_SCHEDULE_AVAILABLE'));
                $startUtc = new DateTimeImmutable((string) $session['starts_at'], new DateTimeZone('UTC'));
                $start = $startUtc->setTimezone($tz);
                $registrationNotOpen = !empty($session['registration_opens_at'])
                    && $nowUtc < new DateTimeImmutable((string) $session['registration_opens_at'], new DateTimeZone('UTC'));
                $registrationClosed = $nowUtc >= $startUtc
                    || (!empty($session['registration_closes_at'])
                        && $nowUtc >= new DateTimeImmutable((string) $session['registration_closes_at'], new DateTimeZone('UTC')));
                $registrationIsOpen = !$registrationNotOpen && !$registrationClosed;
                $registrationLabelKey = $registrationNotOpen
                    ? 'COM_MEMIPILATES_SCHEDULE_REGISTRATION_NOT_OPEN'
                    : 'COM_MEMIPILATES_SCHEDULE_REGISTRATION_CLOSED';
                $duration = max(5, (int) ($session['duration_minutes'] ?? 60));
                $sessionDate = $start->format('Y-m-d');
                $isSelectedDate = $sessionDate === $this->startDate;
                $description = trim((string) ($session['course_description'] ?? ''));
                $location = trim((string) ($session['location_title'] ?? ''));
                $room = trim((string) ($session['room_title'] ?? ''));
                $locationText = implode(' · ', array_filter([$location, $room]));
                $titleId = 'memi-session-title-' . (int) $session['id'];
            ?>
                <article
                    class="memi-class-card"
                    data-memi-schedule-card
                    data-course-type="<?= (int) $session['course_type_id']; ?>"
                    data-instructor="<?= (int) $session['instructor_id']; ?>"
                    data-location="<?= (int) ($session['location_id'] ?? 0); ?>"
                    data-session-date="<?= $escape($sessionDate); ?>"
                    data-status="<?= $escape($state); ?>"
                    role="listitem"
                    aria-labelledby="<?= $escape($titleId); ?>"
                    <?= $isSelectedDate ? '' : 'hidden'; ?>
                >
                    <div class="memi-class-card__time">
                        <time datetime="<?= $escape($start->format(DATE_ATOM)); ?>"><?= $escape($start->format('H:i')); ?></time>
                        <span><?= Text::sprintf('COM_MEMIPILATES_SCHEDULE_DURATION', $duration); ?></span>
                    </div>

                    <div class="memi-class-card__main">
                        <h3 id="<?= $escape($titleId); ?>"><?= $escape($session['course_title']); ?></h3>
                        <?php if (!empty($session['instructor_name'])) : ?>
                            <p class="memi-class-card__instructor"><?= $escape($session['instructor_name']); ?></p>
                        <?php endif; ?>
                        <?php if ($description !== '') : ?>
                            <details class="memi-class-card__details">
                                <summary><?= Text::_('COM_MEMIPILATES_SCHEDULE_CLASS_DETAILS'); ?></summary>
                                <p><?= nl2br($escape($description)); ?></p>
                            </details>
                        <?php endif; ?>
                    </div>

                    <div class="memi-class-card__location">
                        <?php if ($locationText !== '') : ?>
                            <span><?= $escape($locationText); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="memi-class-card__availability">
                        <span class="memi-status memi-status--<?= $escape($state); ?>"><?= Text::_($statusKey); ?></span>
                        <span><?= Text::sprintf('COM_MEMIPILATES_SCHEDULE_PLACES_LEFT', $remaining); ?></span>
                    </div>

                    <div class="memi-class-card__actions">
                        <?php if ($state !== 'cancelled' && !$registrationIsOpen) : ?>
                            <span class="btn btn-outline-secondary disabled" aria-disabled="true"><?= Text::_($registrationLabelKey); ?></span>
                        <?php elseif ($state === 'full') : ?>
                            <a class="btn btn-outline-primary" href="<?= Route::_('index.php?option=com_memipilates&view=booking&session_id=' . (int) $session['id']); ?>"><?= Text::_('COM_MEMIPILATES_SCHEDULE_JOIN_WAITLIST'); ?></a>
                        <?php elseif ($state !== 'cancelled') : ?>
                            <a class="btn btn-primary" href="<?= Route::_('index.php?option=com_memipilates&view=booking&session_id=' . (int) $session['id']); ?>"><?= Text::_('COM_MEMIPILATES_SCHEDULE_BOOK'); ?></a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <p class="memi-empty-state" data-memi-schedule-empty <?= $selectedSessionCount > 0 ? 'hidden' : ''; ?>>
            <?= Text::_('COM_MEMIPILATES_SCHEDULE_NO_RESULTS'); ?>
        </p>
    </div>
</section>
