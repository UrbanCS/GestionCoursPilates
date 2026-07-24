<?php
/** @var \Memi\Component\Memipilates\Site\View\Customers\HtmlView $this */

defined('_JEXEC') or die;

$portalView = 'customers';
require dirname(__DIR__) . '/portal/start.php';
require JPATH_ADMINISTRATOR . '/components/com_memipilates/tmpl/customers/default.php';
require dirname(__DIR__) . '/portal/end.php';
