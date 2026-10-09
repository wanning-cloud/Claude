<?php

declare(strict_types=1);

namespace Cockpit\Repo;

use Cockpit\Clock;
use Cockpit\Db;
use Cockpit\Google\YouTubeApi;
use Cockpit\Http\HttpException;
use Cockpit\Meta\MetaApi;

/**
 * Shared inbox for all platforms. Top level comments are the inbox items; replies hang below.
 * Area "social" = Instagram, Facebook and comments on YouTube Shorts; "podcast" = everything else.
 */
final class Comments
{
    public const PAGE_SIZE = 50;
    public const SOCIAL_SQL = "(c.platform IN ('instagram', 'facebook') OR (c.platform = 'youtube' AND c.video_id IN (SELECT external_id FROM social_posts WHERE platform = 'youtube_shorts')))";

    public function __construct(private readonly Db $db)
    {
    }

    /** @param array{platform?: string|null, area?: string|null, episode?: int|null, status?: string|null, page?: int} $filter */
    public function list(array $filter): array
    {
        $where = ['c.parent_id IS NULL', 'c.from_host = 0'];
        $params = [];
        if (!empty($filter['platform'])) {
            $where[] = 'c.platform = ?';
            $params[] = $filter['platform'];
        }
        if (($filter['area'] ?? null) === 'social') {
            $where[] = self::SOCIAL_SQL;
        } elseif (($filter['area'] ?? null) === 'podcast') {
            $where[] = 'NOT ' . self::SOCIAL_SQL;
        }
        if (!empty($filter['episode'])) {
            $where[] = 'c.episode_id = ?';
            $params[] = (int) $filter['episode'];
        }
        if (!empty($filter['status'])) {
            $where[] = 'c.status = ?';
            $params[] = $filter['status'];
        }
        $sqlWhere = implode(' AND ', $where);
        $page = max(1, (int) ($filter['page'] ?? 1));
        $total = (int) $this->db->value("SELECT COUNT(*) FROM comments c WHERE {$sqlWhere}", $params);
        $rows = $this->db->all(
            "SELECT c.* FROM comments c WHERE {$sqlWhere} ORDER BY c.posted_at DESC, c.id DESC LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE),
            $params,
        );
        return [
            'items' => $this->hydrate($rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            'newCount' => $this->newCount(),
        ];
    }

    public function newCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM comments WHERE parent_id IS NULL AND from_host = 0 AND status = 'new'");
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        $row = $this->db->one('SELECT * FROM comments WHERE id = ? AND parent_id IS NULL', [$id]);
        if ($row === null) {
            throw new HttpException(404, 'Kommentar nicht gefunden.');
        }
        return $this->hydrate([$row])[0];
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    public function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $in = implode(',', $ids);
        $replies = [];
        foreach ($this->db->all("SELECT * FROM comments WHERE parent_id IN ({$in}) ORDER BY posted_at") as $r) {
            $replies[(int) $r['parent_id']][] = [
                'id' => (int) $r['id'],
                'author' => $r['author_name'],
                'text' => $r['text'],
                'postedAt' => $r['posted_at'],
                'fromHost' => (bool) $r['from_host'],
            ];
        }
        foreach ($this->db->all("SELECT * FROM reply_queue WHERE comment_id IN ({$in}) AND state <> 'sent' ORDER BY approved_at") as $q) {
            $replies[(int) $q['comment_id']][] = [
                'id' => -(int) $q['id'],
                'author' => 'Markus Wanning',
                'text' => $q['text'],
                'postedAt' => $q['approved_at'],
                'fromHost' => true,
                'state' => $q['state'],
                'error' => $q['error'],
            ];
        }
        $posts = [];
        $shorts = [];
        foreach ($this->db->all('SELECT platform, external_id, permalink FROM social_posts') as $p) {
            $posts[$p['platform'] . ':' . $p['external_id']] = $p['permalink'];
            if ($p['platform'] === 'youtube_shorts') {
                $shorts[(string) $p['external_id']] = true;
            }
        }
        $episodes = [];
        foreach ($this->db->all('SELECT id, number, title FROM episodes') as $e) {
            $episodes[(int) $e['id']] = ['id' => (int) $e['id'], 'number' => $e['number'] === null ? null : (int) $e['number'], 'title' => $e['title']];
        }
        return array_map(function (array $r) use ($replies, $episodes, $posts, $shorts): array {
            $platform = (string) $r['platform'];
            return [
                'id' => (int) $r['id'],
                'platform' => $platform,
                'area' => in_array($platform, ['instagram', 'facebook'], true) || ($platform === 'youtube' && isset($shorts[(string) $r['video_id']])) ? 'social' : 'podcast',
                'isShort' => $platform === 'youtube' && isset($shorts[(string) $r['video_id']]),
                'episode' => $r['episode_id'] === null ? null : ($episodes[(int) $r['episode_id']] ?? null),
                'author' => $r['author_name'],
                'authorUrl' => $r['author_url'],
                'text' => $r['text'],
                'rating' => $r['rating'] === null ? null : (int) $r['rating'],
                'title' => $r['title'],
                'postedAt' => $r['posted_at'],
                'status' => $r['status'],
                'note' => $r['note'],
                'replies' => $replies[(int) $r['id']] ?? [],
                'replyMode' => match ($platform) { 'youtube', 'instagram', 'facebook' => 'api', 'spotify' => 'queue', default => 'none' },
                'externalUrl' => match ($platform) {
                    'instagram', 'facebook' => $posts[$platform . ':' . $r['video_id']] ?? null,
                    'youtube' => isset($shorts[(string) $r['video_id']]) ? 'https://www.youtube.com/shorts/' . rawurlencode((string) $r['video_id']) : $this->externalUrl($r),
                    default => $this->externalUrl($r),
                },
            ];
        }, $rows);
    }

    private function externalUrl(array $r): ?string
    {
        return match ((string) $r['platform']) {
            'youtube' => $r['video_id'] ? 'https://www.youtube.com/watch?v=' . rawurlencode((string) $r['video_id']) . '&lc=' . rawurlencode((string) $r['external_id']) : null,
            'spotify' => 'https://creators.spotify.com/',
            'apple' => 'https://podcasts.apple.com/de/podcast/id6819469570?see-all=reviews',
            default => null,
        };
    }

    /** @param array{status?: string, note?: string|null} $patch */
    public function update(int $id, array $patch, string $actor): array
    {
        $this->get($id);
        if (array_key_exists('status', $patch)) {
            $this->db->run('UPDATE comments SET status = ? WHERE id = ?', [$patch['status'], $id]);
        }
        if (array_key_exists('note', $patch)) {
            $note = $patch['note'] === null || trim($patch['note']) === '' ? null : trim($patch['note']);
            $this->db->run('UPDATE comments SET note = ? WHERE id = ?', [$note, $id]);
        }
        $this->db->audit($actor, 'comment.update', (string) $id, $patch);
        return $this->get($id);
    }

    /**
     * Markus clicked "Antwort senden": that click is the approval for exactly this reply.
     * YouTube: stored as "sending", then comments.insert, then marked sent. On error the text is kept.
     * Spotify: queued for the next routine run.
     * Instagram and Facebook: like YouTube, sent right away through the Graph API.
     */
    public function reply(int $id, string $text, string $actor, ?YouTubeApi $youtube, ?MetaApi $meta = null): array
    {
        $comment = $this->db->one('SELECT * FROM comments WHERE id = ? AND parent_id IS NULL', [$id]);
        if ($comment === null) {
            throw new HttpException(404, 'Kommentar nicht gefunden.');
        }
        $text = trim($text);
        $platform = (string) $comment['platform'];
        if (!in_array($platform, ['youtube', 'spotify', 'instagram', 'facebook'], true)) {
            throw new HttpException(422, $platform === 'apple' ? 'Antwort bei Apple nicht möglich.' : 'Auf diesem Portal kann das Cockpit nicht antworten.');
        }
        $now = Clock::nowIso();
        $channel = $platform === 'spotify' ? 'routine' : 'api';
        $this->db->run(
            'INSERT INTO reply_queue (comment_id, text, channel, state, approved_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $text, $channel, $channel === 'api' ? 'sending' : 'queued', $now],
        );
        $replyId = $this->db->lastId();
        $this->db->audit($actor, 'reply.approved', (string) $id, ['reply_id' => $replyId, 'platform' => $platform, 'text' => $text]);

        if ($channel === 'routine') {
            return $this->get($id);
        }
        $name = ['youtube' => 'YouTube', 'instagram' => 'Instagram', 'facebook' => 'Facebook'][$platform];
        if (($platform === 'youtube' && $youtube === null) || ($platform !== 'youtube' && $meta === null)) {
            $this->failReply($replyId, "{$name} ist nicht verbunden.");
            throw new HttpException(409, "{$name} ist nicht verbunden. Deine Antwort ist gespeichert und wurde nicht gesendet.");
        }
        try {
            $result = match ($platform) {
                'youtube' => $youtube->post('comments', ['part' => 'snippet'], ['snippet' => ['parentId' => $comment['external_id'], 'textOriginal' => $text]]),
                'instagram' => $meta->post((string) $comment['external_id'] . '/replies', ['message' => $text]),
                'facebook' => $meta->post((string) $comment['external_id'] . '/comments', ['message' => $text]),
            };
        } catch (\Throwable $e) {
            $this->failReply($replyId, $e->getMessage());
            throw new HttpException(502, 'Antwort nicht gesendet: ' . $e->getMessage() . ' Dein Text ist gespeichert.');
        }
        $externalId = (string) ($result['id'] ?? '');
        $this->db->tx(function () use ($replyId, $externalId, $comment, $text, $id, $actor): void {
            $at = Clock::nowIso();
            $this->db->run("UPDATE reply_queue SET state = 'sent', sent_at = ?, external_id = ?, error = NULL WHERE id = ?", [$at, $externalId, $replyId]);
            $this->db->run(
                "INSERT OR IGNORE INTO comments (platform, external_id, parent_id, episode_id, video_id, author_name, text, posted_at, status, from_host)
                 VALUES (?, ?, ?, ?, ?, 'Markus Wanning', ?, ?, 'done', 1)",
                [$comment['platform'], $externalId ?: 'reply:' . $replyId, $id, $comment['episode_id'], $comment['video_id'], $text, $at],
            );
            $this->db->run("UPDATE comments SET status = 'answered', answered_at = ? WHERE id = ?", [$at, $id]);
            $this->db->audit($actor, 'reply.sent', (string) $id, ['reply_id' => $replyId, 'external_id' => $externalId]);
        });
        return $this->get($id);
    }

    private function failReply(int $replyId, string $error): void
    {
        $this->db->run("UPDATE reply_queue SET state = 'failed', error = ? WHERE id = ?", [$error, $replyId]);
    }

    /** Replies waiting for the routine (Spotify). @return list<array<string, mixed>> */
    public function queue(): array
    {
        $rows = $this->db->all(
            "SELECT q.*, c.platform, c.external_id AS comment_ref, c.author_name, c.text AS comment_text, c.episode_id, e.number, e.title AS episode_title
             FROM reply_queue q JOIN comments c ON c.id = q.comment_id LEFT JOIN episodes e ON e.id = c.episode_id
             WHERE q.channel = 'routine' AND q.state = 'queued' ORDER BY q.approved_at",
        );
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'commentId' => (int) $r['comment_id'],
            'platform' => $r['platform'],
            'episode' => $r['episode_id'] === null ? null : ['id' => (int) $r['episode_id'], 'number' => $r['number'] === null ? null : (int) $r['number'], 'title' => $r['episode_title']],
            'commentAuthor' => $r['author_name'],
            'commentText' => $r['comment_text'],
            'externalRef' => $r['comment_ref'],
            'text' => $r['text'],
            'approvedAt' => $r['approved_at'],
            'sentAt' => $r['sent_at'],
        ], $rows);
    }
}
