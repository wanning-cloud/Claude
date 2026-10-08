<?php

declare(strict_types=1);

namespace Cockpit\Tests;

use Cockpit\Clock;
use Cockpit\Connectors\AppleReviewsConnector;
use Cockpit\Connectors\DownloadsLogsConnector;
use Cockpit\Connectors\FeedConnector;
use Cockpit\Counting\IabCounter;
use Cockpit\Counting\UserAgents;
use Cockpit\Sync\Schedule;
use Cockpit\Sync\SyncRunner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support.php';

final class ConnectorsTest extends TestCase
{
    protected function setUp(): void
    {
        Clock::freeze('2026-10-08T12:00:00+02:00');
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
    }

    public function testFeedParsingSkipsScheduledEpisodes(): void
    {
        $items = FeedConnector::parse((string) file_get_contents(__DIR__ . '/fixtures/feed.xml'));
        self::assertCount(2, $items);
        self::assertSame(1, $items[0]['number']);
        self::assertSame('/media/folge-01.mp3', $items[0]['mp3_path']);
        self::assertSame(1234, $items[0]['duration_s']);
        self::assertSame(2, $items[1]['number'], 'number from "Folge 02" when itunes:episode is missing');
        self::assertSame(3723, $items[1]['duration_s']);
        self::assertNull(FeedConnector::duration(''));
    }

    public function testFeedSyncIsRepeatable(): void
    {
        $db = Support::db();
        $http = new FakeHttp(['GET https://monteur-podcast.de/feed.xml' => ['status' => 200, 'body' => (string) file_get_contents(__DIR__ . '/fixtures/feed.xml')]]);
        $feed = new FeedConnector($db, $http, 'https://monteur-podcast.de/feed.xml');
        $feed->sync();
        $feed->sync();
        self::assertSame(2, (int) $db->value('SELECT COUNT(*) FROM episodes'));
    }

    public function testAppleReviewParsing(): void
    {
        $reviews = AppleReviewsConnector::parse((string) file_get_contents(__DIR__ . '/fixtures/apple-reviews.json'));
        self::assertCount(2, $reviews);
        self::assertSame(5, $reviews[0]['rating']);
        self::assertSame('Endlich jemand mit Praxis', $reviews[0]['title']);
    }

    public function testAppleReviewsAreImportedOnce(): void
    {
        $db = Support::db();
        $body = (string) file_get_contents(__DIR__ . '/fixtures/apple-reviews.json');
        $http = new FakeHttp(['GET https://itunes.apple.com/de/rss/customerreviews/page=1/' => ['status' => 200, 'body' => $body]]);
        $apple = new AppleReviewsConnector($db, $http, '6819469570');
        self::assertSame(2, $apple->sync()->items);
        self::assertSame(0, $apple->sync()->items);
        self::assertSame(2, (int) $db->value("SELECT COUNT(*) FROM comments WHERE platform = 'apple' AND status = 'new'"));
    }

    public function testLogConnectorReadsIncrementallyAndNeverTwice(): void
    {
        $db = Support::db();
        Support::seedEpisodes($db);
        $dir = sys_get_temp_dir() . '/cockpit-logs-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $ua = 'Overcast/3.0 (+http://overcast.fm/; iOS podcast app)';
        file_put_contents("{$dir}/access_log", Support::logLine('1.1.1.0', '07/Oct/2026:08:00:00 +0200', 'GET', '/media/folge-01.mp3', 200, 5_000_000, $ua) . "\n");
        $connector = fn () => new DownloadsLogsConnector($db, new IabCounter($db, new UserAgents()), $dir);
        $connector()->sync();
        file_put_contents("{$dir}/access_log", Support::logLine('2.2.2.0', '07/Oct/2026:09:00:00 +0200', 'GET', '/media/folge-01.mp3', 200, 5_000_000, $ua) . "\n", FILE_APPEND);
        $connector()->sync();
        $connector()->sync();
        // Rotation: the old file is compressed, a new one starts. Nothing is counted twice.
        $gz = gzopen("{$dir}/access_log.1.gz", 'wb');
        gzwrite($gz, (string) file_get_contents("{$dir}/access_log"));
        gzclose($gz);
        unlink("{$dir}/access_log");
        file_put_contents("{$dir}/access_log", Support::logLine('3.3.3.0', '07/Oct/2026:10:00:00 +0200', 'GET', '/media/folge-02.mp3', 200, 5_000_000, $ua) . "\n");
        $connector()->sync();
        self::assertSame(3, (int) $db->value('SELECT SUM(downloads) FROM download_daily'));
        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);
    }

    public function testScheduleRules(): void
    {
        $now = Clock::now(); // 12:00
        self::assertTrue(Schedule::isDue('feed', null, $now));
        self::assertFalse(Schedule::isDue('feed', '2026-10-08T11:30:00+02:00', $now));
        self::assertTrue(Schedule::isDue('feed', '2026-10-08T11:00:00+02:00', $now));
        self::assertTrue(Schedule::isDue('youtube_comments', '2026-10-08T11:30:00+02:00', $now));
        self::assertFalse(Schedule::isDue('youtube_stats', '2026-10-08T06:00:30+02:00', $now));
        self::assertTrue(Schedule::isDue('youtube_stats', '2026-10-07T06:00:30+02:00', $now));
        self::assertSame('2026-10-09T06:00:00+02:00', Schedule::next('youtube_stats', '2026-10-08T06:00:30+02:00', $now)?->format(DATE_ATOM));
        self::assertFalse(Schedule::isDue('backup', '2026-10-08T03:00:10+02:00', $now));
    }

    public function testRunnerLogsFailuresInPlainLanguageAndLocks(): void
    {
        $db = Support::db();
        $feed = new FeedConnector($db, new FakeHttp(), 'https://monteur-podcast.de/feed.xml');
        $runner = new SyncRunner($db, ['feed' => $feed]);
        $result = $runner->run('feed', 'test');
        self::assertFalse($result['ok']);
        self::assertSame('Feed nicht erreichbar (HTTP 404).', $result['message']);
        self::assertSame(0, (int) $db->value('SELECT ok FROM sync_runs'));

        $db->run('INSERT INTO sync_locks (source, locked_until) VALUES (?, ?)', ['feed', '2026-10-08T12:10:00+02:00']);
        self::assertSame('Läuft bereits. Bitte kurz warten.', $runner->run('feed', 'test')['message']);
    }
}
