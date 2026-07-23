<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\View\Kiosk;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;

final class HtmlView extends BaseHtmlView
{
    /** @var list<array<string,mixed>> */
    public array $sessions = [];
    public string $scanUrl = '';
    public string $manualUrl = '';
    /** @var array<string,mixed> */
    public array $settings = [];

    public function display($tpl = null): void
    {
        $this->sessions = $this->loadSessions();
        $this->scanUrl = Route::_('index.php?option=com_memipilates&task=kiosk.scan&format=json', false);
        $this->manualUrl = Route::_('index.php?option=com_memipilates&task=kiosk.manual&format=json', false);
        $settings = ComponentServices::settings();
        $confirmationSeconds = max(1, min(60, $settings->getInt('kiosk_confirmation_seconds', 4)));
        $this->settings = [
            'auto_reset_ms' => $confirmationSeconds * 1000,
            'default_mode' => (string) $settings->get('kiosk_default_mode', 'reader'),
            'max_token_length' => $settings->getInt('qr_max_token_length', 128),
            'sounds' => $settings->getBool('kiosk_sound_enabled', true),
            'timezone' => $settings->timezone()->getName(),
        ];

        $document = Factory::getApplication()->getDocument();
        $document->getWebAssetManager()
            ->useStyle('com_memipilates.site')
            ->useStyle('com_memipilates.kiosk')
            ->useScript('com_memipilates.kiosk-scanner');
        $keys = [
            'COM_MEMIPILATES_KIOSK_READY', 'COM_MEMIPILATES_KIOSK_PROCESSING',
            'COM_MEMIPILATES_KIOSK_SCAN_SUCCESS', 'COM_MEMIPILATES_KIOSK_SCAN_ALREADY_CONFIRMED',
            'COM_MEMIPILATES_KIOSK_NETWORK_ERROR', 'COM_MEMIPILATES_KIOSK_SESSION_REQUIRED',
            'COM_MEMIPILATES_KIOSK_CAMERA_DENIED', 'COM_MEMIPILATES_KIOSK_CAMERA_ERROR',
            'COM_MEMIPILATES_KIOSK_CAMERA_READY', 'COM_MEMIPILATES_KIOSK_CAMERA_STOPPED',
            'COM_MEMIPILATES_KIOSK_TEST_VALID', 'COM_MEMIPILATES_KIOSK_TEST_INVALID',
        ];
        $messages = [];
        foreach ($keys as $key) {
            $messages[$key] = Text::_($key);
            Text::script($key);
        }
        $document->addScriptOptions('com_memipilates.kiosk', [
            'autoResetMs' => $this->settings['auto_reset_ms'],
            'defaultMode' => $this->settings['default_mode'],
            'maxTokenLength' => $this->settings['max_token_length'],
            'messages' => $messages,
            'scanUrl' => $this->scanUrl,
            'manualUrl' => $this->manualUrl,
            'sounds' => $this->settings['sounds'],
            'timeZone' => $this->settings['timezone'],
        ]);

        parent::display($tpl);
    }

    public function formatDate(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));

            return $date->setTimezone(ComponentServices::settings()->timezone())->format(Text::_('DATE_FORMAT_LC5'));
        } catch (\Throwable) {
            return $value;
        }
    }

    /** @return list<array<string,mixed>> */
    private function loadSessions(): array
    {
        $db = ComponentServices::database();
        $from = gmdate('Y-m-d H:i:s', time() - 3600);
        $to = gmdate('Y-m-d H:i:s', time() + 12 * 3600);
        $query = $db->getQuery(true)
            ->select(['s.id', 's.starts_at', 's.ends_at', 's.capacity', 's.reserved_count', 'c.title AS course_title', 'i.display_name AS instructor_name', 'r.title AS room_title'])
            ->from($db->quoteName('#__memi_sessions', 's'))
            ->join('INNER', $db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('LEFT', $db->quoteName('#__memi_instructors', 'i') . ' ON i.id = s.instructor_id')
            ->join('LEFT', $db->quoteName('#__memi_rooms', 'r') . ' ON r.id = s.room_id')
            ->where('s.status IN (' . $db->quote('published') . ', ' . $db->quote('open') . ')')
            ->where('s.starts_at >= :from_at')
            ->where('s.starts_at <= :to_at')
            ->order('s.starts_at ASC');
        $query->bind(':from_at', $from)->bind(':to_at', $to);
        $identity = Factory::getApplication()->getIdentity();
        if (!(bool) $identity->authorise('core.admin', 'com_memipilates')
            && !(bool) $identity->authorise('attendance.all_sessions', 'com_memipilates')) {
            $instructorId = ComponentServices::staffScope()->instructorIdForUser((int) ($identity->id ?? 0));
            if ($instructorId === null) {
                $query->where('1 = 0');
            } else {
                $scopeInstructor = $instructorId;
                $query->where('s.instructor_id = :scope_instructor_id')
                    ->bind(':scope_instructor_id', $scopeInstructor, \Joomla\Database\ParameterType::INTEGER);
            }
        }
        $db->setQuery($query);

        return $db->loadAssocList() ?: [];
    }
}
