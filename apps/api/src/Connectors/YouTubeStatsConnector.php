<?php

declare(strict_types=1);

namespace Cockpit\Connectors;

use Cockpit\Clock;
use Cockpit\Db;
use Cockpit\Google\YouTubeApi;

/**
 * Daily YouTube numbers:
 * 1. Videos from the uploads playlist (playlistItems.list, 1 unit per 50) and lifetime counters
 *    from videos.list statistics (1 unit per 50) → metric_totals.
 * 2. Mapping video → episode via "Folge NN" in the title (unless set by hand).
 * 3. Daily values per video and for the whole channel from the Analytics API → metric_daily.
 *    Analytics data arrives with a delay, so the last 7 days are always fetched again.
 */
final class YouTubeStatsConnector implements Connector
{
    public const METRICS = ['views', 'estimatedMinutesWatched', 'averageViewDuration', 'averageViewPercentage', 'likes', 'comments', 'subscribersGained'];

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
        $this->mapEpisodes();
        $rows = $this->analytics(array_keys($videos));
        return new SyncOutcome(count($videos), count($videos) . " Videos, {$rows} Tageswerte geholt.");
    }

    /** @return array<string, array{title: string, published_at: string}> */
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
            $data = $this->api->get('videos', ['part' => 'snippet,statistics', 'id' => implode(',', $chunk), 'maxResults' => 50]);
            foreach ($data['items'] ?? [] as $video) {
                $id = (string) $video['id'];
                $title = (string) $video['snippet']['title'];
                $published = Clock::iso(Clock::parse((string) $video['snippet']['publishedAt']));
                $videos[$id] = ['title' => $title, 'published_at' => $published];
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

    /** "Folge 7", "Folge 07:", "#07" → episode number. Manual mappings are never overwritten. */
    public function mapEpisodes(): void
    {
        $episodes = [];
        foreach ($this->db->all('SELECT id, number FROM episodes WHERE number IS NOT NULL') as $row) {
            $episodes[(int) $row['number']] = (int) $row['id'];
        }
        $taken = array_flip(array_map('strval', $this->db->pdo->query('SELECT youtube_video_id FROM episodes WHERE youtube_manual = 1 AND youtube_video_id IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN)));
        foreach ($this->db->all('SELECT video_id, title FROM youtube_videos ORDER BY published_at') as $video) {
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
        $start = $last !== null ? Clock::addDays((string) $last, -7) : ($first !== null ? Clock::dateOf((string) $first) : Clock::addDays($end, -90));
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

        // Channel level: the reach value of YouTube (includes videos without an episode, e.g. trailers).
        foreach ($this->api->report([
            'ids' => 'channel==MINE', 'startDate' => $start, 'endDate' => $end,
            'metrics' => implode(',', self::METRICS), 'dimensions' => 'day', 'sort' => 'day',
        ]) as $row) {
            $store(0, $row);
        }

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
}
