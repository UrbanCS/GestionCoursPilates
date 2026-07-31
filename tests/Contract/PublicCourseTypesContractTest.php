<?php

declare(strict_types=1);

namespace MemiPilates\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class PublicCourseTypesContractTest extends TestCase
{
    public function testPublishedCourseTypesHaveAPublicImageCatalogue(): void
    {
        $root = dirname(__DIR__, 2) . '/packages/com_memipilates';
        $view = (string) file_get_contents($root . '/site/src/View/Coursetypes/HtmlView.php');
        $template = (string) file_get_contents($root . '/site/tmpl/coursetypes/default.php');
        $menu = (string) file_get_contents($root . '/site/tmpl/coursetypes/default.xml');

        self::assertStringContainsString("'ct.image'", $view);
        self::assertStringContainsString('ct.published = 1', $view);
        self::assertStringContainsString('ct.archived_at IS NULL', $view);
        self::assertStringContainsString('ct.access IN (', $view);
        self::assertStringContainsString('HTMLHelper::cleanImageURL($image)', $template);
        self::assertStringContainsString('view=schedule&type=', $template);
        self::assertStringContainsString("'index.php?option=com_memipilates&view=schedule'", $view);
        self::assertStringContainsString("'&Itemid=' . \$this->scheduleMenuItemId", $template);
        self::assertStringContainsString('memi-course-types__grid', $template);
        self::assertStringContainsString('COM_MEMIPILATES_COURSE_TYPES', $menu);
    }

    public function testCourseTypeImageCanBeSelectedAndPersisted(): void
    {
        $root = dirname(__DIR__, 2) . '/packages/com_memipilates';
        $form = (string) file_get_contents($root . '/admin/forms/course_type.xml');
        $create = (string) file_get_contents($root . '/admin/src/Service/CatalogService.php');
        $update = (string) file_get_contents($root . '/admin/src/Service/CatalogManagementService.php');
        $catalog = (string) file_get_contents($root . '/admin/tmpl/catalog/default.php');

        self::assertStringContainsString('type="media"', $form);
        self::assertStringContainsString("'image' => \$this->image(\$input)", $create);
        self::assertStringContainsString("'image' => \$this->image(\$input)", $update);
        self::assertStringContainsString("renderField('image')", $catalog);

        foreach ([$create, $update] as $service) {
            self::assertStringContainsString('images/memipilates/', $service);
            self::assertStringContainsString('(?:jpe?g|png|webp|gif|avif)', $service);
            self::assertStringContainsString('joomlaImage://local-images/', $service);
            self::assertStringContainsString('return $relativePath;', $service);
        }
    }

    public function testCourseTypeCatalogueLabelsExistInBothSiteLanguages(): void
    {
        $languageRoot = dirname(__DIR__, 2) . '/packages/com_memipilates/site/language';
        $keys = [
            'COM_MEMIPILATES_COURSE_TYPES',
            'COM_MEMIPILATES_COURSE_TYPES_PAGE_TITLE',
            'COM_MEMIPILATES_COURSE_TYPES_INTRO',
            'COM_MEMIPILATES_COURSE_TYPES_VIEW_SCHEDULE',
            'COM_MEMIPILATES_COURSE_TYPES_EMPTY',
        ];

        foreach (['fr-FR', 'en-GB'] as $language) {
            $contents = (string) file_get_contents($languageRoot . '/' . $language . '/com_memipilates.ini');
            foreach ($keys as $key) {
                self::assertStringContainsString($key . '=', $contents, $language . ': ' . $key);
            }
        }
    }
}
