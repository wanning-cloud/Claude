<?php

declare(strict_types=1);

namespace Cockpit\Import;

use Cockpit\Clock;
use Cockpit\Db;

/**
 * Maps a platform row (Spotify, Apple, Amazon, Deezer) to an episode.
 * Sure matches (stored alias, GUID, identical title, "Folge NN") are saved as confirmed aliases.
 * Everything else becomes a pending alias that Markus confirms once. Until then the values are stored
 * under episode_id = -alias_id and move to the episode when the alias is confirmed.
 */
final class EpisodeMatcher
{
    /** @var list<array{id: int, guid: string, number: int|null, title: string, norm: string}>|null */
    private ?array $episodes = null;

    public function __construct(private readonly Db $db)
    {
    }

    public function resolve(string $platform, ?string $guid, string $title): int
    {
        $key = $guid !== null && $guid !== '' ? 'guid:' . $guid : 'title:' . self::normalize($title);
        $alias = $this->db->one('SELECT id, episode_id, status FROM episode_aliases WHERE platform = ? AND foreign_key = ?', [$platform, $key]);
        if ($alias !== null) {
            return $alias['status'] === 'confirmed' ? (int) $alias['episode_id'] : -(int) $alias['id'];
        }
        $episodeId = $this->sureMatch($guid, $title);
        $this->db->run(
            'INSERT INTO episode_aliases (platform, foreign_key, foreign_title, episode_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$platform, $key, $title, $episodeId, $episodeId === null ? 'pending' : 'confirmed', Clock::nowIso()],
        );
        return $episodeId ?? -$this->db->lastId();
    }

    /** Dry run for the import preview: null when the row would need a confirmation. */
    public function peek(string $platform, ?string $guid, string $title): ?int
    {
        $key = $guid !== null && $guid !== '' ? 'guid:' . $guid : 'title:' . self::normalize($title);
        $alias = $this->db->one('SELECT episode_id, status FROM episode_aliases WHERE platform = ? AND foreign_key = ?', [$platform, $key]);
        if ($alias !== null) {
            return $alias['status'] === 'confirmed' ? (int) $alias['episode_id'] : null;
        }
        return $this->sureMatch($guid, $title);
    }

    private function sureMatch(?string $guid, string $title): ?int
    {
        $norm = self::normalize($title);
        $number = preg_match('/\bFolge\s*0*(\d{1,3})\b/iu', $title, $m) ? (int) $m[1] : null;
        foreach ($this->episodes() as $episode) {
            if (($guid !== null && $guid !== '' && $guid === $episode['guid']) || $norm === $episode['norm']) {
                return $episode['id'];
            }
        }
        if ($number !== null) {
            $hits = array_values(array_filter($this->episodes(), static fn (array $e): bool => $e['number'] === $number));
            if (count($hits) === 1) {
                return $hits[0]['id'];
            }
        }
        return null;
    }

    /** @return list<array{id: int, number: int|null, title: string, score: float}> best candidates for a pending alias */
    public function suggestions(string $title, int $limit = 3): array
    {
        $norm = self::normalize($title);
        $out = [];
        foreach ($this->episodes() as $episode) {
            similar_text($norm, $episode['norm'], $pct);
            $out[] = ['id' => $episode['id'], 'number' => $episode['number'], 'title' => $episode['title'], 'score' => round($pct / 100, 2)];
        }
        usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $limit);
    }

    /** Confirms a pending alias and moves its values to the episode. */
    public function confirm(int $aliasId, ?int $episodeId): void
    {
        $this->db->tx(function () use ($aliasId, $episodeId): void {
            $this->db->run('UPDATE episode_aliases SET episode_id = ?, status = ? WHERE id = ?', [$episodeId, $episodeId === null ? 'ignored' : 'confirmed', $aliasId]);
            if ($episodeId === null) {
                return; // ignored rows keep counting towards the platform total only
            }
            foreach (['metric_daily', 'metric_period'] as $table) {
                // Values already present for the episode win; the alias copy is dropped.
                $this->db->run("UPDATE OR IGNORE {$table} SET episode_id = ? WHERE episode_id = ?", [$episodeId, -$aliasId]);
                $this->db->run("DELETE FROM {$table} WHERE episode_id = ?", [-$aliasId]);
            }
            $this->db->run('UPDATE comments SET episode_id = ?, alias_id = NULL WHERE alias_id = ?', [$episodeId, $aliasId]);
        });
    }

    public static function normalize(string $title): string
    {
        $t = mb_strtolower($title);
        $t = preg_replace('/^(folge\s*\d+\s*[:\-–|]\s*)/u', '', $t) ?? $t;
        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t) ?? $t;
        return trim($t);
    }

    /** @return list<array{id: int, guid: string, number: int|null, title: string, norm: string}> */
    private function episodes(): array
    {
        if ($this->episodes === null) {
            $this->episodes = array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'guid' => (string) $r['guid'],
                'number' => $r['number'] === null ? null : (int) $r['number'],
                'title' => (string) $r['title'],
                'norm' => self::normalize((string) $r['title']),
            ], $this->db->all('SELECT id, guid, number, title FROM episodes'));
        }
        return $this->episodes;
    }
}
