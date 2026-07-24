<?php
/** @var \Memi\Component\Memipilates\Site\View\Sessions\HtmlView $this */

defined('_JEXEC') or die;

$portalView = 'sessions';
require dirname(__DIR__) . '/portal/start.php';
require JPATH_ADMINISTRATOR . '/components/com_memipilates/tmpl/sessions/default.php';
require dirname(__DIR__) . '/portal/end.php';
