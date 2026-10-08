<?php

declare(strict_types=1);

// Front controller. On the server it lives in /podcast-admin/analytics/api/ with the code in app/
// (denied by .htaccess); in the repo the code sits one level up.
$appDir = is_dir(__DIR__ . '/app/src') ? __DIR__ . '/app' : dirname(__DIR__);
require $appDir . '/src/autoload.php';

date_default_timezone_set('Europe/Berlin');
ini_set('display_errors', '0');

$config = Cockpit\Config::load();
$base = rtrim($config->get('API_BASE_PATH', '/podcast-admin/analytics/api'), '/');
$app = new Cockpit\App($config);
$app->handle(Cockpit\Http\Request::fromGlobals($base))->send();
