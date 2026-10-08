<?php

declare(strict_types=1);

namespace Cockpit\Connectors;

use Cockpit\Clock;
use Cockpit\Db;
use Cockpit\Google\YouTubeApi;

/**
 * New comments on all channel videos every 30 minutes via commentThreads.list
 * (allThreadsRelatedToChannelId, 1 unit per 100 threads). Stops paging once a page only holds
 * threads that have not changed since the last run. Threads with more than the inlined replies
 * load the rest via comments.list.
 */
final class YouTubeCommentsConnector implements Connector
{
    public function __construct(private readonly Db $db, private readonly YouTubeApi $api, private readonly string $channelId)
    {
    }

    public function id(): string
    {
        return 'youtube_comments';
    }

    public function label(): string
    {
        return 'YouTube-Kommentare';
    }

    public function sync(): SyncOutcome
    {
        $since = $this->db->setting('youtube_comments.since');
        $startedAt = Clock::nowIso();
        // order=time sorts by thread start, so new replies in old threads only show up in a full pass.
        // One full pass per day costs about one unit per 100 threads.
        $lastFull = $this->db->setting('youtube_comments.full');
        $full = $lastFull === null || Clock::parse($lastFull) < Clock::now()->modify('-24 hours');
        if ($full) {
            $since = null;
        }
        $new = 0;
        $page = null;
        do {
            $data = $this->api->get('commentThreads', array_filter([
                'part' => 'snippet,replies',
                'allThreadsRelatedToChannelId' => $this->channelId,
                'maxResults' => 100,
                'order' => 'time',
                'textFormat' => 'plainText',
                'pageToken' => $page,
            ]));
            $changed = false;
            foreach ($data['items'] ?? [] as $thread) {
                $top = $thread['snippet']['topLevelComment'];
                $updated = (string) ($top['snippet']['updatedAt'] ?? $top['snippet']['publishedAt']);
                $replies = $thread['replies']['comments'] ?? [];
                foreach ($replies as $reply) {
                    $updated = max($updated, (string) ($reply['snippet']['updatedAt'] ?? $reply['snippet']['publishedAt']));
                }
                if ($since !== null && Clock::parse($updated) <= Clock::parse($since)) {
                    continue;
                }
                $changed = true;
                $videoId = (string) ($thread['snippet']['videoId'] ?? $top['snippet']['videoId'] ?? '');
                $parentId = $this->upsert($top, null, $videoId, $new);
                $total = (int) ($thread['snippet']['totalReplyCount'] ?? 0);
                if ($total > count($replies)) {
                    $replies = $this->allReplies((string) $top['id']);
                }
                foreach ($replies as $reply) {
                    $this->upsert($reply, $parentId, $videoId, $new);
                }
                $this->markAnswered($parentId);
            }
            $page = $data['nextPageToken'] ?? null;
            // Threads are ordered by activity; a page without changes means everything older is known.
        } while ($page !== null && ($changed || $since === null));

        $this->db->setSetting('youtube_comments.since', $startedAt);
        if ($full) {
            $this->db->setSetting('youtube_comments.full', $startedAt);
        }
        return new SyncOutcome($new, $new === 0 ? 'Keine neuen Kommentare.' : "{$new} neue Kommentare.");
    }

    /** @return list<array<string, mixed>> */
    private function allReplies(string $parentId): array
    {
        $out = [];
        $page = null;
        do {
            $data = $this->api->get('comments', array_filter([
                'part' => 'snippet', 'parentId' => $parentId, 'maxResults' => 100, 'textFormat' => 'plainText', 'pageToken' => $page,
            ]));
            array_push($out, ...($data['items'] ?? []));
            $page = $data['nextPageToken'] ?? null;
        } while ($page !== null);
        return $out;
    }

    /** @param array<string, mixed> $comment */
    private function upsert(array $comment, ?int $parentId, string $videoId, int &$new): int
    {
        $s = $comment['snippet'];
        $authorChannel = (string) ($s['authorChannelId']['value'] ?? '');
        $fromHost = $authorChannel === $this->channelId ? 1 : 0;
        $existing = $this->db->one("SELECT id FROM comments WHERE platform = 'youtube' AND external_id = ?", [(string) $comment['id']]);
        $episodeId = $this->db->value('SELECT id FROM episodes WHERE youtube_video_id = ?', [$videoId]);
        $values = [
            'parent_id' => $parentId,
            'episode_id' => $episodeId,
            'video_id' => $videoId,
            'author_name' => (string) ($s['authorDisplayName'] ?? ''),
            'author_url' => $s['authorChannelUrl'] ?? null,
            'author_channel_id' => $authorChannel ?: null,
            'text' => (string) ($s['textOriginal'] ?? $s['textDisplay'] ?? ''),
            'posted_at' => Clock::iso(Clock::parse((string) $s['publishedAt'])),
            'updated_at' => isset($s['updatedAt']) ? Clock::iso(Clock::parse((string) $s['updatedAt'])) : null,
            'from_host' => $fromHost,
            'raw_json' => json_encode($comment, JSON_UNESCAPED_UNICODE),
        ];
        if ($existing !== null) {
            $this->db->run(
                'UPDATE comments SET parent_id = :parent_id, episode_id = :episode_id, video_id = :video_id, author_name = :author_name,
                   author_url = :author_url, author_channel_id = :author_channel_id, text = :text, posted_at = :posted_at,
                   updated_at = :updated_at, from_host = :from_host, raw_json = :raw_json WHERE id = :id',
                $values + ['id' => $existing['id']],
            );
            return (int) $existing['id'];
        }
        // Own comments and replies need no action; everything else starts as "new".
        $status = ($fromHost || $parentId !== null) ? 'done' : 'new';
        $this->db->run(
            "INSERT INTO comments (platform, external_id, parent_id, episode_id, video_id, author_name, author_url, author_channel_id,
               text, posted_at, updated_at, status, from_host, raw_json)
             VALUES ('youtube', :external_id, :parent_id, :episode_id, :video_id, :author_name, :author_url, :author_channel_id,
               :text, :posted_at, :updated_at, :status, :from_host, :raw_json)",
            $values + ['external_id' => (string) $comment['id'], 'status' => $status],
        );
        if ($status === 'new') {
            $new++;
        }
        return $this->db->lastId();
    }

    /** A thread is "answered" once the host replied after the newest listener comment. */
    private function markAnswered(int $threadId): void
    {
        $lastHost = $this->db->value('SELECT MAX(posted_at) FROM comments WHERE parent_id = ? AND from_host = 1', [$threadId]);
        if ($lastHost === null) {
            return;
        }
        $this->db->run(
            "UPDATE comments SET status = 'answered', answered_at = COALESCE(answered_at, ?) WHERE id = ? AND status = 'new'",
            [(string) $lastHost, $threadId],
        );
    }
}
