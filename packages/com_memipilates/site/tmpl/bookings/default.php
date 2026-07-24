<?php
/** @var \Memi\Component\Memipilates\Site\View\Bookings\HtmlView $this */

defined('_JEXEC') or die;

$portalView = 'bookings';
require dirname(__DIR__) . '/portal/start.php';
require JPATH_ADMINISTRATOR . '/components/com_memipilates/tmpl/bookings/default.php';
require dirname(__DIR__) . '/portal/end.php';
