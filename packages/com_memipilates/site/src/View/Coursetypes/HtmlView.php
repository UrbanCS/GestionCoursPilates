<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\View\Coursetypes;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;

/** Public, image-led catalogue of the studio's published course categories. */
final class HtmlView extends BaseHtmlView
{
    /** @var list<array<string,mixed>> */
    public array $courseTypes = [];
    public int $scheduleMenuItemId = 0;

    public function display($tpl = null): void
    {
        $application = Factory::getApplication();
        $this->courseTypes = $this->loadCourseTypes();
        $scheduleMenuItem = $application->getMenu()->getItems(
            'link',
            'index.php?option=com_memipilates&view=schedule',
            true
        );
        $this->scheduleMenuItemId = (int) ($scheduleMenuItem->id ?? 0);

        $document = $application->getDocument();
        $document->setTitle(Text::_('COM_MEMIPILATES_COURSE_TYPES_PAGE_TITLE'));
        $document->getWebAssetManager()->useStyle('com_memipilates.site');

        parent::display($tpl);
    }

    /** @return list<array<string,mixed>> */
    private function loadCourseTypes(): array
    {
        $db = ComponentServices::database();
        $levels = array_values(array_unique(array_map(
            'intval',
            Factory::getApplication()->getIdentity()->getAuthorisedViewLevels()
        )));
        if ($levels === []) {
            $levels = [1];
        }

        $query = $db->getQuery(true)
            ->select([
                'ct.id',
                'ct.title',
                'ct.alias',
                'ct.description',
                'ct.level',
                'ct.image',
            ])
            ->from($db->quoteName('#__memi_course_types', 'ct'))
            ->where('ct.published = 1')
            ->where('ct.archived_at IS NULL')
            ->where('ct.access IN (' . implode(',', $levels) . ')')
            ->order('ct.ordering ASC, ct.title ASC');
        $db->setQuery($query);

        return $db->loadAssocList() ?: [];
    }
}
