<?php

declare(strict_types=1);

namespace Cockpit\Tests;

use Cockpit\Clock;
use Cockpit\Db;
use Cockpit\Import\EpisodeMatcher;
use Cockpit\Import\ImportService;
use Cockpit\Import\JsonSchema;
use Cockpit\Repo\Metrics;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support.php';

/** Reach rules (section 7) and the routine import (C4). All values are TEST DATA. */
final class MetricsAndImportTest extends TestCase
{
    private Db $db;
    private ImportService $import;
    private const SCHEMA = __DIR__ . '/../schema/import.schema.json';

    protected function setUp(): void
    {
        Clock::freeze('2026-10-08T12:00:00+02:00');
        $this->db = Support::db();
        Support::seedEpisodes($this->db);
        $this->import = new ImportService($this->db, self::SCHEMA);
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
    }

    private function json(array $payload): array
    {
        return ['type' => 'json', 'content' => (string) json_encode($payload)];
    }

    private function spotifyMetrics(int $plays1, int $plays2, string $from = '2026-10-01', string $to = '2026-10-07'): array
    {
        return $this->json([
            'source' => 'spotify', 'kind' => 'metrics', 'captured_at' => '2026-10-08T08:31:00+02:00',
            'period' => ['from' => $from, 'to' => $to], 'show' => ['followers' => 42],
            'episodes' => [
                ['title' => 'Folge 01: Warum Monteure?', 'metrics' => ['plays' => $plays1, 'listeners' => 30]],
                ['title' => 'Folge 02: Der richtige Standort', 'metrics' => ['plays' => $plays2]],
            ],
        ]);
    }

    private function downloads(int $episodeId, string $date, string $app, int $n): void
    {
        $this->db->run('INSERT INTO download_daily (episode_id, date, app, downloads) VALUES (?, ?, ?, ?)', [$episodeId, $date, $app, $n]);
    }

    private function youtubeDaily(int $episodeId, string $date, int $views): void
    {
        $this->db->run("INSERT INTO metric_daily (episode_id, platform, metric, date, value, source, imported_at) VALUES (?, 'youtube', 'views', ?, ?, 'api', ?)", [$episodeId, $date, $views, Clock::nowIso()]);
    }

    // ---------------------------------------------------------------- reach

    public function testMissingDataIsNullNotZero(): void
    {
        $metrics = new Metrics($this->db);
        $reach = $metrics->reach('2026-10-01', '2026-10-07');
        self::assertNull($reach['value']);
        foreach ($reach['parts'] as $value) {
            self::assertNull($value);
        }
    }

    public function testReachIsSumOfLeadValuesAndExcludesApple(): void
    {
        $this->youtubeDaily(0, '2026-10-02', 100);
        $this->youtubeDaily(1, '2026-10-02', 60);
        $this->downloads(1, '2026-10-02', 'Apple Podcasts', 25);
        $this->import->commit($this->spotifyMetrics(70, 30), 'test');
        $this->import->commit($this->json([
            'source' => 'apple', 'kind' => 'metrics', 'captured_at' => '2026-10-08T08:40:00+02:00',
            'period' => ['from' => '2026-10-01', 'to' => '2026-10-07'],
            'episodes' => [['title' => 'Folge 01: Warum Monteure?', 'metrics' => ['plays' => 999]]],
        ]), 'test');

        $metrics = new Metrics($this->db);
        $reach = $metrics->reach('2026-10-01', '2026-10-07');
        // YouTube uses channel level (100, not 60), downloads 25, Spotify 70 + 30. Apple is not added.
        self::assertSame(100.0, $reach['parts']['youtube']);
        self::assertSame(25.0, $reach['parts']['downloads']);
        self::assertSame(100.0, $reach['parts']['spotify']);
        self::assertNull($reach['parts']['website']);
        self::assertSame(225.0, $reach['value']);
        self::assertSame(array_sum(array_filter($reach['parts'], static fn ($v): bool => $v !== null)), $reach['value'], 'sum is reproducible from the parts');
        self::assertSame(999.0, $metrics->platformValue('apple', '2026-10-01', '2026-10-07'));
    }

    public function testSpotifyAppDownloadsDropOutOnceSpotifyDataExists(): void
    {
        $this->downloads(1, '2026-10-02', 'Spotify', 40);
        $this->downloads(1, '2026-10-02', 'Overcast', 10);
        $metrics = new Metrics($this->db);
        self::assertSame(50.0, $metrics->platformValue('downloads', '2026-10-01', '2026-10-07'), 'without Spotify data all downloads count');

        $this->import->commit($this->spotifyMetrics(70, 30), 'test');
        $metrics = new Metrics($this->db);
        self::assertSame(10.0, $metrics->platformValue('downloads', '2026-10-01', '2026-10-07'));
        self::assertSame([1 => 10.0], $metrics->byEpisode('downloads', '2026-10-01', '2026-10-07'));
        // Outside the Spotify period the Spotify downloads count again.
        $this->downloads(1, '2026-09-20', 'Spotify', 5);
        self::assertSame(5.0, (new Metrics($this->db))->platformValue('downloads', '2026-09-15', '2026-09-25'));
    }

    public function testPeriodValuesOnlyCountWhenFullyInsideTheRange(): void
    {
        $this->import->commit($this->spotifyMetrics(70, 30, '2026-09-28', '2026-10-04'), 'test');
        $metrics = new Metrics($this->db);
        self::assertSame(100.0, $metrics->platformValue('spotify', '2026-09-28', '2026-10-07'));
        self::assertNull($metrics->platformValue('spotify', '2026-10-01', '2026-10-07'), 'partly covered period is not split up or invented');
    }

    public function testMilestonesAreNullUntilReached(): void
    {
        foreach (['2026-09-05' => 10, '2026-09-06' => 5, '2026-09-07' => 3] as $date => $views) {
            $this->youtubeDaily(2, $date, $views);
            $this->youtubeDaily(0, $date, $views);
        }
        $m = (new Metrics($this->db))->milestones(2, '2026-09-05');
        self::assertSame(10.0, $m['d1']['youtube']);
        self::assertSame(18.0, $m['d3']['youtube']);
        self::assertNull($m['d7']['youtube'], 'day 7 is not in the data yet');
        self::assertNull($m['d1']['spotify']);
    }

    // ---------------------------------------------------------------- import

    public function testImportIsIdempotent(): void
    {
        $first = $this->import->commit($this->spotifyMetrics(70, 30), 'test');
        self::assertTrue($first['imported']);
        $second = $this->import->commit($this->spotifyMetrics(70, 30), 'test');
        self::assertFalse($second['imported']);
        self::assertTrue($second['duplicate']);
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM imports'));
        self::assertSame(100.0, (new Metrics($this->db))->platformValue('spotify', '2026-10-01', '2026-10-07'));
    }

    public function testCorrectedImportOverwritesInsteadOfAdding(): void
    {
        $this->import->commit($this->spotifyMetrics(70, 30), 'test');
        $this->import->commit($this->spotifyMetrics(75, 30), 'test');
        self::assertSame(105.0, (new Metrics($this->db))->platformValue('spotify', '2026-10-01', '2026-10-07'));
    }

    public function testInvalidPayloadIsRejectedWithReadableErrors(): void
    {
        $preview = $this->import->preview($this->json(['source' => 'myspace', 'kind' => 'metrics', 'captured_at' => 'gestern']));
        self::assertFalse($preview['ok']);
        $joined = implode(' ', $preview['errors']);
        self::assertStringContainsString('$.source', $joined);
        self::assertStringContainsString('$.captured_at', $joined);

        $negative = $this->import->preview($this->json([
            'source' => 'spotify', 'kind' => 'metrics', 'captured_at' => '2026-10-08T08:31:00+02:00',
            'period' => ['from' => '2026-10-01', 'to' => '2026-10-07'],
            'episodes' => [['title' => 'X', 'metrics' => ['plays' => -1]]],
        ]));
        self::assertFalse($negative['ok']);

        $noPeriod = $this->import->preview($this->json([
            'source' => 'spotify', 'kind' => 'metrics', 'captured_at' => '2026-10-08T08:31:00+02:00',
            'episodes' => [['title' => 'X', 'metrics' => ['plays' => 1]]],
        ]));
        self::assertFalse($noPeriod['ok']);
    }

    public function testUnknownEpisodeIsKeptAndMovedAfterConfirmation(): void
    {
        $payload = $this->json([
            'source' => 'spotify', 'kind' => 'metrics', 'captured_at' => '2026-10-08T08:31:00+02:00',
            'period' => ['from' => '2026-10-01', 'to' => '2026-10-07'],
            'episodes' => [['title' => 'Warum eigentlich Monteure', 'metrics' => ['plays' => 12]]],
        ]);
        $preview = $this->import->preview($payload);
        self::assertSame(['Warum eigentlich Monteure'], $preview['summary']['unknownEpisodes']);
        $this->import->commit($payload, 'test');

        $metrics = new Metrics($this->db);
        self::assertSame(12.0, $metrics->platformValue('spotify', '2026-10-01', '2026-10-07'), 'value counts for the platform right away');
        self::assertSame([], $metrics->byEpisode('spotify', '2026-10-01', '2026-10-07'));

        $alias = (int) $this->db->value("SELECT id FROM episode_aliases WHERE status = 'pending'");
        $matcher = new EpisodeMatcher($this->db);
        self::assertSame(1, $matcher->suggestions('Warum eigentlich Monteure')[0]['id']);
        $matcher->confirm($alias, 1);
        self::assertSame([1 => 12.0], (new Metrics($this->db))->byEpisode('spotify', '2026-10-01', '2026-10-07'));

        // Next import with the same title maps automatically.
        $this->import->commit($this->json([
            'source' => 'spotify', 'kind' => 'metrics', 'captured_at' => '2026-10-09T08:31:00+02:00',
            'period' => ['from' => '2026-09-24', 'to' => '2026-09-30'],
            'episodes' => [['title' => 'Warum eigentlich Monteure', 'metrics' => ['plays' => 3]]],
        ]), 'test');
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM episode_aliases WHERE status = 'pending'"));
    }

    public function testSpotifyCsvWithStoredMapping(): void
    {
        $csv = "Datum;Folge;Streams;Hörer\n2026-10-01;Folge 01: Warum Monteure?;12;9\n2026-10-02;Folge 01: Warum Monteure?;8;6\n2026-10-02;Folge 02: Der richtige Standort;5;5\n";
        $input = ['type' => 'csv', 'content' => $csv];
        self::assertFalse($this->import->preview($input)['ok'], 'no mapping yet');
        $this->db->setSetting('spotify.mapping', (string) json_encode(['date' => 'Datum', 'episode' => 'Folge', 'metrics' => ['plays' => 'Streams', 'listeners' => 'Hörer']]));
        $preview = $this->import->preview($input);
        self::assertTrue($preview['ok'], implode(' ', $preview['errors']));
        $this->import->commit($input, 'test');
        $metrics = new Metrics($this->db);
        self::assertSame(25.0, $metrics->platformValue('spotify', '2026-10-01', '2026-10-07'));
        self::assertSame([1 => 20.0, 2 => 5.0], $metrics->byEpisode('spotify', '2026-10-01', '2026-10-07'));
        self::assertTrue($this->import->commit($input, 'test')['duplicate']);
    }

    public function testSpotifyCommentsAndReplyRoundTrip(): void
    {
        $this->import->commit($this->json([
            'source' => 'spotify', 'kind' => 'comments', 'captured_at' => '2026-10-08T08:31:00+02:00',
            'comments' => [['external_ref' => 'sp-1', 'episode_title' => 'Folge 02: Der richtige Standort', 'author' => 'Hörer A', 'text' => 'Gilt das auch für Kleinstädte?', 'posted_at' => '2026-10-07T19:00:00+02:00']],
        ]), 'test');
        $commentId = (int) $this->db->value("SELECT id FROM comments WHERE platform = 'spotify'");
        self::assertSame(2, (int) $this->db->value('SELECT episode_id FROM comments WHERE id = ?', [$commentId]));

        (new \Cockpit\Repo\Comments($this->db))->reply($commentId, 'Ja, gerade dort.', 'markus', null);
        $queue = (new \Cockpit\Repo\Comments($this->db))->queue();
        self::assertCount(1, $queue);
        self::assertSame('sp-1', $queue[0]['externalRef']);

        $this->import->commit($this->json([
            'source' => 'spotify', 'kind' => 'reply_sent', 'captured_at' => '2026-10-12T08:40:00+02:00',
            'replies' => [['reply_id' => $queue[0]['id'], 'sent_at' => '2026-10-12T08:39:00+02:00']],
        ]), 'test');
        self::assertSame([], (new \Cockpit\Repo\Comments($this->db))->queue());
        self::assertSame('answered', $this->db->value('SELECT status FROM comments WHERE id = ?', [$commentId]));
    }

    // ---------------------------------------------------------------- schema

    public function testPhpValidatorKnowsEveryKeywordOfTheSharedSchema(): void
    {
        $schema = json_decode((string) file_get_contents(self::SCHEMA), true);
        self::assertSame([], JsonSchema::unsupportedKeywords($schema));
    }
}
