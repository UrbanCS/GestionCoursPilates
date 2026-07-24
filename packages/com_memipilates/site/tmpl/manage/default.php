<?php
/** @var \Memi\Component\Memipilates\Site\View\Manage\HtmlView $this */

defined('_JEXEC') or die;

$portalView = 'manage';
require dirname(__DIR__) . '/portal/start.php';
require JPATH_ADMINISTRATOR . '/components/com_memipilates/tmpl/dashboard/default.php';
require dirname(__DIR__) . '/portal/end.php';
