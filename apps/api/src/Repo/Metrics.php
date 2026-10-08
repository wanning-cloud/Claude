<?php

declare(strict_types=1);

namespace Cockpit\Repo;

use Cockpit\Clock;
use Cockpit\Db;

/**
 * Counting rules of the master prompt, section 7. Every page reads from here, never from a platform API.
 * - Each platform has exactly one lead value; only YouTube, Spotify, feed downloads and the website
 *   player count towards the reach.
 * - Downloads of the Spotify app drop out of the downloads value as soon as Spotify values exist for
 *   the period (they are already in the Spotify number).
 * - Missing data is null with a reason, never 0.
 */
final class Metrics
{
    public const LEAD = [
        'youtube' => 'views',
        'spotify' => 'plays',
        'downloads' => 'downloads',
        'website' => 'starts60',
        'apple' => 'plays',
        'amazon' => 'plays',
        'deezer' => 'streams',
    ];
    public const IN_REACH = ['youtube', 'spotify', 'downloads', 'website'];
    public const ALL = ['youtube', 'spotify', 'downloads', 'website', 'apple', 'amazon', 'deezer'];
    public const ROUTINE = ['spotify', 'apple', 'amazon', 'deezer'];
    public const SPOTIFY_APP = 'Spotify';

    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(private readonly Db $db)
    {
    }

    // ---------------------------------------------------------------- platform totals

    /** Lead value of a platform for the whole show in [from, to]. */
    public function platformValue(string $platform, string $from, string $to): ?float
    {
        if ($platform === 'website') {
            return null;
        }
        if ($platform === 'downloads') {
            if (!$this->downloadsAvailable($from, $to)) {
                return null;
            }
            $sql = 'SELECT COALESCE(SUM(downloads), 0) FROM download_daily WHERE date BETWEEN ? AND ?';
            $params = [$from, $to];
            if ($this->spotifyHasData($from, $to)) {
                $sql .= ' AND app <> ?';
                $params[] = self::SPOTIFY_APP;
            }
            return (float) $this->db->value($sql, $params);
        }
        return $this->metricValue($platform, self::LEAD[$platform], $from, $to);
    }

    /** Sum of a metric for the show (show level rows if the platform has them, else the sum of all episode rows). */
    public function metricValue(string $platform, string $metric, string $from, string $to): ?float
    {
        if ($this->hasDaily($platform, $metric)) {
            $max = $this->db->value('SELECT MAX(date) FROM metric_daily WHERE platform = ? AND metric = ?', [$platform, $metric]);
            if ($max === null || $max < $from) {
                return null;
            }
            $where = $this->hasShowLevel($platform, $metric) ? 'episode_id = 0' : 'episode_id <> 0';
            return (float) $this->db->value(
                "SELECT COALESCE(SUM(value), 0) FROM metric_daily WHERE platform = ? AND metric = ? AND date BETWEEN ? AND ? AND {$where}",
                [$platform, $metric, $from, $to],
            );
        }
        $periodShow = (int) $this->db->value('SELECT COUNT(*) FROM metric_period WHERE platform = ? AND metric = ? AND episode_id = 0', [$platform, $metric]) > 0;
        $row = $this->db->one(
            'SELECT COUNT(*) AS n, SUM(value) AS total FROM metric_period WHERE platform = ? AND metric = ? AND period_from >= ? AND period_to <= ? AND ' . ($periodShow ? 'episode_id = 0' : 'episode_id <> 0'),
            [$platform, $metric, $from, $to],
        );
        return (int) $row['n'] === 0 ? null : (float) $row['total'];
    }

    /** Lead values per episode id (only real episodes, id > 0). @return array<int, float> */
    public function byEpisode(string $platform, string $from, string $to): array
    {
        if ($platform === 'website') {
            return [];
        }
        if ($platform === 'downloads') {
            if (!$this->downloadsAvailable($from, $to)) {
                return [];
            }
            $sql = 'SELECT episode_id, SUM(downloads) AS v FROM download_daily WHERE date BETWEEN ? AND ?';
            $params = [$from, $to];
            if ($this->spotifyHasData($from, $to)) {
                $sql .= ' AND app <> ?';
                $params[] = self::SPOTIFY_APP;
            }
            return $this->pairs($sql . ' GROUP BY episode_id', $params);
        }
        $metric = self::LEAD[$platform];
        if ($this->hasDaily($platform, $metric)) {
            return $this->pairs(
                'SELECT episode_id, SUM(value) AS v FROM metric_daily WHERE platform = ? AND metric = ? AND date BETWEEN ? AND ? AND episode_id > 0 GROUP BY episode_id',
                [$platform, $metric, $from, $to],
            );
        }
        return $this->pairs(
            'SELECT episode_id, SUM(value) AS v FROM metric_period WHERE platform = ? AND metric = ? AND period_from >= ? AND period_to <= ? AND episode_id > 0 GROUP BY episode_id',
            [$platform, $metric, $from, $to],
        );
    }

    /** False when a platform only delivers show level values (e.g. the Spotify "Leistung" export): per episode it is "–", not 0. */
    public function hasEpisodeLevel(string $platform): bool
    {
        if ($platform === 'downloads') {
            return true;
        }
        if ($platform === 'website') {
            return false;
        }
        $metric = self::LEAD[$platform];
        return $this->cache["e:{$platform}"] ??= $this->db->value('SELECT 1 FROM metric_daily WHERE platform = ? AND metric = ? AND episode_id <> 0 LIMIT 1', [$platform, $metric]) !== null
            || $this->db->value('SELECT 1 FROM metric_period WHERE platform = ? AND metric = ? AND episode_id <> 0 LIMIT 1', [$platform, $metric]) !== null;
    }

    /** @return array<int, float> */
    private function pairs(string $sql, array $params): array
    {
        $out = [];
        foreach ($this->db->all($sql, $params) as $row) {
            $out[(int) $row['episode_id']] = (float) $row['v'];
        }
        return $out;
    }

    /** Daily lead values of the show (or one episode) for the chart. @return array<string, array<string, float|null>> date → platform → value */
    public function daily(array $platforms, string $from, string $to, ?int $episodeId = null): array
    {
        $out = [];
        foreach (Clock::dates($from, $to) as $date) {
            $out[$date] = [];
        }
        foreach ($platforms as $platform) {
            $values = $this->dailySeries($platform, $from, $to, $episodeId);
            foreach ($out as $date => $_) {
                $out[$date][$platform] = $values === null ? null : ($values[$date] ?? (($values['__max'] ?? '') >= $date ? 0.0 : null));
            }
        }
        return $out;
    }

    /** @return array<string, float|string>|null date → value plus "__max" (last date with data); null when the platform has no daily data */
    private function dailySeries(string $platform, string $from, string $to, ?int $episodeId): ?array
    {
        if ($platform === 'website') {
            return null;
        }
        if ($platform === 'downloads') {
            if (!$this->downloadsAvailable($from, $to)) {
                return null;
            }
            $rows = $this->db->all(
                'SELECT date, app, SUM(downloads) AS v FROM download_daily WHERE date BETWEEN ? AND ?' . ($episodeId !== null ? ' AND episode_id = ?' : '') . ' GROUP BY date, app',
                $episodeId !== null ? [$from, $to, $episodeId] : [$from, $to],
            );
            $spotifyDays = array_flip($this->spotifyDays($from, $to));
            // Data reaches the day of the newest log line read (all-inkl writes one file per day, so today
            // is usually not in yet). Without that mark, the day of the last successful run counts.
            $hwm = $this->db->setting('logs.hwm');
            $lastRun = $this->db->value("SELECT MAX(started_at) FROM sync_runs WHERE source = 'downloads' AND ok = 1");
            $lastDate = (string) $this->db->value('SELECT MAX(date) FROM download_daily');
            $through = $hwm !== null ? Clock::dateOf($hwm) : max($lastRun === null ? '' : Clock::dateOf((string) $lastRun), $lastDate);
            $out = ['__max' => $through];
            foreach ($rows as $row) {
                if ($row['app'] === self::SPOTIFY_APP && isset($spotifyDays[$row['date']])) {
                    continue;
                }
                $out[$row['date']] = ($out[$row['date']] ?? 0.0) + (float) $row['v'];
            }
            return $out;
        }
        $metric = self::LEAD[$platform];
        if (!$this->hasDaily($platform, $metric)) {
            return null;
        }
        if ($episodeId !== null) {
            $where = 'episode_id = ' . $episodeId;
        } else {
            $where = $this->hasShowLevel($platform, $metric) ? 'episode_id = 0' : 'episode_id <> 0';
        }
        $out = ['__max' => (string) $this->db->value('SELECT MAX(date) FROM metric_daily WHERE platform = ? AND metric = ?', [$platform, $metric])];
        foreach ($this->db->all(
            "SELECT date, SUM(value) AS v FROM metric_daily WHERE platform = ? AND metric = ? AND date BETWEEN ? AND ? AND {$where} GROUP BY date",
            [$platform, $metric, $from, $to],
        ) as $row) {
            $out[$row['date']] = (float) $row['v'];
        }
        return $out;
    }

    /**
     * Cumulative lead values after 1, 3, 7 and 30 days since publication (day 1 = publication day).
     * Null when the day has not been reached in the data yet.
     * @return array<string, array<string, float|null>>
     */
    public function milestones(int $episodeId, string $publishedDate): array
    {
        $out = [];
        foreach (['d1' => 1, 'd3' => 3, 'd7' => 7, 'd30' => 30] as $key => $days) {
            $end = Clock::addDays($publishedDate, $days - 1);
            $out[$key] = [];
            foreach (self::IN_REACH as $platform) {
                if ($platform === 'spotify' && $days === 7) {
                    $out[$key][$platform] = $this->spotifyFirst7($episodeId, $end);
                    continue;
                }
                $series = $platform === 'spotify' && !$this->hasEpisodeLevel('spotify') ? null : $this->dailySeries($platform, $publishedDate, $end, $episodeId);
                if ($series === null || ($series['__max'] ?? '') < $end) {
                    $out[$key][$platform] = null;
                    continue;
                }
                unset($series['__max']);
                $out[$key][$platform] = (float) array_sum($series);
            }
        }
        return $out;
    }

    /** Spotify's own "plays in the first 7 days", valid once it was captured after day 7. */
    private function spotifyFirst7(int $episodeId, string $day7): ?float
    {
        $row = $this->db->one(
            "SELECT value, captured_at FROM metric_totals WHERE platform = 'spotify' AND metric = 'plays_first7d' AND episode_id = ? ORDER BY captured_at DESC LIMIT 1",
            [$episodeId],
        );
        return $row !== null && Clock::dateOf((string) $row['captured_at']) > $day7 ? (float) $row['value'] : null;
    }

    // ---------------------------------------------------------------- reach

    /** @return array{value: float|null, parts: array<string, float|null>} */
    public function reach(string $from, string $to): array
    {
        $parts = [];
        foreach (self::IN_REACH as $platform) {
            $parts[$platform] = $this->platformValue($platform, $from, $to);
        }
        $known = array_filter($parts, static fn ($v): bool => $v !== null);
        return ['value' => $known === [] ? null : (float) array_sum($known), 'parts' => $parts];
    }

    /** Reach per episode id. @return array<int, float> */
    public function reachByEpisode(string $from, string $to): array
    {
        $out = [];
        foreach (self::IN_REACH as $platform) {
            foreach ($this->byEpisode($platform, $from, $to) as $id => $value) {
                $out[$id] = ($out[$id] ?? 0.0) + $value;
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- helpers

    public function spotifyHasData(string $from, string $to): bool
    {
        return $this->db->value("SELECT 1 FROM metric_daily WHERE platform = 'spotify' AND date BETWEEN ? AND ? LIMIT 1", [$from, $to]) !== null
            || $this->db->value("SELECT 1 FROM metric_period WHERE platform = 'spotify' AND period_from <= ? AND period_to >= ? LIMIT 1", [$to, $from]) !== null;
    }

    /** @return list<string> */
    private function spotifyDays(string $from, string $to): array
    {
        $days = array_map('strval', array_column($this->db->all("SELECT DISTINCT date FROM metric_daily WHERE platform = 'spotify' AND date BETWEEN ? AND ?", [$from, $to]), 'date'));
        foreach ($this->db->all("SELECT period_from, period_to FROM metric_period WHERE platform = 'spotify' AND period_from <= ? AND period_to >= ?", [$to, $from]) as $p) {
            foreach (Clock::dates(max($from, (string) $p['period_from']), min($to, (string) $p['period_to'])) as $d) {
                $days[] = $d;
            }
        }
        return array_values(array_unique($days));
    }

    /** Downloads exist once the measurement ran successfully at least once. */
    private function downloadsAvailable(string $from, string $to): bool
    {
        $first = $this->db->value("SELECT MIN(started_at) FROM sync_runs WHERE source = 'downloads' AND ok = 1");
        return $first !== null || $this->db->value('SELECT 1 FROM download_daily WHERE date BETWEEN ? AND ? LIMIT 1', [$from, $to]) !== null;
    }

    private function hasDaily(string $platform, string $metric): bool
    {
        return $this->cache["d:{$platform}:{$metric}"] ??= $this->db->value('SELECT 1 FROM metric_daily WHERE platform = ? AND metric = ? LIMIT 1', [$platform, $metric]) !== null;
    }

    private function hasShowLevel(string $platform, string $metric): bool
    {
        return $this->cache["s:{$platform}:{$metric}"] ??= $this->db->value('SELECT 1 FROM metric_daily WHERE platform = ? AND metric = ? AND episode_id = 0 LIMIT 1', [$platform, $metric]) !== null;
    }
}
