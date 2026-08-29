<?php

declare(strict_types=1);

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Session\SessionInterface;
use MemiPwa\Context;
use MemiPwa\Schema;

if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    throw new RuntimeException('Memi PWA requires PHP 8.2 or newer.');
}

defined('MEMI_PWA_ROOT') || define('MEMI_PWA_ROOT', dirname(__DIR__));
defined('_JEXEC') || define('_JEXEC', 1);
defined('JPATH_BASE') || define('JPATH_BASE', realpath(MEMI_PWA_ROOT . '/..') ?: dirname(MEMI_PWA_ROOT));

// Joomla's WebApplication expects a request URI even for scheduled CLI work.
// Supply a synthetic local request so the cron can bootstrap the same services
// without exposing a Web endpoint.
if (PHP_SAPI === 'cli') {
    $_SERVER['HTTP_HOST'] = 'memistudio.ca';
    $_SERVER['SERVER_NAME'] = 'memistudio.ca';
    $_SERVER['SERVER_PORT'] = '443';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/app/cron/run.php';
    $_SERVER['SCRIPT_NAME'] = '/app/cron/run.php';
}

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

foreach (['HttpError', 'Context', 'Api', 'Schema', 'Repository', 'PushService', 'CronRunner'] as $class) {
    require_once MEMI_PWA_ROOT . '/lib/' . $class . '.php';
}

$vendor = MEMI_PWA_ROOT . '/vendor/autoload.php';
if (is_file($vendor)) {
    require_once $vendor;
}

$container = Factory::getContainer();
$container->alias(SessionInterface::class, 'session.web.site');
$application = $container->get(SiteApplication::class);
Factory::$application = $application;
$application->createExtensionNamespaceMap();
$session = $application->getSession();
if ($application->getIdentity() === null) {
    $application->loadIdentity($session->get('user'));
}
$database = $container->get(DatabaseInterface::class);
$context = new Context($application, $database);
Schema::ensure($context);

return $context;
