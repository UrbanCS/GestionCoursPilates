<?php
/** @var \Memi\Component\Memipilates\Site\View\Offers\HtmlView $this */

defined('_JEXEC') or die;

$portalView = 'offers';
require dirname(__DIR__) . '/portal/start.php';
require JPATH_ADMINISTRATOR . '/components/com_memipilates/tmpl/offers/default.php';
require dirname(__DIR__) . '/portal/end.php';
