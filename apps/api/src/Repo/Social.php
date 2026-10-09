<?php

declare(strict_types=1);

namespace Cockpit\Repo;

use Cockpit\Clock;
use Cockpit\Db;
use Cockpit\Http\HttpException;

/**
 * Social media area: Instagram, the Facebook page and YouTube Shorts. Reads and writes only the
 * social_* tables. Nothing here is ever added to the podcast reach (Metrics), and no podcast value
 * is added here.
 */
final class Social
{
    public const PLATFORMS = ['instagram', 'facebook', 'youtube_shorts'];

    public const NAMES = ['instagram' => 'Instagram', 'facebook' => 'Facebook-Seite', 'youtube_shorts' => 'YouTube Shorts'];

    /** Connector that feeds each channel (for the data stamp). */
    public const SOURCE = ['instagram' => 'instagram', 'facebook' => 'facebook', 'youtube_shorts' => 'youtube_stats'];

    /** Hashtag (lower case, without #) → series. Set by hand in the cockpit when a post has none. */
    public const SERIES_TAGS = [
        'fehlerderwoche' => 'Fehler der Woche',
        'rechnungin60sekunden' => 'Rechnung in 60 Sekunden',
        'dispofrage' => 'Dispo-Frage der Woche',
        'dispofragederwoche' => 'Dispo-Frage der Woche',
        'antwortderwoche' => 'Antwort der Woche',
    ];
    public const SERIES_EPISODE_CLIP = 'Clip aus einer Folge';

    /** Goals of the social media strategy (90 days, set low on purpose). */
    public const GOAL_INSTAGRAM_FOLLOWERS = 150;
    public const GOAL_YOUTUBE_SUBSCRIBERS = 100;
    public const GOAL_CLIPS_PER_WEEK = 7;

    public function __construct(private readonly Db $db)
    {
    }

    // ---------------------------------------------------------------- writing (connectors)

    /** @param array{format: string, caption?: string|null, permalink?: string|null, thumbnail?: string|null, publishedAt: string, expiresAt?: string|null} $post */
    public function upsertPost(string $platform, string $externalId, array $post): int
    {
        $now = Clock::nowIso();
        $caption = $post['caption'] ?? null;
        $series = self::detectSeries($caption);
        $existing = $this->db->one('SELECT id, series_manual FROM social_posts WHERE platform = ? AND external_id = ?', [$platform, $externalId]);
        if ($existing !== null) {
            $this->db->run(
                'UPDATE social_posts SET format = ?, caption = ?, permalink = COALESCE(?, permalink), thumbnail_url = COALESCE(?, thumbnail_url),
                   published_at = ?, expires_at = ?, updated_at = ?' . ((int) $existing['series_manual'] === 1 ? '' : ', series = ?') . ' WHERE id = ?',
                array_merge(
                    [$post['format'], $caption, $post['permalink'] ?? null, $post['thumbnail'] ?? null, $post['publishedAt'], $post['expiresAt'] ?? null, $now],
                    (int) $existing['series_manual'] === 1 ? [] : [$series],
                    [(int) $existing['id']],
                ),
            );
            return (int) $existing['id'];
        }
        $this->db->run(
            'INSERT INTO social_posts (platform, external_id, format, caption, permalink, thumbnail_url, published_at, series, expires_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$platform, $externalId, $post['format'], $caption, $post['permalink'] ?? null, $post['thumbnail'] ?? null, $post['publishedAt'], $series, $post['expiresAt'] ?? null, $now],
        );
        return $this->db->lastId();
    }

    /** Lifetime values of a post as of today. Missing values are left out, never stored as 0. @param array<string, float|int|null> $values */
    public function storePostMetrics(int $postId, array $values): void
    {
        $now = Clock::nowIso();
        $today = Clock::today();
        foreach ($values as $metric => $value) {
            if ($value === null) {
                continue;
            }
            $this->db->run(
                'INSERT INTO social_post_metrics_daily (post_id, date, metric, value, captured_at) VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT (post_id, date, metric) DO UPDATE SET value = excluded.value, captured_at = excluded.captured_at',
                [$postId, $today, $metric, (float) $value, $now],
            );
        }
    }

    public function storeAccountDaily(string $platform, string $metric, string $date, float $value, string $source = 'api'): void
    {
        $this->db->run(
            'INSERT INTO social_account_metrics_daily (platform, metric, date, value, source, imported_at) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (platform, metric, date) DO UPDATE SET value = excluded.value, source = excluded.source, imported_at = excluded.imported_at',
            [$platform, $metric, $date, $value, $source, Clock::nowIso()],
        );
    }

    public function lastAccountDate(string $platform, string $metric): ?string
    {
        $v = $this->db->value('SELECT MAX(date) FROM social_account_metrics_daily WHERE platform = ? AND metric = ?', [$platform, $metric]);
        return $v === null ? null : (string) $v;
    }

    public static function detectSeries(?string $caption): ?string
    {
        if ($caption === null || $caption === '') {
            return null;
        }
        if (preg_match_all('/#([\p{L}\p{N}_]+)/u', $caption, $m)) {
            foreach ($m[1] as $tag) {
                $key = mb_strtolower($tag);
                if (isset(self::SERIES_TAGS[$key])) {
                    return self::SERIES_TAGS[$key];
                }
            }
        }
        if (preg_match('/\b(ganze\s+)?folge\s*#?\d{1,3}\b/iu', $caption)) {
            return self::SERIES_EPISODE_CLIP;
        }
        return null;
    }

    /** Markus sets or clears the series of a post by hand; manual values are never overwritten. */
    public function setSeries(int $id, ?string $series, string $actor): array
    {
        if ($this->db->value('SELECT 1 FROM social_posts WHERE id = ?', [$id]) === null) {
            throw new HttpException(404, 'Beitrag nicht gefunden.');
        }
        $series = $series === null || trim($series) === '' ? null : trim($series);
        if ($series !== null && mb_strlen($series) > 60) {
            throw new HttpException(422, 'Serienname ist zu lang (höchstens 60 Zeichen).');
        }
        $this->db->run('UPDATE social_posts SET series = ?, series_manual = ? WHERE id = ?', [$series, $series === null ? 0 : 1, $id]);
        if ($series === null) {
            $caption = $this->db->value('SELECT caption FROM social_posts WHERE id = ?', [$id]);
            $this->db->run('UPDATE social_posts SET series = ? WHERE id = ?', [self::detectSeries($caption === null ? null : (string) $caption), $id]);
        }
        $this->db->audit($actor, 'social.series', (string) $id, ['series' => $series]);
        return $this->postsById([$id])[$id];
    }

    /** Week = Monday (Europe/Berlin). */
    public static function weekOf(string $date): string
    {
        $d = new \DateTimeImmutable($date . 'T12:00:00', Clock::tz());
        return $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
    }

    public function setGroupLog(string $week, int $answers, ?string $note, string $actor): array
    {
        if (!Clock::isDate($week) || self::weekOf($week) !== $week) {
            throw new HttpException(422, 'Woche ungültig (Montag als Datum angeben).');
        }
        if ($answers < 0 || $answers > 999) {
            throw new HttpException(422, 'Anzahl zwischen 0 und 999 angeben.');
        }
        $note = $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 500);
        $this->db->run(
            'INSERT INTO social_group_log (week, answers, note, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT (week) DO UPDATE SET answers = excluded.answers, note = excluded.note, updated_at = excluded.updated_at',
            [$week, $answers, $note, Clock::nowIso()],
        );
        $this->db->audit($actor, 'social.groups', $week, ['answers' => $answers]);
        return ['week' => $week, 'answers' => $answers, 'note' => $note];
    }

    // ---------------------------------------------------------------- reading

    /**
     * The "Social Media" page. @param array{from: string, to: string} $range @param array{from: string, to: string} $prev
     * @param array<string, array{at: string|null, via: string|null, missing?: string}> $stamps
     */
    public function page(array $range, array $prev, string $channel, array $stamps): array
    {
        if ($channel !== 'all' && !in_array($channel, self::PLATFORMS, true)) {
            throw new HttpException(404, 'Unbekannter Kanal.');
        }
        $platforms = $channel === 'all' ? self::PLATFORMS : [$channel];
        $channels = [];
        foreach (self::PLATFORMS as $p) {
            $channels[] = $this->channelSummary($p, $range, $prev, $stamps[$p]);
        }
        $posts = $this->posts($platforms, $range['from'], $range['to']);
        $weekFrom = Clock::addDays(Clock::today(), -6);
        $recent = array_values(array_filter(
            $this->posts($platforms, $weekFrom, Clock::today()),
            static fn (array $p): bool => $p['engagementRate'] !== null && $p['format'] !== 'story',
        ));
        usort($recent, static fn (array $a, array $b): int => $b['engagementRate'] <=> $a['engagementRate']);

        return [
            'range' => $range,
            'previousRange' => $prev,
            'channel' => $channel,
            'channels' => $channels,
            'posts' => $posts,
            'top' => array_slice($recent, 0, 3),
            'series' => self::seriesTable($posts),
            'clipsPerWeek' => $this->clipsPerWeek(8),
            'goals' => $this->goals(),
            'visits' => $this->visits($range, $prev),
            'groups' => $this->groups(8, $range),
        ];
    }

    /** @param array{from: string, to: string} $range @param array{from: string, to: string} $prev @param array{at: string|null, via: string|null, missing?: string} $stamp */
    private function channelSummary(string $platform, array $range, array $prev, array $stamp): array
    {
        $metrics = match ($platform) {
            'instagram' => ['reach' => 'reach', 'views' => 'views', 'interactions' => 'total_interactions'],
            'facebook' => ['reach' => 'reach', 'views' => 'views', 'interactions' => 'interactions'],
            default => ['reach' => null, 'views' => 'views', 'interactions' => 'interactions'],
        };
        $sum = fn (?string $metric, array $r): ?float => $metric === null ? null : $this->accountSum($platform, $metric, $r['from'], $r['to']);
        $followers = $platform === 'youtube_shorts' ? null : $this->followersAt($platform, $range['to']);
        $followersBefore = $platform === 'youtube_shorts' ? null : $this->followersAt($platform, Clock::addDays($range['from'], -1));
        $posts = $this->posts([$platform], $range['from'], $range['to']);
        return [
            'platform' => $platform,
            'name' => self::NAMES[$platform],
            'stamp' => $stamp,
            'followers' => ['value' => $followers, 'previous' => $followersBefore],
            'reach' => ['value' => $sum($metrics['reach'], $range), 'previous' => $sum($metrics['reach'], $prev)],
            'views' => ['value' => $sum($metrics['views'], $range), 'previous' => $sum($metrics['views'], $prev)],
            'interactions' => ['value' => $sum($metrics['interactions'], $range), 'previous' => $sum($metrics['interactions'], $prev)],
            'engagementRate' => self::pooledRate($posts),
            'posts' => count(array_filter($posts, static fn (array $p): bool => $p['format'] !== 'story')),
            'stories' => count(array_filter($posts, static fn (array $p): bool => $p['format'] === 'story')),
            'newComments' => $this->newComments($platform),
        ];
    }

    /** Sum of daily account values; null when the metric has no data reaching into the period. */
    private function accountSum(string $platform, string $metric, string $from, string $to): ?float
    {
        $max = $this->db->value('SELECT MAX(date) FROM social_account_metrics_daily WHERE platform = ? AND metric = ?', [$platform, $metric]);
        if ($max === null || $max < $from) {
            return null;
        }
        return (float) $this->db->value(
            'SELECT COALESCE(SUM(value), 0) FROM social_account_metrics_daily WHERE platform = ? AND metric = ? AND date BETWEEN ? AND ?',
            [$platform, $metric, $from, $to],
        );
    }

    /** Newest follower snapshot on or before a day. */
    private function followersAt(string $platform, string $date): ?float
    {
        $v = $this->db->value(
            "SELECT value FROM social_account_metrics_daily WHERE platform = ? AND metric = 'followers' AND date <= ? ORDER BY date DESC LIMIT 1",
            [$platform, $date],
        );
        return $v === null ? null : (float) $v;
    }

    private function newComments(string $platform): int
    {
        if ($platform === 'youtube_shorts') {
            return (int) $this->db->value(
                "SELECT COUNT(*) FROM comments WHERE platform = 'youtube' AND parent_id IS NULL AND from_host = 0 AND status = 'new'
                 AND video_id IN (SELECT external_id FROM social_posts WHERE platform = 'youtube_shorts')",
            );
        }
        return (int) $this->db->value("SELECT COUNT(*) FROM comments WHERE platform = ? AND parent_id IS NULL AND from_host = 0 AND status = 'new'", [$platform]);
    }

    /** @param list<string> $platforms @return list<array<string, mixed>> newest first */
    public function posts(array $platforms, string $from, string $to): array
    {
        $in = implode(',', array_fill(0, count($platforms), '?'));
        $ids = array_map('intval', array_column($this->db->all(
            "SELECT id FROM social_posts WHERE platform IN ({$in}) AND substr(published_at, 1, 10) BETWEEN ? AND ? ORDER BY published_at DESC LIMIT 200",
            [...$platforms, $from, $to],
        ), 'id'));
        return array_values($this->postsById($ids));
    }

    /** @param list<int> $ids @return array<int, array<string, mixed>> */
    private function postsById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $latest = [];
        foreach ($this->db->all(
            "SELECT m.post_id, m.metric, m.value, m.captured_at FROM social_post_metrics_daily m
             JOIN (SELECT post_id, metric, MAX(date) AS d FROM social_post_metrics_daily WHERE post_id IN ({$in}) GROUP BY post_id, metric) x
               ON x.post_id = m.post_id AND x.metric = m.metric AND x.d = m.date",
        ) as $row) {
            $latest[(int) $row['post_id']][(string) $row['metric']] = (float) $row['value'];
            $latest[(int) $row['post_id']]['__at'] = max($latest[(int) $row['post_id']]['__at'] ?? '', (string) $row['captured_at']);
        }
        $out = [];
        foreach ($this->db->all("SELECT * FROM social_posts WHERE id IN ({$in}) ORDER BY published_at DESC") as $p) {
            $id = (int) $p['id'];
            $m = $latest[$id] ?? [];
            $capturedAt = $m['__at'] ?? null;
            unset($m['__at']);
            $out[$id] = [
                'id' => $id,
                'platform' => $p['platform'],
                'format' => $p['format'],
                'caption' => $p['caption'],
                'permalink' => $p['permalink'],
                'thumbnail' => $p['thumbnail_url'],
                'publishedAt' => $p['published_at'],
                'series' => $p['series'],
                'seriesManual' => (bool) $p['series_manual'],
                'metrics' => [
                    'views' => $m['views'] ?? null,
                    'reach' => $m['reach'] ?? null,
                    'likes' => $m['likes'] ?? null,
                    'comments' => $m['comments'] ?? null,
                    'saves' => $m['saves'] ?? null,
                    'shares' => $m['shares'] ?? null,
                    'interactions' => self::interactions((string) $p['platform'], $m),
                ],
                'engagementRate' => self::engagementRate((string) $p['platform'], $m),
                'capturedAt' => $capturedAt,
            ];
        }
        return $out;
    }

    /** Likes + comments + saves + shares; Facebook "likes" are all reactions. Null when nothing is known. @param array<string, float> $m */
    public static function interactions(string $platform, array $m): ?float
    {
        $parts = array_intersect_key($m, array_flip(['likes', 'comments', 'saves', 'shares']));
        if ($parts === []) {
            return null;
        }
        return (float) array_sum($parts);
    }

    /**
     * Engagement rate of one post: interactions ÷ reach (accounts reached). YouTube Shorts and posts
     * without reach use views instead. Null when the base is unknown or 0.
     * @param array<string, float> $m
     */
    public static function engagementRate(string $platform, array $m): ?float
    {
        $interactions = self::interactions($platform, $m);
        $base = $m['reach'] ?? $m['views'] ?? null;
        if ($interactions === null || $base === null || $base <= 0) {
            return null;
        }
        return round($interactions / $base, 4);
    }

    /** Pooled rate of several posts: sum of interactions ÷ sum of bases (big posts weigh more, as they should). @param list<array<string, mixed>> $posts */
    private static function pooledRate(array $posts): ?float
    {
        $num = 0.0;
        $den = 0.0;
        foreach ($posts as $p) {
            if ($p['engagementRate'] === null) {
                continue;
            }
            $base = $p['metrics']['reach'] ?? $p['metrics']['views'];
            $num += (float) $p['metrics']['interactions'];
            $den += (float) $base;
        }
        return $den > 0 ? round($num / $den, 4) : null;
    }

    /** "Welche Serie trägt?": posts and pooled engagement rate per series and format. @param list<array<string, mixed>> $posts */
    public static function seriesTable(array $posts): array
    {
        $groups = [];
        foreach ($posts as $p) {
            if ($p['format'] === 'story') {
                continue;
            }
            $key = ($p['series'] ?? '') . '|' . $p['format'];
            $groups[$key] ??= ['series' => $p['series'], 'format' => $p['format'], 'posts' => []];
            $groups[$key]['posts'][] = $p;
        }
        $out = [];
        foreach ($groups as $g) {
            $out[] = ['series' => $g['series'], 'format' => $g['format'], 'posts' => count($g['posts']), 'engagementRate' => self::pooledRate($g['posts'])];
        }
        usort($out, static fn (array $a, array $b): int => ($b['engagementRate'] ?? -1) <=> ($a['engagementRate'] ?? -1) ?: $b['posts'] <=> $a['posts']);
        return $out;
    }

    /**
     * Clips per week: Instagram reels and YouTube Shorts. The same clip is usually posted on both, so
     * the week counts the higher of the two numbers, not the sum.
     */
    private function clipsPerWeek(int $weeks): array
    {
        $thisWeek = self::weekOf(Clock::today());
        $first = Clock::addDays($thisWeek, -7 * ($weeks - 1));
        $counts = [];
        foreach ($this->db->all(
            "SELECT platform, published_at FROM social_posts WHERE ((platform = 'instagram' AND format = 'reel') OR platform = 'youtube_shorts') AND substr(published_at, 1, 10) >= ?",
            [$first],
        ) as $row) {
            $week = self::weekOf(Clock::dateOf((string) $row['published_at']));
            $counts[$week][(string) $row['platform']] = ($counts[$week][(string) $row['platform']] ?? 0) + 1;
        }
        $out = [];
        for ($i = 0; $i < $weeks; $i++) {
            $week = Clock::addDays($first, 7 * $i);
            $c = $counts[$week] ?? [];
            $out[] = ['week' => $week, 'count' => max($c['instagram'] ?? 0, $c['youtube_shorts'] ?? 0), 'reels' => $c['instagram'] ?? 0, 'shorts' => $c['youtube_shorts'] ?? 0, 'target' => self::GOAL_CLIPS_PER_WEEK];
        }
        return $out;
    }

    private function goals(): array
    {
        $ig = $this->followersAt('instagram', Clock::today());
        $yt = $this->db->value("SELECT value FROM metric_totals WHERE platform = 'youtube' AND metric = 'subscribers' AND episode_id = 0 ORDER BY captured_at DESC LIMIT 1");
        return [
            ['id' => 'instagram_followers', 'label' => 'Instagram-Follower', 'value' => $ig, 'target' => self::GOAL_INSTAGRAM_FOLLOWERS],
            ['id' => 'youtube_subscribers', 'label' => 'YouTube-Abonnenten (ganzer Kanal)', 'value' => $yt === null ? null : (float) $yt, 'target' => self::GOAL_YOUTUBE_SUBSCRIBERS],
        ];
    }

    /** @param array{from: string, to: string} $range @param array{from: string, to: string} $prev */
    private function visits(array $range, array $prev): array
    {
        $first = $this->db->value('SELECT MIN(date) FROM social_visits_daily');
        $logsRunning = $this->db->value("SELECT 1 FROM sync_runs WHERE source = 'downloads' AND ok = 1 LIMIT 1") !== null;
        if ($first === null) {
            return [
                'value' => null,
                'previous' => null,
                'bySource' => [],
                'missing' => $logsRunning
                    ? 'Noch kein Besuch über einen Link mit utm_source. Links in Bio und Beiträgen mit ?utm_source=instagram usw. versehen.'
                    : 'Kommt aus den Server-Logs, sobald die Download-Messung läuft.',
            ];
        }
        $sum = fn (array $r): float => (float) $this->db->value('SELECT COALESCE(SUM(visits), 0) FROM social_visits_daily WHERE date BETWEEN ? AND ?', [$r['from'], $r['to']]);
        $bySource = [];
        foreach (\Cockpit\Counting\SocialVisits::SOURCES as $s) {
            $bySource[$s] = (float) $this->db->value('SELECT COALESCE(SUM(visits), 0) FROM social_visits_daily WHERE source = ? AND date BETWEEN ? AND ?', [$s, $range['from'], $range['to']]);
        }
        return [
            'value' => $sum($range),
            'previous' => $prev['to'] < (string) $first ? null : $sum($prev),
            'bySource' => $bySource,
        ];
    }

    /** @param array{from: string, to: string} $range */
    private function groups(int $weeks, array $range): array
    {
        $thisWeek = self::weekOf(Clock::today());
        $log = [];
        foreach ($this->db->all('SELECT * FROM social_group_log WHERE week >= ?', [Clock::addDays($thisWeek, -7 * ($weeks - 1))]) as $row) {
            $log[(string) $row['week']] = $row;
        }
        $out = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $week = Clock::addDays($thisWeek, -7 * $i);
            $row = $log[$week] ?? null;
            $out[] = ['week' => $week, 'answers' => $row === null ? null : (int) $row['answers'], 'note' => $row['note'] ?? null];
        }
        $visits = $this->db->value('SELECT SUM(visits) FROM social_visits_daily WHERE source = ? AND date BETWEEN ? AND ?', ['fb_gruppe', $range['from'], $range['to']]);
        return ['weeks' => $out, 'visits' => $visits === null ? null : (float) $visits];
    }
}
