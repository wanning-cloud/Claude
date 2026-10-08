<?php

declare(strict_types=1);

// CLI entry for the scheduler: php bin/cron.php [due|feed|youtube_comments|...]
require __DIR__ . '/../src/autoload.php';

date_default_timezone_set('Europe/Berlin');
$app = new Cockpit\App(Cockpit\Config::load());
$job = $argv[1] ?? 'due';
$results = $job === 'due' ? $app->runner()->runDue() : [$app->runner()->run($job, 'cli')];
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
exit(in_array(false, array_column($results, 'ok'), true) ? 1 : 0);
