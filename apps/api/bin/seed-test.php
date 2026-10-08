<?php

declare(strict_types=1);

/*
 * TEST DATA ONLY. Fills a separate SQLite database with invented values for local development and
 * Playwright. Refuses to run unless APP_ENV is dev or test, and never touches a database that
 * already holds real runs. Usage: APP_ENV=test DATABASE_PATH=/tmp/x.sqlite php bin/seed-test.php
 */

require __DIR__ . '/../src/autoload.php';
date_default_timezone_set('Europe/Berlin');

use Cockpit\Clock;
use Cockpit\Db;

$env = getenv('APP_ENV');
$path = getenv('DATABASE_PATH');
if (!in_array($env, ['dev', 'test'], true) || !$path) {
    fwrite(STDERR, "Nur mit APP_ENV=dev|test und eigenem DATABASE_PATH.\n");
    exit(1);
}
if (is_file($path)) {
    unlink($path);
}
$db = new Db($path);
$db->migrate();
$db->setSetting('test_data', 'TESTDATEN – nur für Entwicklung und Tests');

$today = Clock::today();
$titles = [
    1 => 'Der unbekannte Milliardenmarkt',
    2 => 'Monteurzimmer vs. normale Vermietung',
    3 => 'Die ehrliche Renditerechnung',
    4 => 'Gewerbe oder privat?',
    5 => 'Der Beherbergungsvertrag',
    6 => 'Rechnungsstellung mit Umsatzsteuer',
    7 => 'Welche Rechtsform für den Start? Einzelunternehmen, GbR oder GmbH',
];
$now = Clock::nowIso();
foreach ($titles as $n => $title) {
    $published = Clock::addDays($today, -((8 - $n) * 4));
    $db->run(
        'INSERT INTO episodes (guid, number, title, published_at, mp3_url, mp3_path, bytes, duration_s, youtube_video_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ["test-folge-{$n}", $n, $title, $published . 'T06:00:00+02:00', "https://monteur-podcast.de/media/audio/folge-0{$n}.mp3", "/media/audio/folge-0{$n}.mp3", 20_000_000, 600, "testvid{$n}", $now],
    );
    $db->run('INSERT INTO youtube_videos (video_id, title, published_at, updated_at) VALUES (?, ?, ?, ?)', ["testvid{$n}", "Folge {$n}: {$title}", $published . 'T06:00:00+02:00', $now]);
}
mt_srand(7);
$episodes = $db->all('SELECT id, published_at FROM episodes');
foreach (Clock::dates(Clock::addDays($today, -40), Clock::addDays($today, -1)) as $date) {
    $channel = 0;
    foreach ($episodes as $e) {
        $age = Clock::daysBetween(Clock::dateOf((string) $e['published_at']), $date);
        if ($age < 0) {
            continue;
        }
        $views = (int) round(60 / (1 + $age * 0.35) + mt_rand(0, 6));
        $channel += $views;
        $db->run("INSERT INTO metric_daily VALUES (?, 'youtube', 'views', ?, ?, 'test', ?)", [$e['id'], $date, $views, $now]);
        foreach (['Apple Podcasts' => 0.5, 'Spotify' => 0.25, 'Overcast' => 0.1, 'Andere' => 0.15] as $app => $share) {
            $dl = (int) round(($views * 0.9 + mt_rand(0, 3)) * $share);
            if ($dl > 0) {
                $db->run('INSERT INTO download_daily VALUES (?, ?, ?, ?)', [$e['id'], $date, $app, $dl]);
            }
        }
    }
    $db->run("INSERT INTO metric_daily VALUES (0, 'youtube', 'views', ?, ?, 'test', ?)", [$date, $channel + mt_rand(0, 8), $now]);
    $db->run("INSERT INTO metric_daily VALUES (0, 'youtube', 'averageViewPercentage', ?, ?, 'test', ?)", [$date, 38 + mt_rand(0, 12), $now]);
    $db->run("INSERT INTO metric_daily VALUES (0, 'youtube', 'estimatedMinutesWatched', ?, ?, 'test', ?)", [$date, $channel * 4, $now]);
}
// Spotify: one weekly routine import (period values).
$from = Clock::addDays($today, -7);
$to = Clock::addDays($today, -1);
foreach ($episodes as $e) {
    $db->run("INSERT INTO metric_period VALUES (?, 'spotify', 'plays', ?, ?, ?, 'test', ?)", [$e['id'], $from, $to, mt_rand(15, 70), $now]);
}
$db->run("INSERT INTO routine_runs (source, kind, started_at, finished_at, ok, message) VALUES ('spotify', 'metrics', ?, ?, 1, 'TESTDATEN')", [$now, $now]);
foreach (['feed', 'youtube_stats', 'youtube_comments', 'apple_reviews', 'downloads', 'backup'] as $i => $source) {
    $ok = $source !== 'apple_reviews' ? 1 : 0;
    $db->run('INSERT INTO sync_runs (source, trigger, started_at, finished_at, ok, items, message) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        $source, 'test', Clock::iso(Clock::now()->modify('-' . (20 + $i) . ' minutes')), Clock::iso(Clock::now()->modify('-' . (19 + $i) . ' minutes')), $ok, 3,
        $ok ? 'TESTDATEN: Lauf erfolgreich.' : 'Apple-Bewertungen (de) nicht erreichbar (HTTP 503).',
    ]);
}
$comments = [
    ['youtube', 'Hörerin K.', 'Wie hoch setzt du die Kaution bei Firmenbuchungen an? Bei uns zahlen manche gar keine.', 7, 'new'],
    ['youtube', 'Thomas R.', 'Starke Folge. Bitte mal eine Folge zu Reinigung zwischen den Belegungen.', 6, 'new'],
    ['spotify', 'Anna', 'Gilt das mit den 7 % auch, wenn die Firma direkt bucht?', 6, 'new'],
    ['apple', 'Vermieter aus Bingen', 'Endlich jemand, der aus der Praxis spricht. Kurz und klar.', null, 'new'],
    ['youtube', 'M. Schulz', 'Danke für die Rechenbeispiele!', 3, 'answered'],
];
foreach ($comments as $i => [$platform, $author, $text, $episode, $status]) {
    $episodeId = $episode === null ? null : $db->value('SELECT id FROM episodes WHERE number = ?', [$episode]);
    $db->run(
        'INSERT INTO comments (platform, external_id, episode_id, video_id, author_name, text, rating, title, posted_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$platform, "test-{$i}", $episodeId, $platform === 'youtube' ? "testvid{$episode}" : null, $author, $text, $platform === 'apple' ? 5 : null,
            $platform === 'apple' ? 'Praxis statt Theorie' : null, Clock::iso(Clock::now()->modify('-' . ($i * 7 + 2) . ' hours')), $status],
    );
    if ($status === 'answered') {
        $parent = $db->lastId();
        $db->run("INSERT INTO comments (platform, external_id, parent_id, episode_id, author_name, text, posted_at, status, from_host) VALUES ('youtube', 'test-reply', ?, ?, 'Markus Wanning', 'Gern! Folge 8 geht genau da weiter.', ?, 'done', 1)", [$parent, $episodeId, $now]);
    }
}
$db->run("INSERT INTO episode_aliases (platform, foreign_key, foreign_title, status, created_at) VALUES ('spotify', 'title:trailer', 'Trailer: Darum geht es im Monteur-Podcast', 'pending', ?)", [$now]);
echo "TESTDATEN in {$path} geschrieben.\n";
