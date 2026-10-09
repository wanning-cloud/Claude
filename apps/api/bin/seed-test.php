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

// Social media (own tables, never part of the podcast reach). TESTDATEN.
$social = new Cockpit\Repo\Social($db);
$db->setSetting('meta.page_id', 'test-page');
$db->setSetting('meta.ig_user_id', 'test-ig');
$db->setSetting('meta.ig_username', 'dermonteurpodcast');
$db->run("INSERT INTO connections (platform, access_token_enc, account, updated_at) VALUES ('meta', 'v1:TESTDATEN', 'Facebook: Der Monteur Podcast · Instagram: @dermonteurpodcast', ?)", [$now]);
$followers = ['instagram' => 58, 'facebook' => 21];
foreach (Clock::dates(Clock::addDays($today, -40), Clock::addDays($today, -1)) as $k => $date) {
    $followers['instagram'] += mt_rand(0, 3);
    $followers['facebook'] += mt_rand(0, 1);
    $social->storeAccountDaily('instagram', 'followers', $date, $followers['instagram'], 'test');
    $social->storeAccountDaily('facebook', 'followers', $date, $followers['facebook'], 'test');
    $social->storeAccountDaily('instagram', 'reach', $date, 40 + mt_rand(0, 60) + $k, 'test');
    $social->storeAccountDaily('instagram', 'views', $date, 90 + mt_rand(0, 140) + 2 * $k, 'test');
    $social->storeAccountDaily('instagram', 'total_interactions', $date, mt_rand(2, 14), 'test');
    $social->storeAccountDaily('facebook', 'reach', $date, 10 + mt_rand(0, 25), 'test');
    $social->storeAccountDaily('facebook', 'views', $date, 20 + mt_rand(0, 40), 'test');
    $social->storeAccountDaily('facebook', 'interactions', $date, mt_rand(0, 6), 'test');
    $sv = 30 + mt_rand(0, 120);
    $social->storeAccountDaily('youtube_shorts', 'views', $date, $sv, 'test');
    $social->storeAccountDaily('youtube_shorts', 'interactions', $date, (int) round($sv * 0.05), 'test');
    if ($k % 3 === 0) {
        foreach (['instagram' => mt_rand(0, 4), 'facebook' => mt_rand(0, 2), 'fb_gruppe' => mt_rand(0, 3), 'youtube_shorts' => mt_rand(0, 1)] as $src => $v) {
            if ($v > 0) {
                $db->run('INSERT INTO social_visits_daily (date, source, visits) VALUES (?, ?, ?)', [$date, $src, $v]);
            }
        }
    }
}
$captions = [
    ['reel', '3 Fehler bei der Rechnung an Monteure #fehlerderwoche'],
    ['carousel', 'Gewerbe oder privat? Die Checkliste. Ganze Folge 04: Link in Bio'],
    ['reel', 'Umsatzsteuer 7 oder 19 Prozent #rechnungin60sekunden'],
    ['image', 'Neue Folge ist da. Folge 07: Welche Rechtsform?'],
    ['reel', 'Was Disponenten wirklich wollen #dispofrage'],
    ['carousel', 'So rechnest du die Auslastung #rechnungin60sekunden'],
    ['reel', 'Kaution bei Firmenbuchungen #fehlerderwoche'],
];
foreach (range(0, 20) as $i) {
    [$format, $caption] = $captions[$i % count($captions)];
    $published = Clock::iso(Clock::now()->modify('-' . ($i * 30 + 5) . ' hours'));
    $id = $social->upsertPost('instagram', "test-ig-{$i}", ['format' => $format, 'caption' => $caption, 'permalink' => 'https://www.instagram.com/', 'publishedAt' => $published]);
    $reach = mt_rand(60, 420);
    $social->storePostMetrics($id, ['views' => $reach * 2 + mt_rand(0, 90), 'reach' => $reach, 'likes' => mt_rand(3, 30), 'comments' => mt_rand(0, 6), 'saves' => mt_rand(0, 9), 'shares' => mt_rand(0, 5)]);
    if ($format === 'reel') {
        $sid = $social->upsertPost('youtube_shorts', "test-short-{$i}", ['format' => 'short', 'caption' => $caption, 'permalink' => 'https://www.youtube.com/', 'publishedAt' => $published]);
        $social->storePostMetrics($sid, ['views' => mt_rand(80, 900), 'likes' => mt_rand(2, 40), 'comments' => mt_rand(0, 5)]);
    }
    if ($i % 2 === 0) {
        $fid = $social->upsertPost('facebook', "test-fb-{$i}", ['format' => $format === 'reel' ? 'video' : 'image', 'caption' => $caption, 'permalink' => 'https://www.facebook.com/', 'publishedAt' => $published]);
        $social->storePostMetrics($fid, ['views' => mt_rand(20, 160), 'reach' => mt_rand(15, 120), 'likes' => mt_rand(0, 8), 'comments' => mt_rand(0, 3), 'shares' => mt_rand(0, 2)]);
    }
}
$story = $social->upsertPost('instagram', 'test-story-1', ['format' => 'story', 'caption' => null, 'publishedAt' => Clock::iso(Clock::now()->modify('-3 hours')), 'expiresAt' => Clock::iso(Clock::now()->modify('+21 hours'))]);
$social->storePostMetrics($story, ['views' => 64, 'reach' => 51, 'replies' => 1]);
$db->run('INSERT INTO social_group_log (week, answers, note, updated_at) VALUES (?, 4, ?, ?)', [Cockpit\Repo\Social::weekOf(Clock::addDays($today, -7)), 'TESTDATEN: Vermieter-Gruppen', $now]);
foreach ([
    ['instagram', 'vermieterin_nrw', 'Gilt das auch für Kleinunternehmer?', 'test-ig-0'],
    ['facebook', 'Disponent Jörg', 'Wo finde ich die ganze Folge?', 'test-fb-2'],
    ['youtube', 'Handwerker Ben', 'Kurz und knackig, mehr davon!', 'test-short-0'],
] as $i => [$platform, $author, $text, $object]) {
    $db->run('INSERT INTO comments (platform, external_id, video_id, author_name, text, posted_at, status) VALUES (?, ?, ?, ?, ?, ?, ?)', [$platform, "test-social-{$i}", $object, $author, $text, Clock::iso(Clock::now()->modify('-' . ($i + 1) . ' hours')), 'new']);
}
foreach (['instagram', 'instagram_stories', 'facebook', 'meta_comments'] as $source) {
    $db->run('INSERT INTO sync_runs (source, trigger, started_at, finished_at, ok, items, message) VALUES (?, ?, ?, ?, 1, 3, ?)', [$source, 'test', $now, $now, 'TESTDATEN: Lauf erfolgreich.']);
}
echo "TESTDATEN in {$path} geschrieben.\n";
