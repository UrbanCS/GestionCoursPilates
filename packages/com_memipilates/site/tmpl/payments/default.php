<?php
/** @var \Memi\Component\Memipilates\Site\View\Payments\HtmlView $this */

defined('_JEXEC') or die;

$portalView = 'payments';
require dirname(__DIR__) . '/portal/start.php';
require JPATH_ADMINISTRATOR . '/components/com_memipilates/tmpl/payments/default.php';
require dirname(__DIR__) . '/portal/end.php';
