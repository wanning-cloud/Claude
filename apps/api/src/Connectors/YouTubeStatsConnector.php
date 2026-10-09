<?php

declare(strict_types=1);

namespace Cockpit\Connectors;

use Cockpit\Clock;
use Cockpit\Db;
use Cockpit\Google\YouTubeApi;
use Cockpit\Repo\Social;

/**
 * Daily YouTube numbers:
 * 1. Videos from the uploads playlist (playlistItems.list, 1 unit per 50) and lifetime counters
 *    from videos.list statistics (1 unit per 50) → metric_totals.
 * 2. Shorts are social media, not podcast: they go to social_posts (lifetime counters) and are
 *    never mapped to an episode.
 * 3. Mapping video → episode via "Folge NN" in the title (unless set by hand).
 * 4. Daily values per video and for the whole channel from the Analytics API. The channel report is
 *    split by creatorContentType: everything except SHORTS → metric_daily (podcast reach),
 *    SHORTS → social_account_metrics_daily. Analytics data arrives with a delay, so the last 7 days
 *    are always fetched again.
 */
final class YouTubeStatsConnector implements Connector
{
    public const METRICS = ['views', 'estimatedMinutesWatched', 'averageViewDuration', 'averageViewPercentage', 'likes', 'comments', 'subscribersGained'];
    /** Averages: combined across content types weighted by views, never summed. */
    public const AVERAGES = ['averageViewDuration', 'averageViewPercentage'];
    /** Shorts can be up to 3 minutes long (since October 2024). Used only when the Shorts playlist is unavailable. */
    public const SHORT_MAX_SECONDS = 180;

    public function __construct(private readonly Db $db, private readonly YouTubeApi $api, private readonly string $channelId)
    {
    }

    public function id(): string
    {
        return 'youtube_stats';
    }

    public function label(): string
    {
        return 'YouTube-Zahlen';
    }

    public function sync(): SyncOutcome
    {
        $videos = $this->videos();
        $shorts = $this->shorts($videos);
        $this->subscribers();
        $this->mapEpisodes();
        $rows = $this->analytics(array_keys($videos));
        $long = count($videos) - count($shorts);
        return new SyncOutcome(count($videos), "{$long} Videos und " . count($shorts) . " Shorts, {$rows} Tageswerte geholt.");
    }

    /** @return array<string, array{title: string, published_at: string, duration: int|null, views: float|null, likes: float|null, comments: float|null}> */
    private function videos(): array
    {
        $uploads = 'UU' . substr($this->channelId, 2);
        $ids = [];
        $page = null;
        do {
            $data = $this->api->get('playlistItems', array_filter([
                'part' => 'snippet,contentDetails',
                'playlistId' => $uploads,
                'maxResults' => 50,
                'pageToken' => $page,
            ]));
            foreach ($data['items'] ?? [] as $item) {
                $ids[] = (string) $item['contentDetails']['videoId'];
            }
            $page = $data['nextPageToken'] ?? null;
        } while ($page !== null);

        $videos = [];
        $now = Clock::nowIso();
        foreach (array_chunk($ids, 50) as $chunk) {
            $data = $this->api->get('videos', ['part' => 'snippet,statistics,contentDetails', 'id' => implode(',', $chunk), 'maxResults' => 50]);
            foreach ($data['items'] ?? [] as $video) {
                $id = (string) $video['id'];
                $title = (string) $video['snippet']['title'];
                $published = Clock::iso(Clock::parse((string) $video['snippet']['publishedAt']));
                $stat = static fn (string $f): ?float => isset($video['statistics'][$f]) ? (float) $video['statistics'][$f] : null;
                $videos[$id] = [
                    'title' => $title,
                    'published_at' => $published,
                    'duration' => self::seconds((string) ($video['contentDetails']['duration'] ?? '')),
                    'views' => $stat('viewCount'),
                    'likes' => $stat('likeCount'),
                    'comments' => $stat('commentCount'),
                ];
                $this->db->run(
                    'INSERT INTO youtube_videos (video_id, title, published_at, updated_at) VALUES (?, ?, ?, ?)
                     ON CONFLICT (video_id) DO UPDATE SET title = excluded.title, published_at = excluded.published_at, updated_at = excluded.updated_at',
                    [$id, $title, $published, $now],
                );
                foreach (['viewCount' => 'views', 'likeCount' => 'likes', 'commentCount' => 'comments'] as $field => $metric) {
                    if (isset($video['statistics'][$field])) {
                        $this->db->run(
                            "INSERT OR REPLACE INTO metric_totals (episode_id, platform, metric, captured_at, value, ref) VALUES (0, 'youtube', ?, ?, ?, ?)",
                            [$metric, $now, (float) $video['statistics'][$field], $id],
                        );
                    }
                }
            }
        }
        return $videos;
    }

    /**
     * Shorts of the channel: the channel's Shorts playlist (UUSH…) when YouTube answers it, otherwise
     * videos up to 3 minutes. Stored as social posts with their lifetime counters.
     * @param array<string, array{title: string, published_at: string, duration: int|null, views: float|null, likes: float|null, comments: float|null}> $videos
     * @return list<string> video ids
     */
    private function shorts(array $videos): array
    {
        $ids = null;
        try {
            $ids = [];
            $page = null;
            do {
                $data = $this->api->get('playlistItems', array_filter([
                    'part' => 'contentDetails', 'playlistId' => 'UUSH' . substr($this->channelId, 2), 'maxResults' => 50, 'pageToken' => $page,
                ]));
                foreach ($data['items'] ?? [] as $item) {
                    $ids[] = (string) $item['contentDetails']['videoId'];
                }
                $page = $data['nextPageToken'] ?? null;
            } while ($page !== null);
        } catch (\RuntimeException) {
            $ids = null; // playlist not available (no Shorts yet, or YouTube changed it): fall back to the length
        }
        $manual = array_flip(array_map('strval', $this->db->pdo->query('SELECT youtube_video_id FROM episodes WHERE youtube_video_id IS NOT NULL AND youtube_manual = 1')->fetchAll(\PDO::FETCH_COLUMN)));
        $shorts = [];
        foreach ($videos as $id => $v) {
            $isShort = $ids !== null ? in_array($id, $ids, true) : ($v['duration'] !== null && $v['duration'] <= self::SHORT_MAX_SECONDS);
            if ($isShort && !isset($manual[$id])) {
                $shorts[] = $id;
            }
        }
        $social = new Social($this->db);
        foreach ($shorts as $id) {
            $v = $videos[$id];
            $postId = $social->upsertPost('youtube_shorts', $id, [
                'format' => 'short',
                'caption' => $v['title'],
                'permalink' => 'https://www.youtube.com/shorts/' . rawurlencode($id),
                'thumbnail' => 'https://i.ytimg.com/vi/' . rawurlencode($id) . '/hqdefault.jpg',
                'publishedAt' => $v['published_at'],
            ]);
            $social->storePostMetrics($postId, ['views' => $v['views'], 'likes' => $v['likes'], 'comments' => $v['comments']]);
            // A Short is never an episode video.
            $this->db->run('UPDATE episodes SET youtube_video_id = NULL WHERE youtube_video_id = ? AND youtube_manual = 0', [$id]);
        }
        return $shorts;
    }

    /** Current subscriber count of the channel (goal on the social page). */
    private function subscribers(): void
    {
        $data = $this->api->get('channels', ['part' => 'statistics', 'id' => $this->channelId]);
        $count = $data['items'][0]['statistics']['subscriberCount'] ?? null;
        if ($count !== null && !($data['items'][0]['statistics']['hiddenSubscriberCount'] ?? false)) {
            $this->db->run(
                "INSERT OR REPLACE INTO metric_totals (episode_id, platform, metric, captured_at, value, ref) VALUES (0, 'youtube', 'subscribers', ?, ?, '')",
                [Clock::nowIso(), (float) $count],
            );
        }
    }

    /** ISO 8601 duration (PT1H2M3S) → seconds. */
    public static function seconds(string $iso): ?int
    {
        if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $iso, $m) || $iso === 'P') {
            return null;
        }
        return ((int) ($m[1] ?? 0)) * 86400 + ((int) ($m[2] ?? 0)) * 3600 + ((int) ($m[3] ?? 0)) * 60 + (int) ($m[4] ?? 0);
    }

    /** "Folge 7", "Folge 07:", "#07" → episode number. Manual mappings are never overwritten. Shorts are skipped. */
    public function mapEpisodes(): void
    {
        $episodes = [];
        foreach ($this->db->all('SELECT id, number FROM episodes WHERE number IS NOT NULL') as $row) {
            $episodes[(int) $row['number']] = (int) $row['id'];
        }
        $taken = array_flip(array_map('strval', $this->db->pdo->query('SELECT youtube_video_id FROM episodes WHERE youtube_manual = 1 AND youtube_video_id IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN)));
        foreach ($this->db->all("SELECT video_id, title FROM youtube_videos WHERE video_id NOT IN (SELECT external_id FROM social_posts WHERE platform = 'youtube_shorts') ORDER BY published_at") as $video) {
            if (isset($taken[$video['video_id']]) || !preg_match('/\bFolge\s*0*(\d{1,3})\b/iu', (string) $video['title'], $m)) {
                continue;
            }
            $episodeId = $episodes[(int) $m[1]] ?? null;
            if ($episodeId !== null) {
                $this->db->run('UPDATE episodes SET youtube_video_id = ? WHERE id = ? AND youtube_manual = 0', [$video['video_id'], $episodeId]);
            }
        }
    }

    /** @param list<string> $videoIds */
    private function analytics(array $videoIds): int
    {
        $end = Clock::addDays(Clock::today(), -1);
        $last = $this->db->value("SELECT MAX(date) FROM metric_daily WHERE platform = 'youtube' AND episode_id = 0");
        $first = $this->db->value('SELECT MIN(published_at) FROM youtube_videos');
        // Values stored before the Shorts split still contain Shorts: fetch the whole history once again.
        $didSplit = $this->db->setting('youtube.shorts_split') !== null;
        $start = $last !== null && $didSplit ? Clock::addDays((string) $last, -7) : ($first !== null ? Clock::dateOf((string) $first) : Clock::addDays($end, -90));
        if ($start > $end) {
            return 0;
        }
        $now = Clock::nowIso();
        $count = 0;
        $store = function (int $episodeId, array $row) use ($now, &$count): void {
            foreach (self::METRICS as $metric) {
                if (!array_key_exists($metric, $row)) {
                    continue;
                }
                $this->db->run(
                    "INSERT INTO metric_daily (episode_id, platform, metric, date, value, source, imported_at) VALUES (?, 'youtube', ?, ?, ?, 'api', ?)
                     ON CONFLICT (episode_id, platform, metric, date) DO UPDATE SET value = excluded.value, imported_at = excluded.imported_at",
                    [$episodeId, $metric, (string) $row['day'], (float) $row[$metric], $now],
                );
                $count++;
            }
        };

        // Channel level, split by content type. Podcast reach of YouTube = everything except Shorts
        // (includes videos without an episode, e.g. trailers). Shorts go to the social area.
        $split = self::splitByContentType($this->api->report([
            'ids' => 'channel==MINE', 'startDate' => $start, 'endDate' => $end,
            'metrics' => implode(',', self::METRICS), 'dimensions' => 'day,creatorContentType', 'sort' => 'day',
        ]));
        // Replace the channel rows of the period: a day that only had Shorts views must not keep an old mixed value.
        $this->db->run("DELETE FROM metric_daily WHERE platform = 'youtube' AND episode_id = 0 AND date BETWEEN ? AND ?", [$start, $end]);
        foreach ($split['podcast'] as $row) {
            $store(0, $row);
        }
        $social = new Social($this->db);
        foreach ($split['shorts'] as $row) {
            foreach (['views', 'likes', 'comments', 'subscribersGained', 'estimatedMinutesWatched'] as $metric) {
                if (isset($row[$metric])) {
                    $social->storeAccountDaily('youtube_shorts', $metric, (string) $row['day'], (float) $row[$metric]);
                    $count++;
                }
            }
            $social->storeAccountDaily('youtube_shorts', 'interactions', (string) $row['day'], (float) ($row['likes'] ?? 0) + (float) ($row['comments'] ?? 0));
        }
        $this->db->setSetting('youtube.shorts_split', Clock::nowIso());

        // Per episode video. Values of videos without an episode only count on channel level.
        $byVideo = [];
        foreach ($this->db->all('SELECT id, youtube_video_id FROM episodes WHERE youtube_video_id IS NOT NULL') as $row) {
            $byVideo[(string) $row['youtube_video_id']] = (int) $row['id'];
        }
        foreach ($videoIds as $videoId) {
            if (!isset($byVideo[$videoId])) {
                continue;
            }
            foreach ($this->api->report([
                'ids' => 'channel==MINE', 'startDate' => $start, 'endDate' => $end,
                'metrics' => implode(',', self::METRICS), 'dimensions' => 'day', 'filters' => 'video==' . $videoId, 'sort' => 'day',
            ]) as $row) {
                $store($byVideo[$videoId], $row);
            }
        }
        return $count;
    }

    /**
     * Rows of day × creatorContentType → one podcast row per day (all types except SHORTS) and one
     * Shorts row per day. Sums are added; averages are weighted by views. A day with only Shorts
     * gets a podcast row with 0 views, so "no views" is not mistaken for "no data".
     * @param list<array<string, mixed>> $rows
     * @return array{podcast: list<array<string, mixed>>, shorts: list<array<string, mixed>>}
     */
    public static function splitByContentType(array $rows): array
    {
        $acc = ['podcast' => [], 'shorts' => []];
        foreach ($rows as $row) {
            $bucket = strtoupper((string) ($row['creatorContentType'] ?? '')) === 'SHORTS' ? 'shorts' : 'podcast';
            $day = (string) $row['day'];
            $a = $acc[$bucket][$day] ?? ['day' => $day, '__w' => []];
            $views = (float) ($row['views'] ?? 0);
            foreach (self::METRICS as $metric) {
                if (!array_key_exists($metric, $row)) {
                    continue;
                }
                if (in_array($metric, self::AVERAGES, true)) {
                    $a['__w'][$metric] = [($a['__w'][$metric][0] ?? 0.0) + (float) $row[$metric] * $views, ($a['__w'][$metric][1] ?? 0.0) + $views];
                } else {
                    $a[$metric] = ($a[$metric] ?? 0.0) + (float) $row[$metric];
                }
            }
            $acc[$bucket][$day] = $a;
        }
        // A day with only Shorts views is a real 0 for the podcast, not a gap in the data.
        foreach (array_keys($acc['shorts']) as $day) {
            $acc['podcast'][$day] ??= ['day' => $day, '__w' => []] + array_fill_keys(array_diff(self::METRICS, self::AVERAGES), 0.0);
        }
        $out = ['podcast' => [], 'shorts' => []];
        foreach ($acc as $bucket => $days) {
            ksort($days);
            foreach ($days as $a) {
                foreach ($a['__w'] as $metric => [$num, $den]) {
                    $a[$metric] = $den > 0 ? round($num / $den, 2) : 0.0;
                }
                unset($a['__w']);
                $out[$bucket][] = $a;
            }
        }
        return $out;
    }
}
