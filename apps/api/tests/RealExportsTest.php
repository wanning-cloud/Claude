<?php

declare(strict_types=1);

namespace Cockpit\Tests;

use Cockpit\Clock;
use Cockpit\Db;
use Cockpit\Import\CsvProfiles;
use Cockpit\Import\ImportService;
use Cockpit\Repo\Metrics;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support.php';

/** Real CSV exports from Spotify for Creators and Amazon Music for Podcasters (08.10.2026, tests/fixtures/real). */
final class RealExportsTest extends TestCase
{
    private Db $db;
    private ImportService $import;
    private const DIR = __DIR__ . '/fixtures/real/';

    protected function setUp(): void
    {
        Clock::freeze('2026-10-08T20:00:00+02:00');
        $this->db = Support::db();
        foreach ([
            [1, 'Der unbekannte Milliardenmarkt'], [2, 'Monteurzimmer vs. normale Vermietung'], [3, 'Die ehrliche Renditerechnung'],
            [4, 'Gewerbe oder privat?'], [5, 'Der Beherbergungsvertrag'], [6, 'Rechnungsstellung mit Umsatzsteuer'],
            [7, 'Welche Rechtsform für den Start? Einzelunternehmen, GbR oder GmbH'],
        ] as [$n, $title]) {
            $this->db->run(
                'INSERT INTO episodes (guid, number, title, published_at, mp3_url, mp3_path, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                ["monteur-podcast-folge-0{$n}", $n, $title, $n === 7 ? '2026-10-08T06:00:00+02:00' : '2026-10-06T01:05:00+02:00', "https://x/{$n}.mp3", "/{$n}.mp3", Clock::nowIso()],
            );
        }
        $this->import = new ImportService($this->db, __DIR__ . '/../schema/import.schema.json');
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
    }

    private function csv(string $file): array
    {
        return ['type' => 'csv', 'content' => (string) file_get_contents(self::DIR . $file), 'fileName' => $file];
    }

    public function testProfilesAreDetected(): void
    {
        self::assertSame('spotify_performance', CsvProfiles::detect(['Datum', 'Wiedergaben', 'Hörer*innen']));
        self::assertSame('spotify_trends_first7', CsvProfiles::detect(['Name der Folge', 'Veröffentlichungsdatum', 'Leistung', 'Wiedergaben']));
        self::assertSame('amazon_overview', CsvProfiles::detect(['Musikverlag', 'ID des Podcasts', 'Titel', 'Starts', 'Plays', 'Hörer:innen', 'Begeisterte Hörer', 'Follower']));
        self::assertNull(CsvProfiles::detect(['Foo', 'Bar']));
        self::assertSame(['from' => '2026-10-01', 'to' => '2026-10-07'], CsvProfiles::periodFromFileName('Überblick_01.10.2026-07.10.2026.csv'));
    }

    public function testSpotifyPerformanceDailyExport(): void
    {
        $preview = $this->import->preview($this->csv('spotify-performance_9-9-2026--8-10-2026.csv'));
        self::assertTrue($preview['ok'], implode(' ', $preview['errors']));
        self::assertSame('spotify_performance', $preview['summary']['profile']);
        $this->import->commit($this->csv('spotify-performance_9-9-2026--8-10-2026.csv'), 'test');
        self::assertSame(30, (int) $this->db->value("SELECT COUNT(*) FROM metric_daily WHERE platform = 'spotify' AND metric = 'plays' AND episode_id = 0"));
        $metrics = new Metrics($this->db);
        $sum = (float) $this->db->value("SELECT SUM(value) FROM metric_daily WHERE platform = 'spotify' AND metric = 'plays'");
        self::assertSame($sum, $metrics->platformValue('spotify', '2026-09-09', '2026-10-08'));
        self::assertFalse($metrics->hasEpisodeLevel('spotify'), 'show level only: per episode is "–"');
        self::assertTrue($this->import->commit($this->csv('spotify-performance_9-9-2026--8-10-2026.csv'), 'test')['duplicate']);
    }

    public function testSpotifyTrendsFirst7DaysSkipsEmptyCells(): void
    {
        $this->import->commit($this->csv('spotify-trends-first7days.csv'), 'test');
        $rows = $this->db->all("SELECT e.number, t.value FROM metric_totals t JOIN episodes e ON e.id = t.episode_id WHERE t.metric = 'plays_first7d' ORDER BY e.number");
        self::assertSame([[1, 2.0], [2, 2.0], [4, 1.0], [5, 1.0]], array_map(static fn (array $r): array => [(int) $r['number'], (float) $r['value']], $rows));
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM episode_aliases WHERE status = 'pending'"), 'titles match the feed exactly');
    }

    public function testAmazonOverviewTakesPeriodFromFileName(): void
    {
        $file = 'amazon-ueberblick_01.10.2026-07.10.2026.csv';
        $preview = $this->import->preview($this->csv($file));
        self::assertTrue($preview['ok'], implode(' ', $preview['errors']));
        self::assertSame(['from' => '2026-10-01', 'to' => '2026-10-07'], $preview['summary']['period']);
        $this->import->commit($this->csv($file), 'test');
        self::assertSame(0.0, (new Metrics($this->db))->platformValue('amazon', '2026-10-01', '2026-10-07'), 'Amazon reported 0 plays: a real 0');
        self::assertSame(0.0, (float) $this->db->value("SELECT value FROM metric_totals WHERE platform = 'amazon' AND metric = 'followers'"));

        $noName = $this->import->preview(['type' => 'csv', 'content' => (string) file_get_contents(self::DIR . $file)]);
        self::assertFalse($noName['ok'], 'without file name the period must be entered');
    }
}
