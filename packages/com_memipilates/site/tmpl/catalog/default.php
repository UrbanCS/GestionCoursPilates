<?php
/** @var \Memi\Component\Memipilates\Site\View\Catalog\HtmlView $this */

defined('_JEXEC') or die;

$portalView = 'catalog';
require dirname(__DIR__) . '/portal/start.php';
require JPATH_ADMINISTRATOR . '/components/com_memipilates/tmpl/catalog/default.php';
require dirname(__DIR__) . '/portal/end.php';
