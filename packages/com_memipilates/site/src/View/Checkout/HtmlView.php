<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\View\Checkout;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\Database\ParameterType;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;

final class HtmlView extends BaseHtmlView
{
    /** @var list<array<string,mixed>> */
    public array $packages = [];
    /** @var array<string,string> */
    public array $square = [];
    /** @var array<string,mixed>|null */
    public ?array $session = null;
    public int $sessionId = 0;
    public int $sessionSubtotalCents = 0;
    public int $sessionTaxCents = 0;
    public int $sessionTotalCents = 0;
    public int $selectedPackageId = 0;
    public string $currency = 'CAD';
    public string $buyerGivenName = '';
    public string $buyerFamilyName = '';
    public string $buyerEmail = '';
    public string $createEndpoint = '';
    public string $payEndpoint = '';

    public function formatDate(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(ComponentServices::settings()->timezone())
                ->format(Text::_('DATE_FORMAT_LC5'));
        } catch (\Throwable) {
            return $value;
        }
    }

    public function display($tpl = null): void
    {
        $application = Factory::getApplication();
        $sessionId = $application->input->getInt('session_id', 0);
        $this->selectedPackageId = $application->input->getInt('package_id', 0);
        $identity = $application->getIdentity();
        if ((int) ($identity->id ?? 0) <= 0) {
            $return = 'index.php?option=com_memipilates&view=checkout';
            if ($sessionId > 0) {
                $return .= '&session_id=' . $sessionId;
            } elseif ($this->selectedPackageId > 0) {
                $return .= '&package_id=' . $this->selectedPackageId;
            }
            $application->redirect(Route::_(
                'index.php?option=com_users&view=login&return=' . rawurlencode(base64_encode($return)),
                false
            ));
            return;
        }
        $parts = preg_split('/\s+/u', trim((string) ($identity->name ?? '')), 2) ?: [];
        $this->buyerGivenName = (string) ($parts[0] ?? '');
        $this->buyerFamilyName = (string) ($parts[1] ?? '');
        $this->buyerEmail = filter_var((string) ($identity->email ?? ''), FILTER_VALIDATE_EMAIL)
            ? (string) $identity->email
            : '';
        $db = ComponentServices::database();
        $this->sessionId = $sessionId;
        if ($this->sessionId > 0) {
            $sessionId = $this->sessionId;
            $now = gmdate('Y-m-d H:i:s');
            $sessionQuery = $db->getQuery(true)
                ->select([
                    's.id', 's.starts_at', 's.price_cents',
                    'c.title AS course_title', 'i.display_name AS instructor_name', 'r.title AS room_title',
                ])
                ->from($db->quoteName('#__memi_sessions', 's'))
                ->join('INNER', $db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
                ->join('LEFT', $db->quoteName('#__memi_instructors', 'i') . ' ON i.id = s.instructor_id')
                ->join('LEFT', $db->quoteName('#__memi_rooms', 'r') . ' ON r.id = s.room_id')
                ->where('s.id = :session_id')
                ->where('s.archived_at IS NULL')
                ->where('c.archived_at IS NULL')
                ->where('c.published = 1')
                ->where('s.status IN (' . $db->quote('published') . ', ' . $db->quote('open') . ')')
                ->where('s.starts_at > :now')
                ->where('s.price_cents > 0')
                ->bind(':session_id', $sessionId, ParameterType::INTEGER)
                ->bind(':now', $now);
            $db->setQuery($sessionQuery, 0, 1);
            $this->session = $db->loadAssoc() ?: null;
            if (!$this->session) {
                throw new \RuntimeException(Text::_('COM_MEMIPILATES_ERROR_DIRECT_PAYMENT_UNAVAILABLE'), 404);
            }
            $this->sessionSubtotalCents = max(0, (int) $this->session['price_cents']);
            $this->sessionTaxCents = ComponentServices::settings()->calculateTaxCents($this->sessionSubtotalCents);
            $this->sessionTotalCents = $this->sessionSubtotalCents + $this->sessionTaxCents;
        }
        $configuredCurrency = strtoupper((string) ComponentServices::settings()->get('currency', 'CAD'));
        $this->currency = preg_match('/^[A-Z]{3}$/D', $configuredCurrency) ? $configuredCurrency : 'CAD';
        $query = $db->getQuery(true)->select('*')->from($db->quoteName('#__memi_packages'))->where('published = 1')->where('archived_at IS NULL')->order('ordering ASC, title ASC');
        $db->setQuery($query);
        $this->packages = $db->loadAssocList() ?: [];
        if ($this->selectedPackageId > 0) {
            $selectedPackages = array_values(array_filter(
                $this->packages,
                fn (array $package): bool => (int) $package['id'] === $this->selectedPackageId
            ));
            if ($selectedPackages === []) {
                $this->selectedPackageId = 0;
            } else {
                // A checkout opened from the public Tarifs page concerns one
                // explicit offer. Keep the full catalogue only for the generic
                // "Acheter un forfait" route without a package_id.
                $this->packages = $selectedPackages;
            }
        }
        $this->square = ComponentServices::payments()->clientConfiguration();
        $this->createEndpoint = Route::_('index.php?option=com_memipilates&task=checkout.createOrder&format=json', false);
        $this->payEndpoint = Route::_('index.php?option=com_memipilates&task=checkout.pay&format=json', false);
        Factory::getApplication()->getDocument()->getWebAssetManager()->useStyle('com_memipilates.site')->useScript('com_memipilates.checkout');
        parent::display($tpl);
    }
}
