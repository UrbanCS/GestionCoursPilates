<?php
/** @var \Memi\Component\Memipilates\Site\View\Coursetypes\HtmlView $this */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$rootUrl = rtrim(Uri::root(), '/') . '/';
?>
<section class="memi-course-types" aria-labelledby="memi-course-types-title">
    <header class="memi-course-types__header">
        <p class="memi-course-types__eyebrow"><?= Text::_('COM_MEMIPILATES_COURSE_TYPES_EYEBROW'); ?></p>
        <h1 id="memi-course-types-title"><?= Text::_('COM_MEMIPILATES_COURSE_TYPES_PAGE_TITLE'); ?></h1>
        <p><?= Text::_('COM_MEMIPILATES_COURSE_TYPES_INTRO'); ?></p>
    </header>

    <?php if ($this->courseTypes !== []) : ?>
        <div class="memi-course-types__grid" role="list">
            <?php foreach ($this->courseTypes as $courseType) :
                $image = trim((string) ($courseType['image'] ?? ''));
                $cleanImage = $image !== '' ? HTMLHelper::cleanImageURL($image) : null;
                $imageUrl = $cleanImage !== null ? trim((string) ($cleanImage->url ?? '')) : '';
                $scheduleUrl = Route::_(
                    'index.php?option=com_memipilates&view=schedule&type=' . (int) $courseType['id']
                        . '&mode=week'
                        . ($this->scheduleMenuItemId > 0 ? '&Itemid=' . $this->scheduleMenuItemId : ''),
                    false
                );
            ?>
                <article class="memi-course-type-card" role="listitem">
                    <a href="<?= $escape($scheduleUrl); ?>" aria-label="<?= $escape(Text::sprintf('COM_MEMIPILATES_COURSE_TYPES_VIEW_SCHEDULE_FOR', $courseType['title'])); ?>">
                        <span class="memi-course-type-card__media">
                            <?php if ($imageUrl !== '') : ?>
                                <img
                                    src="<?= $escape($rootUrl . ltrim($imageUrl, '/')); ?>"
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                >
                            <?php else : ?>
                                <span class="memi-course-type-card__placeholder" aria-hidden="true"></span>
                            <?php endif; ?>
                        </span>
                        <span class="memi-course-type-card__overlay" aria-hidden="true"></span>
                        <span class="memi-course-type-card__content">
                            <strong><?= $escape($courseType['title']); ?></strong>
                            <?php if (trim((string) ($courseType['level'] ?? '')) !== '') : ?>
                                <span><?= $escape($courseType['level']); ?></span>
                            <?php endif; ?>
                            <span class="memi-course-type-card__action"><?= Text::_('COM_MEMIPILATES_COURSE_TYPES_VIEW_SCHEDULE'); ?></span>
                        </span>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else : ?>
        <p class="memi-empty-state"><?= Text::_('COM_MEMIPILATES_COURSE_TYPES_EMPTY'); ?></p>
    <?php endif; ?>
</section>
