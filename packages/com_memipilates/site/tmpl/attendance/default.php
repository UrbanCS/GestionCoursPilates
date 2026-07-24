<?php
/** @var \Memi\Component\Memipilates\Site\View\Attendance\HtmlView $this */

defined('_JEXEC') or die;

$portalView = 'attendance';
require dirname(__DIR__) . '/portal/start.php';
require JPATH_ADMINISTRATOR . '/components/com_memipilates/tmpl/attendance/default.php';
require dirname(__DIR__) . '/portal/end.php';
