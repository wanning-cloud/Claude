<?php

declare(strict_types=1);

// Router for `php -S` in local development: every request goes to index.php.
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
