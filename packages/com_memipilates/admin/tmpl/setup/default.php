<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$label = fn (string $key, string $fallback): string => $this->label($key, $fallback);
$postUrl = Route::_('index.php?option=com_memipilates&view=setup');
$token = Session::getFormToken();
$weekdays = [1 => $label('COM_MEMIPILATES_SETUP_MONDAY', 'Monday'), 2 => $label('COM_MEMIPILATES_SETUP_TUESDAY', 'Tuesday'), 3 => $label('COM_MEMIPILATES_SETUP_WEDNESDAY', 'Wednesday'), 4 => $label('COM_MEMIPILATES_SETUP_THURSDAY', 'Thursday'), 5 => $label('COM_MEMIPILATES_SETUP_FRIDAY', 'Friday'), 6 => $label('COM_MEMIPILATES_SETUP_SATURDAY', 'Saturday'), 7 => $label('COM_MEMIPILATES_SETUP_SUNDAY', 'Sunday')];
$entityFields = static function (string $entity) use ($token): string {
    return '<input type="hidden" name="option" value="com_memipilates"><input type="hidden" name="task" value="display.saveSetup"><input type="hidden" name="entity" value="' . $entity . '"><input type="hidden" name="' . $token . '" value="1"><input type="hidden" name="published" value="1">';
};
?>
<div class="container-fluid memi-admin-setup">
    <div class="alert alert-info mb-4" role="status">
        <strong><?= $escape($label('COM_MEMIPILATES_SETUP_TITLE', 'Studio setup')); ?></strong><br>
        <?= $escape($label('COM_MEMIPILATES_SETUP_INTRO', 'Create the catalogue in this order. The public schedule stays empty until you create and publish a future session.')); ?>
    </div>

    <div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
        <div class="col"><div class="card h-100"><div class="card-body"><span class="text-muted small"><?= $escape($label('COM_MEMIPILATES_SETUP_LOCATIONS', 'Locations')); ?></span><div class="fs-3 fw-bold"><?= count($this->locations); ?></div></div></div></div>
        <div class="col"><div class="card h-100"><div class="card-body"><span class="text-muted small"><?= $escape($label('COM_MEMIPILATES_SETUP_ROOMS', 'Rooms')); ?></span><div class="fs-3 fw-bold"><?= count($this->rooms); ?></div></div></div></div>
        <div class="col"><div class="card h-100"><div class="card-body"><span class="text-muted small"><?= $escape($label('COM_MEMIPILATES_SETUP_COURSES', 'Courses')); ?></span><div class="fs-3 fw-bold"><?= count($this->courses); ?></div></div></div></div>
        <div class="col"><div class="card h-100"><div class="card-body"><span class="text-muted small"><?= $escape($label('COM_MEMIPILATES_SETUP_RECURRING_RULES', 'Recurring schedules')); ?></span><div class="fs-3 fw-bold"><?= count($this->rules); ?></div></div></div></div>
    </div>

    <?php if ($this->hasCatalog && $this->canResetCatalog) : ?>
    <section class="card border-danger mb-4">
        <div class="card-header text-danger"><h2 class="h5 mb-0"><?= $escape($label('COM_MEMIPILATES_SETUP_RESET_TITLE', 'Reset test catalogue')); ?></h2></div>
        <div class="card-body">
            <p><?= $escape($label('COM_MEMIPILATES_SETUP_RESET_INTRO', 'Use this only to clear startup test data before launch. It archives the catalogue and generated sessions, and is refused if customer activity exists.')); ?></p>
            <form action="<?= $postUrl; ?>" method="post" class="row g-3">
                <input type="hidden" name="option" value="com_memipilates">
                <input type="hidden" name="task" value="display.resetTestCatalog">
                <input type="hidden" name="<?= $token; ?>" value="1">
                <div class="col-12 col-md-6">
                    <label class="form-label" for="reset-confirmation"><?= $escape($label('COM_MEMIPILATES_SETUP_RESET_CONFIRMATION', 'Type REINITIALISER to confirm')); ?></label>
                    <input class="form-control" id="reset-confirmation" name="confirmation" autocomplete="off" maxlength="32" required>
                </div>
                <div class="col-12"><button class="btn btn-danger" type="submit"><?= $escape($label('COM_MEMIPILATES_SETUP_RESET_BUTTON', 'Archive test catalogue')); ?></button></div>
            </form>
        </div>
    </section>
    <?php endif; ?>

    <section class="card mb-4">
        <div class="card-header"><h2 class="h5 mb-0">1. <?= $escape($label('COM_MEMIPILATES_SETUP_LOCATION', 'Location')); ?></h2></div>
        <div class="card-body">
            <form action="<?= $postUrl; ?>" method="post" class="row g-3">
                <?= $entityFields('location'); ?>
                <div class="col-12 col-md-6"><label class="form-label" for="location-title"><?= $escape(Text::_('JGLOBAL_TITLE')); ?></label><input class="form-control" id="location-title" name="title" maxlength="255" required></div>
                <div class="col-12 col-md-6"><label class="form-label" for="location-phone"><?= $escape($label('COM_MEMIPILATES_SETUP_PHONE', 'Phone')); ?></label><input class="form-control" id="location-phone" name="phone" maxlength="64"></div>
                <div class="col-12 col-md-6"><label class="form-label" for="location-address"><?= $escape($label('COM_MEMIPILATES_SETUP_ADDRESS', 'Address')); ?></label><input class="form-control" id="location-address" name="address_line1" maxlength="255"></div>
                <div class="col-12 col-md-2"><label class="form-label" for="location-city"><?= $escape($label('COM_MEMIPILATES_SETUP_CITY', 'City')); ?></label><input class="form-control" id="location-city" name="city" maxlength="128"></div>
                <div class="col-12 col-md-2"><label class="form-label" for="location-province"><?= $escape($label('COM_MEMIPILATES_SETUP_PROVINCE', 'Province')); ?></label><input class="form-control" id="location-province" name="province" maxlength="128" value="Québec"></div>
                <div class="col-12 col-md-2"><label class="form-label" for="location-postal"><?= $escape($label('COM_MEMIPILATES_SETUP_POSTAL_CODE', 'Postal code')); ?></label><input class="form-control" id="location-postal" name="postal_code" maxlength="32"></div>
                <div class="col-12"><button class="btn btn-primary" type="submit"><?= $escape($label('COM_MEMIPILATES_SETUP_SAVE_LOCATION', 'Save location')); ?></button></div>
            </form>
        </div>
    </section>

    <section class="card mb-4">
        <div class="card-header"><h2 class="h5 mb-0">2. <?= $escape($label('COM_MEMIPILATES_SETUP_ROOM', 'Room')); ?></h2></div>
        <div class="card-body">
            <?php if ($this->locations === []) : ?><p class="text-muted mb-0"><?= $escape($label('COM_MEMIPILATES_SETUP_PREREQUISITE_LOCATION', 'Create a location first.')); ?></p><?php else : ?>
            <form action="<?= $postUrl; ?>" method="post" class="row g-3">
                <?= $entityFields('room'); ?>
                <div class="col-12 col-md-5"><label class="form-label" for="room-location"><?= $escape($label('COM_MEMIPILATES_SETUP_LOCATION', 'Location')); ?></label><select class="form-select" id="room-location" name="location_id" required><?php foreach ($this->locations as $location) : ?><option value="<?= (int) $location['id']; ?>"><?= $escape($location['title']); ?><?= ($location['city'] ?? '') !== '' ? ' — ' . $escape($location['city']) : ''; ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-5"><label class="form-label" for="room-title"><?= $escape(Text::_('JGLOBAL_TITLE')); ?></label><input class="form-control" id="room-title" name="title" maxlength="255" required></div>
                <div class="col-12 col-md-2"><label class="form-label" for="room-capacity"><?= $escape($label('COM_MEMIPILATES_SETUP_CAPACITY', 'Capacity')); ?></label><input class="form-control" id="room-capacity" name="capacity" type="number" min="1" max="500" value="8" required></div>
                <div class="col-12"><label class="form-label" for="room-description"><?= $escape(Text::_('JGLOBAL_DESCRIPTION')); ?></label><textarea class="form-control" id="room-description" name="description" rows="2"></textarea></div>
                <div class="col-12"><button class="btn btn-primary" type="submit"><?= $escape($label('COM_MEMIPILATES_SETUP_SAVE_ROOM', 'Save room')); ?></button></div>
            </form><?php endif; ?>
        </div>
    </section>

    <section class="card mb-4">
        <div class="card-header"><h2 class="h5 mb-0">3. <?= $escape($label('COM_MEMIPILATES_SETUP_INSTRUCTOR', 'Instructor')); ?></h2></div>
        <div class="card-body">
            <form action="<?= $postUrl; ?>" method="post" class="row g-3">
                <?= $entityFields('instructor'); ?>
                <div class="col-12 col-md-4"><label class="form-label" for="instructor-name"><?= $escape($label('COM_MEMIPILATES_SETUP_DISPLAY_NAME', 'Display name')); ?></label><input class="form-control" id="instructor-name" name="display_name" maxlength="255" required></div>
                <div class="col-12 col-md-4"><label class="form-label" for="instructor-email"><?= $escape($label('COM_MEMIPILATES_SETUP_EMAIL', 'Email')); ?></label><input class="form-control" id="instructor-email" name="email" type="email" maxlength="320"></div>
                <div class="col-12 col-md-4"><label class="form-label" for="instructor-phone"><?= $escape($label('COM_MEMIPILATES_SETUP_PHONE', 'Phone')); ?></label><input class="form-control" id="instructor-phone" name="phone" maxlength="64"></div>
                <div class="col-12"><label class="form-label" for="instructor-bio"><?= $escape($label('COM_MEMIPILATES_SETUP_BIO', 'Biography')); ?></label><textarea class="form-control" id="instructor-bio" name="bio" rows="2"></textarea></div>
                <div class="col-12"><button class="btn btn-primary" type="submit"><?= $escape($label('COM_MEMIPILATES_SETUP_SAVE_INSTRUCTOR', 'Save instructor')); ?></button></div>
            </form>
        </div>
    </section>

    <section class="card mb-4">
        <div class="card-header"><h2 class="h5 mb-0">4. <?= $escape($label('COM_MEMIPILATES_SETUP_COURSE_TYPE', 'Course type')); ?></h2></div>
        <div class="card-body">
            <form action="<?= $postUrl; ?>" method="post" class="row g-3">
                <?= $entityFields('course_type'); ?>
                <div class="col-12 col-md-6"><label class="form-label" for="type-title"><?= $escape(Text::_('JGLOBAL_TITLE')); ?></label><input class="form-control" id="type-title" name="title" maxlength="255" placeholder="Pilates Reformer" required></div>
                <div class="col-12 col-md-3"><label class="form-label" for="type-level"><?= $escape($label('COM_MEMIPILATES_SETUP_LEVEL', 'Level')); ?></label><input class="form-control" id="type-level" name="level" maxlength="64" placeholder="Tous niveaux"></div>
                <div class="col-12 col-md-3"><label class="form-label" for="type-intensity"><?= $escape($label('COM_MEMIPILATES_SETUP_INTENSITY', 'Intensity (1–10)')); ?></label><input class="form-control" id="type-intensity" name="intensity" type="number" min="1" max="10"></div>
                <div class="col-6 col-md-3"><label class="form-label" for="type-duration"><?= $escape($label('COM_MEMIPILATES_SETUP_DURATION_MINUTES', 'Duration (minutes)')); ?></label><input class="form-control" id="type-duration" name="duration_minutes" type="number" min="5" max="720" value="60" required></div>
                <div class="col-6 col-md-3"><label class="form-label" for="type-capacity"><?= $escape($label('COM_MEMIPILATES_SETUP_CAPACITY', 'Capacity')); ?></label><input class="form-control" id="type-capacity" name="capacity" type="number" min="1" max="500" value="8" required></div>
                <div class="col-6 col-md-2"><label class="form-label" for="type-credits"><?= $escape($label('COM_MEMIPILATES_SETUP_CREDITS', 'Credits')); ?></label><input class="form-control" id="type-credits" name="credits_required" type="number" min="0" max="1000" value="1" required></div>
                <div class="col-6 col-md-2"><label class="form-label" for="type-price"><?= $escape($label('COM_MEMIPILATES_SETUP_PRICE_CAD', 'Price (CAD)')); ?></label><input class="form-control" id="type-price" name="price" inputmode="decimal" value="0"></div>
                <div class="col-12 col-md-2"><label class="form-label" for="type-tax"><?= $escape($label('COM_MEMIPILATES_SETUP_TAX_BPS', 'Tax (basis points)')); ?></label><input class="form-control" id="type-tax" name="tax_rate_basis_points" type="number" min="0" max="10000" value="0"></div>
                <div class="col-12"><label class="form-label" for="type-description"><?= $escape(Text::_('JGLOBAL_DESCRIPTION')); ?></label><textarea class="form-control" id="type-description" name="description" rows="2"></textarea></div>
                <div class="col-12"><button class="btn btn-primary" type="submit"><?= $escape($label('COM_MEMIPILATES_SETUP_SAVE_COURSE_TYPE', 'Save course type')); ?></button></div>
            </form>
        </div>
    </section>

    <section class="card mb-4">
        <div class="card-header"><h2 class="h5 mb-0">5. <?= $escape($label('COM_MEMIPILATES_SETUP_COURSE', 'Course')); ?></h2></div>
        <div class="card-body">
            <?php if ($this->courseTypes === []) : ?><p class="text-muted mb-0"><?= $escape($label('COM_MEMIPILATES_SETUP_PREREQUISITE_COURSE_TYPE', 'Create a course type first.')); ?></p><?php else : ?>
            <form action="<?= $postUrl; ?>" method="post" class="row g-3">
                <?= $entityFields('course'); ?>
                <div class="col-12 col-md-4"><label class="form-label" for="course-type"><?= $escape($label('COM_MEMIPILATES_SETUP_COURSE_TYPE', 'Course type')); ?></label><select class="form-select" id="course-type" name="course_type_id" required><?php foreach ($this->courseTypes as $type) : ?><option value="<?= (int) $type['id']; ?>"><?= $escape($type['title']); ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-4"><label class="form-label" for="course-title"><?= $escape(Text::_('JGLOBAL_TITLE')); ?></label><input class="form-control" id="course-title" name="title" maxlength="255" required></div>
                <div class="col-12 col-md-4"><label class="form-label" for="course-instructor"><?= $escape($label('COM_MEMIPILATES_SETUP_INSTRUCTOR', 'Instructor')); ?></label><select class="form-select" id="course-instructor" name="instructor_id"><option value="">—</option><?php foreach ($this->instructors as $instructor) : ?><option value="<?= (int) $instructor['id']; ?>"><?= $escape($instructor['display_name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-4"><label class="form-label" for="course-room"><?= $escape($label('COM_MEMIPILATES_SETUP_ROOM', 'Room')); ?></label><select class="form-select" id="course-room" name="room_id"><option value="">—</option><?php foreach ($this->rooms as $room) : ?><option value="<?= (int) $room['id']; ?>"><?= $escape($room['location_title'] . ' — ' . $room['title'] . ' (' . $room['capacity'] . ')'); ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-md-2"><label class="form-label" for="course-duration"><?= $escape($label('COM_MEMIPILATES_SETUP_DURATION_MINUTES', 'Duration (minutes)')); ?></label><input class="form-control" id="course-duration" name="duration_minutes" type="number" min="5" max="720" value="60"></div>
                <div class="col-6 col-md-2"><label class="form-label" for="course-capacity"><?= $escape($label('COM_MEMIPILATES_SETUP_CAPACITY', 'Capacity')); ?></label><input class="form-control" id="course-capacity" name="capacity" type="number" min="1" max="500" value="8"></div>
                <div class="col-6 col-md-2"><label class="form-label" for="course-credits"><?= $escape($label('COM_MEMIPILATES_SETUP_CREDITS', 'Credits')); ?></label><input class="form-control" id="course-credits" name="credits_required" type="number" min="0" max="1000" value="1"></div>
                <div class="col-6 col-md-2"><label class="form-label" for="course-price"><?= $escape($label('COM_MEMIPILATES_SETUP_PRICE_CAD', 'Price (CAD)')); ?></label><input class="form-control" id="course-price" name="price" inputmode="decimal" value="0"></div>
                <div class="col-6 col-md-2"><label class="form-label" for="course-open"><?= $escape($label('COM_MEMIPILATES_SETUP_BOOKING_OPENS_DAYS', 'Booking opens (days)')); ?></label><input class="form-control" id="course-open" name="booking_opens_days" type="number" min="0" max="365" value="7"></div>
                <div class="col-6 col-md-2"><label class="form-label" for="course-close"><?= $escape($label('COM_MEMIPILATES_SETUP_BOOKING_CLOSES_MINUTES', 'Booking closes (minutes)')); ?></label><input class="form-control" id="course-close" name="booking_closes_minutes" type="number" min="0" max="10080" value="0"></div>
                <div class="col-12"><label class="form-label" for="course-description"><?= $escape(Text::_('JGLOBAL_DESCRIPTION')); ?></label><textarea class="form-control" id="course-description" name="description" rows="2"></textarea></div>
                <div class="col-12"><button class="btn btn-primary" type="submit"><?= $escape($label('COM_MEMIPILATES_SETUP_SAVE_COURSE', 'Save course')); ?></button></div>
            </form><?php endif; ?>
        </div>
    </section>

    <section class="card mb-4">
        <div class="card-header"><h2 class="h5 mb-0">6. <?= $escape($label('COM_MEMIPILATES_SETUP_ONE_OFF_SESSION', 'One-off session')); ?></h2></div>
        <div class="card-body">
            <?php if ($this->courses === []) : ?><p class="text-muted mb-0"><?= $escape($label('COM_MEMIPILATES_SETUP_PREREQUISITE_COURSE', 'Create a course first.')); ?></p><?php else : ?>
            <form action="<?= $postUrl; ?>" method="post" class="row g-3">
                <?= $entityFields('session'); ?>
                <div class="col-12 col-md-5"><label class="form-label" for="session-course"><?= $escape($label('COM_MEMIPILATES_SETUP_COURSE', 'Course')); ?></label><select class="form-select" id="session-course" name="course_id" required><?php foreach ($this->courses as $course) : ?><option value="<?= (int) $course['id']; ?>"><?= $escape($course['course_type_title'] . ' — ' . $course['title']); ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-4"><label class="form-label" for="session-start"><?= $escape($label('COM_MEMIPILATES_SETUP_STARTS_AT', 'Starts at')); ?></label><input class="form-control" id="session-start" name="starts_at" type="datetime-local" value="<?= $escape($this->defaultSessionStart); ?>" required></div>
                <div class="col-6 col-md-1"><label class="form-label" for="session-duration"><?= $escape($label('COM_MEMIPILATES_SETUP_DURATION_MINUTES', 'Duration (minutes)')); ?></label><input class="form-control" id="session-duration" name="duration_minutes" type="number" min="5" max="720" value="60"></div>
                <div class="col-6 col-md-2"><label class="form-label" for="session-capacity"><?= $escape($label('COM_MEMIPILATES_SETUP_CAPACITY', 'Capacity')); ?></label><input class="form-control" id="session-capacity" name="capacity" type="number" min="1" max="500" value="8"></div>
                <div class="col-12 col-md-6"><label class="form-label" for="session-instructor"><?= $escape($label('COM_MEMIPILATES_SETUP_INSTRUCTOR', 'Instructor')); ?></label><select class="form-select" id="session-instructor" name="instructor_id"><option value=""><?= $escape($label('COM_MEMIPILATES_SETUP_USE_COURSE_DEFAULT', 'Use course default')); ?></option><?php foreach ($this->instructors as $instructor) : ?><option value="<?= (int) $instructor['id']; ?>"><?= $escape($instructor['display_name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-6"><label class="form-label" for="session-room"><?= $escape($label('COM_MEMIPILATES_SETUP_ROOM', 'Room')); ?></label><select class="form-select" id="session-room" name="room_id"><option value=""><?= $escape($label('COM_MEMIPILATES_SETUP_USE_COURSE_DEFAULT', 'Use course default')); ?></option><?php foreach ($this->rooms as $room) : ?><option value="<?= (int) $room['id']; ?>"><?= $escape($room['location_title'] . ' — ' . $room['title']); ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><button class="btn btn-primary" type="submit"><?= $escape($label('COM_MEMIPILATES_SETUP_SAVE_SESSION', 'Publish session')); ?></button></div>
            </form><?php endif; ?>
        </div>
    </section>

    <section class="card mb-4">
        <div class="card-header"><h2 class="h5 mb-0">7. <?= $escape($label('COM_MEMIPILATES_SETUP_RECURRING_RULE', 'Weekly schedule')); ?></h2></div>
        <div class="card-body">
            <?php if ($this->courses === []) : ?><p class="text-muted mb-0"><?= $escape($label('COM_MEMIPILATES_SETUP_PREREQUISITE_COURSE', 'Create a course first.')); ?></p><?php else : ?>
            <form action="<?= $postUrl; ?>" method="post" class="row g-3">
                <?= $entityFields('session_rule'); ?>
                <div class="col-12 col-md-4"><label class="form-label" for="rule-course"><?= $escape($label('COM_MEMIPILATES_SETUP_COURSE', 'Course')); ?></label><select class="form-select" id="rule-course" name="course_id" required><?php foreach ($this->courses as $course) : ?><option value="<?= (int) $course['id']; ?>"><?= $escape($course['course_type_title'] . ' — ' . $course['title']); ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-2"><label class="form-label" for="rule-weekday"><?= $escape($label('COM_MEMIPILATES_SETUP_WEEKDAY', 'Day')); ?></label><select class="form-select" id="rule-weekday" name="weekday"><?php foreach ($weekdays as $number => $weekday) : ?><option value="<?= $number; ?>"<?= $number === $this->defaultWeekday ? ' selected' : ''; ?>><?= $escape($weekday); ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-md-2"><label class="form-label" for="rule-time"><?= $escape($label('COM_MEMIPILATES_SETUP_START_TIME', 'Start time')); ?></label><input class="form-control" id="rule-time" name="start_time" type="time" value="09:00" required></div>
                <div class="col-6 col-md-2"><label class="form-label" for="rule-start"><?= $escape($label('COM_MEMIPILATES_SETUP_START_DATE', 'Start date')); ?></label><input class="form-control" id="rule-start" name="starts_on" type="date" value="<?= $escape($this->today); ?>" required></div>
                <div class="col-12 col-md-2"><label class="form-label" for="rule-end"><?= $escape($label('COM_MEMIPILATES_SETUP_END_DATE', 'End date')); ?></label><input class="form-control" id="rule-end" name="ends_on" type="date"></div>
                <div class="col-6 col-md-2"><label class="form-label" for="rule-duration"><?= $escape($label('COM_MEMIPILATES_SETUP_DURATION_MINUTES', 'Duration (minutes)')); ?></label><input class="form-control" id="rule-duration" name="duration_minutes" type="number" min="5" max="720" value="60"></div>
                <div class="col-6 col-md-2"><label class="form-label" for="rule-capacity"><?= $escape($label('COM_MEMIPILATES_SETUP_CAPACITY', 'Capacity')); ?></label><input class="form-control" id="rule-capacity" name="capacity" type="number" min="1" max="500" value="8"></div>
                <div class="col-12 col-md-4"><label class="form-label" for="rule-instructor"><?= $escape($label('COM_MEMIPILATES_SETUP_INSTRUCTOR', 'Instructor')); ?></label><select class="form-select" id="rule-instructor" name="instructor_id"><option value=""><?= $escape($label('COM_MEMIPILATES_SETUP_USE_COURSE_DEFAULT', 'Use course default')); ?></option><?php foreach ($this->instructors as $instructor) : ?><option value="<?= (int) $instructor['id']; ?>"><?= $escape($instructor['display_name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-4"><label class="form-label" for="rule-room"><?= $escape($label('COM_MEMIPILATES_SETUP_ROOM', 'Room')); ?></label><select class="form-select" id="rule-room" name="room_id"><option value=""><?= $escape($label('COM_MEMIPILATES_SETUP_USE_COURSE_DEFAULT', 'Use course default')); ?></option><?php foreach ($this->rooms as $room) : ?><option value="<?= (int) $room['id']; ?>"><?= $escape($room['location_title'] . ' — ' . $room['title']); ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><p class="form-text mb-2"><?= $escape($label('COM_MEMIPILATES_SETUP_RULE_GENERATES', 'Saving generates published sessions through the configured planning horizon immediately.')); ?></p><button class="btn btn-primary" type="submit"><?= $escape($label('COM_MEMIPILATES_SETUP_SAVE_RECURRING_RULE', 'Save weekly schedule')); ?></button></div>
            </form><?php endif; ?>
        </div>
    </section>

    <section class="card mb-4">
        <div class="card-header"><h2 class="h5 mb-0">8. <?= $escape($label('COM_MEMIPILATES_SETUP_PACKAGE', 'Class package')); ?></h2></div>
        <div class="card-body">
            <form action="<?= $postUrl; ?>" method="post" class="row g-3">
                <?= $entityFields('package'); ?>
                <div class="col-12 col-md-5"><label class="form-label" for="package-title"><?= $escape(Text::_('JGLOBAL_TITLE')); ?></label><input class="form-control" id="package-title" name="title" maxlength="255" placeholder="10 cours Pilates" required></div>
                <div class="col-6 col-md-2"><label class="form-label" for="package-price"><?= $escape($label('COM_MEMIPILATES_SETUP_PRICE_CAD', 'Price (CAD)')); ?></label><input class="form-control" id="package-price" name="price" inputmode="decimal" required></div>
                <div class="col-6 col-md-2"><label class="form-label" for="package-credits"><?= $escape($label('COM_MEMIPILATES_SETUP_CREDITS', 'Credits')); ?></label><input class="form-control" id="package-credits" name="credits" type="number" min="1" max="1000" value="10" required></div>
                <div class="col-6 col-md-2"><label class="form-label" for="package-validity"><?= $escape($label('COM_MEMIPILATES_SETUP_VALIDITY_DAYS', 'Validity (days)')); ?></label><input class="form-control" id="package-validity" name="validity_days" type="number" min="1" max="3650" placeholder="Sans expiration"></div>
                <div class="col-6 col-md-1"><label class="form-label" for="package-points"><?= $escape($label('COM_MEMIPILATES_SETUP_BONUS_POINTS', 'Bonus points')); ?></label><input class="form-control" id="package-points" name="bonus_points" type="number" min="0" max="1000000" value="0"></div>
                <div class="col-12"><label class="form-label" for="package-description"><?= $escape(Text::_('JGLOBAL_DESCRIPTION')); ?></label><textarea class="form-control" id="package-description" name="description" rows="2"></textarea></div>
                <div class="col-12"><button class="btn btn-primary" type="submit"><?= $escape($label('COM_MEMIPILATES_SETUP_SAVE_PACKAGE', 'Save package')); ?></button></div>
            </form>
        </div>
    </section>
</div>
