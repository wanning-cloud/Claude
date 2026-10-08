<?php

declare(strict_types=1);

namespace Cockpit\Import;

use Cockpit\Clock;
use Cockpit\Db;
use Cockpit\Http\HttpException;

/**
 * Import interface for the routine "Podcast-Zahlen holen" (and Markus as a fallback):
 * - JSON after the shared schema (packages/shared/src/import.ts),
 * - Spotify CSV with a stored column mapping.
 * Checks, preview, idempotent commit: the same file twice changes nothing, overlapping files overwrite
 * the same keys instead of adding up.
 */
final class ImportService
{
    public function __construct(private readonly Db $db, private readonly string $schemaFile)
    {
    }

    /**
     * @param array{type: string, content: string, source?: string, period?: array{from: string, to: string}|null} $input
     * @return array{ok: bool, errors: list<string>, duplicate: bool, summary: array{episodes: int, comments: int, replies: int, unknownEpisodes: list<string>}, checksum: string}
     */
    public function preview(array $input): array
    {
        $parsed = $this->parse($input);
        $matcher = new EpisodeMatcher($this->db);
        $unknown = [];
        foreach ($parsed['episodes'] as $row) {
            if ($row['title'] !== null && $matcher->peek($parsed['source'], $row['guid'], $row['title']) === null) {
                $unknown[$row['title']] = true;
            }
        }
        foreach ($parsed['comments'] as $comment) {
            if ($matcher->peek($parsed['source'], null, $comment['episode_title']) === null) {
                $unknown[$comment['episode_title']] = true;
            }
        }
        return [
            'ok' => $parsed['errors'] === [],
            'errors' => $parsed['errors'],
            'duplicate' => $this->isDuplicate($parsed['checksum']),
            'summary' => [
                'episodes' => count(array_unique(array_map(static fn (array $r): string => (string) $r['title'], $parsed['episodes']))),
                'comments' => count($parsed['comments']),
                'replies' => count($parsed['replies']),
                'unknownEpisodes' => array_keys($unknown),
            ],
            'checksum' => $parsed['checksum'],
        ];
    }

    /** @param array{type: string, content: string, source?: string, period?: array{from: string, to: string}|null} $input */
    public function commit(array $input, string $actor): array
    {
        $preview = $this->preview($input);
        if (!$preview['ok']) {
            throw new HttpException(422, 'Import nicht möglich: ' . implode(' ', $preview['errors']), ['errors' => $preview['errors']]);
        }
        if ($preview['duplicate']) {
            return $preview + ['imported' => false, 'message' => 'Diese Datei wurde schon importiert. Nichts geändert.'];
        }
        $parsed = $this->parse($input);
        $this->db->tx(function () use ($parsed, $input, $actor): void {
            $matcher = new EpisodeMatcher($this->db);
            $now = Clock::nowIso();
            $source = $parsed['source'];
            foreach ($parsed['episodes'] as $row) {
                $episodeId = $row['title'] === null ? 0 : $matcher->resolve($source, $row['guid'], $row['title']);
                foreach ($row['metrics'] as $metric => $value) {
                    if ($row['date'] !== null) {
                        $this->db->run(
                            'INSERT INTO metric_daily (episode_id, platform, metric, date, value, source, imported_at) VALUES (?, ?, ?, ?, ?, ?, ?)
                             ON CONFLICT (episode_id, platform, metric, date) DO UPDATE SET value = excluded.value, source = excluded.source, imported_at = excluded.imported_at',
                            [$episodeId, $source, $metric, $row['date'], $value, 'routine', $now],
                        );
                    } else {
                        $this->db->run(
                            'INSERT INTO metric_period (episode_id, platform, metric, period_from, period_to, value, source, imported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                             ON CONFLICT (episode_id, platform, metric, period_from, period_to) DO UPDATE SET value = excluded.value, imported_at = excluded.imported_at',
                            [$episodeId, $source, $metric, $parsed['period']['from'], $parsed['period']['to'], $value, 'routine', $now],
                        );
                    }
                }
            }
            if ($parsed['followers'] !== null) {
                $this->db->run(
                    "INSERT OR REPLACE INTO metric_totals (episode_id, platform, metric, captured_at, value, ref) VALUES (0, ?, 'followers', ?, ?, '')",
                    [$source, $parsed['capturedAt'], $parsed['followers']],
                );
            }
            foreach ($parsed['comments'] as $c) {
                $episodeId = $matcher->resolve($source, null, $c['episode_title']);
                $this->db->run(
                    "INSERT OR IGNORE INTO comments (platform, external_id, episode_id, alias_id, author_name, text, posted_at, status, raw_json)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'new', ?)",
                    [$source, $c['external_ref'], $episodeId > 0 ? $episodeId : null, $episodeId < 0 ? -$episodeId : null,
                        $c['author'] ?: 'Unbekannt', $c['text'], Clock::iso(Clock::parse($c['posted_at'])), json_encode($c, JSON_UNESCAPED_UNICODE)],
                );
            }
            foreach ($parsed['replies'] as $r) {
                $this->markReplySent($r['reply_id'], $r['external_ref'] ?? null, $r['sent_at']);
            }
            $this->db->run(
                'INSERT INTO imports (type, source, kind, checksum, rows, created_at, original) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$input['type'], $source, $parsed['kind'], $parsed['checksum'], count($parsed['episodes']) + count($parsed['comments']) + count($parsed['replies']), $now, $input['content']],
            );
            $this->db->run(
                'INSERT INTO routine_runs (source, kind, started_at, finished_at, ok, message) VALUES (?, ?, ?, ?, 1, ?)',
                [$source, $parsed['kind'], $parsed['capturedAt'], $now, 'Import über das Cockpit'],
            );
            $this->db->audit($actor, 'import', $source, ['kind' => $parsed['kind'], 'checksum' => $parsed['checksum']]);
        });
        return $preview + ['imported' => true, 'message' => 'Import gespeichert.'];
    }

    private function markReplySent(int $replyId, ?string $externalRef, string $sentAt): void
    {
        $reply = $this->db->one("SELECT id, comment_id, text, state FROM reply_queue WHERE id = ? AND channel = 'routine'", [$replyId]);
        if ($reply === null) {
            throw new HttpException(422, "Antwort {$replyId} ist nicht in der Warteschlange.");
        }
        if ($reply['state'] === 'sent') {
            return;
        }
        $at = Clock::iso(Clock::parse($sentAt));
        $this->db->run("UPDATE reply_queue SET state = 'sent', sent_at = ?, external_id = ? WHERE id = ?", [$at, $externalRef, $replyId]);
        $comment = $this->db->one('SELECT platform, episode_id FROM comments WHERE id = ?', [$reply['comment_id']]);
        $this->db->run(
            "INSERT OR IGNORE INTO comments (platform, external_id, parent_id, episode_id, author_name, text, posted_at, status, from_host)
             VALUES (?, ?, ?, ?, 'Markus Wanning', ?, ?, 'done', 1)",
            [$comment['platform'] ?? 'spotify', $externalRef ?? ('reply:' . $replyId), $reply['comment_id'], $comment['episode_id'] ?? null, $reply['text'], $at],
        );
        $this->db->run("UPDATE comments SET status = 'answered', answered_at = ? WHERE id = ?", [$at, $reply['comment_id']]);
    }

    private function isDuplicate(string $checksum): bool
    {
        return $this->db->value('SELECT 1 FROM imports WHERE checksum = ?', [$checksum]) !== null;
    }

    /**
     * @param array{type: string, content: string, source?: string, period?: array{from: string, to: string}|null} $input
     * @return array{errors: list<string>, checksum: string, source: string, kind: string, capturedAt: string, period: array{from: string, to: string}|null, followers: int|null,
     *   episodes: list<array{guid: string|null, title: string|null, date: string|null, metrics: array<string, int>}>,
     *   comments: list<array{external_ref: string, episode_title: string, author: string, text: string, posted_at: string}>,
     *   replies: list<array{reply_id: int, external_ref?: string, sent_at: string}>}
     */
    private function parse(array $input): array
    {
        $content = (string) ($input['content'] ?? '');
        $checksum = hash('sha256', ($input['type'] ?? '') . '|' . ($input['source'] ?? '') . '|' . json_encode($input['period'] ?? null) . '|' . $content);
        $empty = ['errors' => [], 'checksum' => $checksum, 'source' => (string) ($input['source'] ?? ''), 'kind' => 'metrics', 'capturedAt' => Clock::nowIso(),
            'period' => null, 'followers' => null, 'episodes' => [], 'comments' => [], 'replies' => []];
        if (strlen($content) > 5_000_000) {
            return ['errors' => ['Die Datei ist größer als 5 MB.']] + $empty;
        }
        return match ($input['type'] ?? '') {
            'json' => $this->parseJson($content, $empty),
            'csv' => $this->parseCsv($content, $input['period'] ?? null, $empty),
            default => ['errors' => ['Unbekannter Import-Typ. Erlaubt: json, csv.']] + $empty,
        };
    }

    private function parseJson(string $content, array $base): array
    {
        try {
            $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['errors' => ['Kein gültiges JSON: ' . $e->getMessage()]] + $base;
        }
        $schema = json_decode((string) file_get_contents($this->schemaFile), true);
        $errors = JsonSchema::validate($data, $schema);
        if ($errors !== []) {
            return ['errors' => $errors] + $base;
        }
        $kind = $data['kind'];
        if ($kind === 'metrics' && !isset($data['period'])) {
            $errors[] = '$.period: fehlt (bei kind "metrics" Pflicht).';
        }
        if (isset($data['period'])) {
            $errors = [...$errors, ...$this->checkPeriod($data['period'])];
        }
        if ($kind === 'comments' && empty($data['comments'])) {
            $errors[] = '$.comments: fehlt oder ist leer (bei kind "comments" Pflicht).';
        }
        if ($kind === 'reply_sent' && empty($data['replies'])) {
            $errors[] = '$.replies: fehlt oder ist leer (bei kind "reply_sent" Pflicht).';
        }
        if ($kind === 'metrics' && empty($data['episodes']) && !isset($data['show']['followers'])) {
            $errors[] = 'Keine Werte im Import: episodes und show.followers fehlen.';
        }
        $episodes = [];
        foreach ($data['episodes'] ?? [] as $e) {
            $episodes[] = ['guid' => $e['guid'] ?? null, 'title' => $e['title'], 'date' => null, 'metrics' => $e['metrics']];
        }
        return [
            'errors' => $errors,
            'source' => $data['source'],
            'kind' => $kind,
            'capturedAt' => Clock::iso(Clock::parse($data['captured_at'])),
            'period' => $data['period'] ?? null,
            'followers' => $data['show']['followers'] ?? null,
            'episodes' => $episodes,
            'comments' => $data['comments'] ?? [],
            'replies' => $data['replies'] ?? [],
        ] + $base;
    }

    private function parseCsv(string $content, ?array $period, array $base): array
    {
        $base['source'] = 'spotify';
        $mapping = json_decode($this->db->setting('spotify.mapping') ?? 'null', true);
        if (!is_array($mapping)) {
            return ['errors' => ['Für Spotify-CSVs ist noch keine Spalten-Zuordnung gespeichert. Bitte zuerst unter Automatik › Import festlegen.']] + $base;
        }
        try {
            $csv = SpotifyCsv::read($content);
            $rows = SpotifyCsv::apply($csv['headers'], $csv['rows'], $mapping);
        } catch (\RuntimeException $e) {
            return ['errors' => [$e->getMessage()]] + $base;
        }
        $errors = [];
        if (empty($mapping['date'])) {
            if ($period === null) {
                $errors[] = 'Die CSV hat keine Datumsspalte. Bitte den Zeitraum des Exports angeben.';
            } else {
                $errors = $this->checkPeriod($period);
            }
        }
        if ($rows === []) {
            $errors[] = 'Die CSV enthält keine Datenzeilen.';
        }
        $episodes = array_map(static fn (array $r): array => ['guid' => null, 'title' => $r['episode'], 'date' => $r['date'], 'metrics' => $r['metrics']], $rows);
        return ['errors' => $errors, 'source' => 'spotify', 'kind' => 'metrics', 'period' => $period, 'episodes' => $episodes] + $base;
    }

    /** @param array{from?: string, to?: string} $period @return list<string> */
    private function checkPeriod(array $period): array
    {
        $from = (string) ($period['from'] ?? '');
        $to = (string) ($period['to'] ?? '');
        if (!Clock::isDate($from) || !Clock::isDate($to)) {
            return ['Zeitraum: Datum im Format JJJJ-MM-TT angeben.'];
        }
        if ($from > $to) {
            return ['Zeitraum: „von“ liegt nach „bis“.'];
        }
        if ($to > Clock::today()) {
            return ['Zeitraum: „bis“ liegt in der Zukunft.'];
        }
        return [];
    }
}
