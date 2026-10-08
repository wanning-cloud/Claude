<?php

declare(strict_types=1);

namespace Cockpit\Tests;

use Cockpit\Clock;
use Cockpit\Counting\IabCounter;
use Cockpit\Counting\LogParser;
use Cockpit\Counting\UserAgents;
use Cockpit\Db;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support.php';

/** Counting rules of section 7.3: duplicates, bots, HEAD, Range 0-1, Spotify user agent. */
final class IabCounterTest extends TestCase
{
    private Db $db;
    private IabCounter $counter;

    private const APPLE = 'AppleCoreMedia/1.0.0.21E236 (iPhone; U; CPU OS 17_4 like Mac OS X; de_de)';
    private const SPOTIFY = 'Spotify/8.9.38 iOS/17.4 (iPhone15,2)';
    private const MB = 1_440_000;

    protected function setUp(): void
    {
        Clock::freeze('2026-10-08T12:00:00+02:00');
        $this->db = Support::db();
        Support::seedEpisodes($this->db);
        $this->counter = new IabCounter($this->db, new UserAgents());
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
    }

    private function feed(string ...$lines): void
    {
        foreach ($lines as $line) {
            $event = LogParser::parse($line);
            self::assertNotNull($event, "unparsable: {$line}");
            $this->counter->add($event);
        }
    }

    private function downloads(?string $app = null): int
    {
        return (int) $this->db->value('SELECT COALESCE(SUM(downloads), 0) FROM download_daily' . ($app ? ' WHERE app = ?' : ''), $app ? [$app] : []);
    }

    public function testFullDownloadCountsOnce(): void
    {
        $this->feed(Support::logLine('1.2.3.0', '07/Oct/2026:08:00:00 +0200', 'GET', '/media/folge-01.mp3', 200, 86_400_000, self::APPLE));
        self::assertSame(1, $this->downloads());
        self::assertSame(1, $this->downloads('Apple Podcasts'));
    }

    public function testSameIpUaEpisodeWithin24HoursCountsOnce(): void
    {
        $this->feed(
            Support::logLine('1.2.3.0', '07/Oct/2026:08:00:00 +0200', 'GET', '/media/folge-01.mp3', 200, 5 * self::MB, self::APPLE),
            Support::logLine('1.2.3.0', '07/Oct/2026:20:00:00 +0200', 'GET', '/media/folge-01.mp3', 200, 5 * self::MB, self::APPLE),
            Support::logLine('1.2.3.0', '08/Oct/2026:07:59:00 +0200', 'GET', '/media/folge-01.mp3', 200, 5 * self::MB, self::APPLE),
        );
        self::assertSame(1, $this->downloads());
    }

    public function testNewWindowAfter24HoursCountsAgain(): void
    {
        $this->feed(
            Support::logLine('1.2.3.0', '06/Oct/2026:08:00:00 +0200', 'GET', '/media/folge-01.mp3', 200, 5 * self::MB, self::APPLE),
            Support::logLine('1.2.3.0', '07/Oct/2026:08:00:01 +0200', 'GET', '/media/folge-01.mp3', 200, 5 * self::MB, self::APPLE),
        );
        self::assertSame(2, $this->downloads());
    }

    public function testWindowAcrossMidnightUsesPreviousDaySalt(): void
    {
        $this->feed(
            Support::logLine('1.2.3.0', '06/Oct/2026:23:50:00 +0200', 'GET', '/media/folge-01.mp3', 200, 5 * self::MB, self::APPLE),
            Support::logLine('1.2.3.0', '07/Oct/2026:00:10:00 +0200', 'GET', '/media/folge-01.mp3', 200, 5 * self::MB, self::APPLE),
        );
        self::assertSame(1, $this->downloads());
    }

    public function testDifferentEpisodeOrUserAgentCountsSeparately(): void
    {
        $this->feed(
            Support::logLine('1.2.3.0', '07/Oct/2026:08:00:00 +0200', 'GET', '/media/folge-01.mp3', 200, 5 * self::MB, self::APPLE),
            Support::logLine('1.2.3.0', '07/Oct/2026:08:01:00 +0200', 'GET', '/media/folge-02.mp3', 200, 5 * self::MB, self::APPLE),
            Support::logLine('1.2.3.0', '07/Oct/2026:08:02:00 +0200', 'GET', '/media/folge-01.mp3', 200, 5 * self::MB, 'Overcast/3.0 (+http://overcast.fm/; iOS podcast app)'),
        );
        self::assertSame(3, $this->downloads());
    }

    public function testBotsAreExcluded(): void
    {
        $this->feed(
            Support::logLine('5.6.7.0', '07/Oct/2026:08:00:00 +0200', 'GET', '/media/folge-01.mp3', 200, 5 * self::MB, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'),
            Support::logLine('5.6.7.1', '07/Oct/2026:08:00:00 +0200', 'GET', '/media/folge-01.mp3', 200, 5 * self::MB, 'curl/8.4.0'),
            Support::logLine('5.6.7.2', '07/Oct/2026:08:00:00 +0200', 'GET', '/media/folge-01.mp3', 200, 5 * self::MB, '-'),
        );
        self::assertSame(0, $this->downloads());
    }

    public function testHeadRequestsAreExcluded(): void
    {
        $this->feed(Support::logLine('1.2.3.0', '07/Oct/2026:08:00:00 +0200', 'HEAD', '/media/folge-01.mp3', 200, 86_400_000, self::APPLE));
        self::assertSame(0, $this->downloads());
    }

    public function testRangeProbeBytes0To1IsNotCounted(): void
    {
        // Range: bytes=0-1 → 206 with 2 bytes; below the one-minute threshold.
        $this->feed(Support::logLine('1.2.3.0', '07/Oct/2026:08:00:00 +0200', 'GET', '/media/folge-01.mp3', 206, 2, self::APPLE));
        self::assertSame(0, $this->downloads());
    }

    public function testPartialRequestsAddUpToOneMinute(): void
    {
        $this->feed(
            Support::logLine('1.2.3.0', '07/Oct/2026:08:00:00 +0200', 'GET', '/media/folge-01.mp3', 206, 2, self::APPLE),
            Support::logLine('1.2.3.0', '07/Oct/2026:08:00:01 +0200', 'GET', '/media/folge-01.mp3', 206, 1_000_000, self::APPLE),
        );
        self::assertSame(0, $this->downloads());
        $this->feed(Support::logLine('1.2.3.0', '07/Oct/2026:08:00:02 +0200', 'GET', '/media/folge-01.mp3', 206, 500_000, self::APPLE));
        self::assertSame(1, $this->downloads());
    }

    public function testErrorStatusIsExcluded(): void
    {
        $this->feed(
            Support::logLine('1.2.3.0', '07/Oct/2026:08:00:00 +0200', 'GET', '/media/folge-01.mp3', 404, 5 * self::MB, self::APPLE),
            Support::logLine('1.2.3.1', '07/Oct/2026:08:00:00 +0200', 'GET', '/media/folge-01.mp3', 304, 0, self::APPLE),
        );
        self::assertSame(0, $this->downloads());
    }

    public function testSpotifyUserAgentIsDetected(): void
    {
        $this->feed(Support::logLine('9.9.9.0', '07/Oct/2026:08:00:00 +0200', 'GET', '/media/folge-01.mp3', 200, 5 * self::MB, self::SPOTIFY));
        self::assertSame(1, $this->downloads('Spotify'));
    }

    public function testUnknownFilesAreIgnored(): void
    {
        $this->feed(Support::logLine('1.2.3.0', '07/Oct/2026:08:00:00 +0200', 'GET', '/index.html', 200, 5 * self::MB, self::APPLE));
        self::assertSame(0, $this->downloads());
    }

    public function testNoIpIsStored(): void
    {
        $this->feed(Support::logLine('203.0.113.77', '07/Oct/2026:08:00:00 +0200', 'GET', '/media/folge-01.mp3', 200, 5 * self::MB, self::APPLE));
        $dump = json_encode($this->db->all('SELECT * FROM download_windows')) . json_encode($this->db->all('SELECT * FROM download_daily'));
        self::assertStringNotContainsString('203.0.113', (string) $dump);
    }

    public function testCleanupRemovesOldWindowsAndSalts(): void
    {
        $this->feed(Support::logLine('1.2.3.0', '01/Oct/2026:08:00:00 +0200', 'GET', '/media/folge-01.mp3', 200, 5 * self::MB, self::APPLE));
        $this->counter->cleanup();
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM download_windows'));
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM daily_salts WHERE day < '2026-10-06'"));
        self::assertSame(1, $this->downloads(), 'counted downloads stay');
    }

    public function testThresholdFromFeed(): void
    {
        self::assertSame(1_440_000, IabCounter::threshold(86_400_000, 3600));
        self::assertSame(IabCounter::FALLBACK_BYTES_PER_MINUTE, IabCounter::threshold(null, 3600));
        self::assertSame(IabCounter::FALLBACK_BYTES_PER_MINUTE, IabCounter::threshold(1000, 0));
    }

    public function testLogParserAcceptsVhostPrefixAndRejectsGarbage(): void
    {
        $event = LogParser::parse('monteur-podcast.de ' . Support::logLine('1.2.3.0', '07/Oct/2026:08:00:00 +0200', 'GET', '/media/folge%2001.mp3?x=1', 206, 123, self::APPLE));
        self::assertNotNull($event);
        self::assertSame('/media/folge 01.mp3', $event['path']);
        self::assertSame(206, $event['status']);
        self::assertNull(LogParser::parse('not a log line'));
    }
}
