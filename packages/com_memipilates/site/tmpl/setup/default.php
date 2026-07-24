<?php
/** @var \Memi\Component\Memipilates\Site\View\Setup\HtmlView $this */

defined('_JEXEC') or die;

$portalView = 'setup';
require dirname(__DIR__) . '/portal/start.php';
require JPATH_ADMINISTRATOR . '/components/com_memipilates/tmpl/setup/default.php';
require dirname(__DIR__) . '/portal/end.php';
