<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\View\Checkout;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;

final class HtmlView extends BaseHtmlView
{
    /** @var list<array<string,mixed>> */
    public array $packages = [];
    /** @var array<string,string> */
    public array $square = [];
    public string $currency = 'CAD';
    public string $createEndpoint = '';
    public string $payEndpoint = '';

    public function display($tpl = null): void
    {
        if ((int) (Factory::getApplication()->getIdentity()->id ?? 0) <= 0) {
            Factory::getApplication()->redirect(Route::_('index.php?option=com_users&view=login', false));
            return;
        }
        $db = ComponentServices::database();
        $configuredCurrency = strtoupper((string) ComponentServices::settings()->get('currency', 'CAD'));
        $this->currency = preg_match('/^[A-Z]{3}$/D', $configuredCurrency) ? $configuredCurrency : 'CAD';
        $query = $db->getQuery(true)->select('*')->from($db->quoteName('#__memi_packages'))->where('published = 1')->where('archived_at IS NULL')->order('ordering ASC, title ASC');
        $db->setQuery($query);
        $this->packages = $db->loadAssocList() ?: [];
        $this->square = ComponentServices::payments()->clientConfiguration();
        $this->createEndpoint = Route::_('index.php?option=com_memipilates&task=checkout.createOrder&format=json', false);
        $this->payEndpoint = Route::_('index.php?option=com_memipilates&task=checkout.pay&format=json', false);
        Factory::getApplication()->getDocument()->getWebAssetManager()->useStyle('com_memipilates.site')->useScript('com_memipilates.checkout');
        parent::display($tpl);
    }
}
