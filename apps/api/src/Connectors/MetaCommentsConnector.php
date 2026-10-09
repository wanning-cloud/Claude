<?php

declare(strict_types=1);

namespace Cockpit\Connectors;

use Cockpit\Clock;
use Cockpit\Db;
use Cockpit\Meta\MetaApi;
use Cockpit\Meta\MetaAuth;

/**
 * Comments on Instagram posts and Facebook page posts every 15 minutes.
 * Cheap on the rate limit: one list call per platform with the comment counts, then comments are
 * only fetched for posts whose count differs from what the inbox already has, plus posts of the
 * last 2 days (edits and hidden comments do not change the count).
 */
final class MetaCommentsConnector implements Connector
{
    public function __construct(private readonly Db $db, private readonly MetaApi $api, private readonly MetaAuth $auth)
    {
    }

    public function id(): string
    {
        return 'meta_comments';
    }

    public function label(): string
    {
        return 'Instagram- und Facebook-Kommentare';
    }

    public function sync(): SyncOutcome
    {
        $new = 0;
        $checked = 0;
        $ig = $this->auth->igUserId();
        if ($ig !== null) {
            foreach ($this->api->all($ig . '/media', ['fields' => 'id,comments_count,timestamp', 'limit' => 50], 2) as $media) {
                if (!$this->needsFetch('instagram', (string) $media['id'], (int) ($media['comments_count'] ?? 0), (string) $media['timestamp'])) {
                    continue;
                }
                $checked++;
                $threads = $this->api->all((string) $media['id'] . '/comments', ['fields' => 'id,text,username,timestamp,replies{id,text,username,timestamp}', 'limit' => 50], 5);
                foreach ($threads as $c) {
                    $parent = $this->upsert('instagram', (string) $media['id'], (string) $c['id'], null, (string) ($c['username'] ?? ''), (string) ($c['text'] ?? ''), (string) $c['timestamp'], $this->isHostInstagram($c), $c, $new);
                    foreach ($c['replies']['data'] ?? [] as $r) {
                        $this->upsert('instagram', (string) $media['id'], (string) $r['id'], $parent, (string) ($r['username'] ?? ''), (string) ($r['text'] ?? ''), (string) $r['timestamp'], $this->isHostInstagram($r), $r, $new);
                    }
                    $this->markAnswered($parent);
                }
            }
        }
        $page = $this->auth->pageId();
        if ($page !== null) {
            foreach ($this->api->all($page . '/published_posts', ['fields' => 'id,created_time,comments.filter(stream).summary(true).limit(0)', 'limit' => 50], 2) as $post) {
                if (!$this->needsFetch('facebook', (string) $post['id'], (int) ($post['comments']['summary']['total_count'] ?? 0), (string) $post['created_time'])) {
                    continue;
                }
                $checked++;
                // filter=stream returns replies too, each with its parent; ordered oldest first so parents exist.
                $all = $this->api->all((string) $post['id'] . '/comments', ['fields' => 'id,message,from{id,name},created_time,parent{id}', 'filter' => 'stream', 'order' => 'chronological', 'limit' => 100], 5);
                $ids = [];
                foreach ($all as $c) {
                    $parentExternal = $c['parent']['id'] ?? null;
                    $parent = $parentExternal === null ? null : ($ids[$parentExternal] ?? $this->localId('facebook', (string) $parentExternal));
                    $fromHost = isset($c['from']['id']) && (string) $c['from']['id'] === $page;
                    $author = (string) ($c['from']['name'] ?? 'Facebook-Nutzer');
                    $ids[(string) $c['id']] = $this->upsert('facebook', (string) $post['id'], (string) $c['id'], $parent, $author, (string) ($c['message'] ?? ''), (string) $c['created_time'], $fromHost, $c, $new);
                }
                foreach ($ids as $externalId => $localId) {
                    $this->markAnswered($localId);
                }
            }
        }
        return new SyncOutcome($new, ($new === 0 ? 'Keine neuen Kommentare.' : "{$new} neue Kommentare.") . " {$checked} Beiträge geprüft.");
    }

    private function needsFetch(string $platform, string $objectId, int $count, string $publishedAt): bool
    {
        if ($count === 0) {
            return false;
        }
        if (Clock::parse($publishedAt) > Clock::now()->modify('-2 days')) {
            return true;
        }
        $known = (int) $this->db->value('SELECT COUNT(*) FROM comments WHERE platform = ? AND video_id = ? AND external_id NOT LIKE ?', [$platform, $objectId, 'reply:%']);
        return $known !== $count;
    }

    /** @param array<string, mixed> $c */
    private function isHostInstagram(array $c): bool
    {
        $own = $this->auth->igUsername();
        return $own !== null && strcasecmp((string) ($c['username'] ?? ''), $own) === 0;
    }

    private function localId(string $platform, string $externalId): ?int
    {
        $v = $this->db->value('SELECT id FROM comments WHERE platform = ? AND external_id = ?', [$platform, $externalId]);
        return $v === null ? null : (int) $v;
    }

    /** @param array<string, mixed> $raw */
    private function upsert(string $platform, string $objectId, string $externalId, ?int $parentId, string $author, string $text, string $postedAt, bool $fromHost, array $raw, int &$new): int
    {
        $existing = $this->localId($platform, $externalId);
        $values = [
            'parent_id' => $parentId,
            'video_id' => $objectId,
            'author_name' => $author !== '' ? $author : ($platform === 'instagram' ? 'Instagram-Nutzer' : 'Facebook-Nutzer'),
            'text' => $text,
            'posted_at' => Clock::iso(Clock::parse($postedAt)),
            'from_host' => $fromHost ? 1 : 0,
            'raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE),
        ];
        if ($existing !== null) {
            $this->db->run(
                'UPDATE comments SET parent_id = :parent_id, video_id = :video_id, author_name = :author_name, text = :text,
                   posted_at = :posted_at, from_host = :from_host, raw_json = :raw_json WHERE id = :id',
                $values + ['id' => $existing],
            );
            return $existing;
        }
        $status = ($fromHost || $parentId !== null) ? 'done' : 'new';
        $this->db->run(
            'INSERT INTO comments (platform, external_id, parent_id, video_id, author_name, text, posted_at, status, from_host, raw_json)
             VALUES (:platform, :external_id, :parent_id, :video_id, :author_name, :text, :posted_at, :status, :from_host, :raw_json)',
            $values + ['platform' => $platform, 'external_id' => $externalId, 'status' => $status],
        );
        if ($status === 'new') {
            $new++;
        }
        return $this->db->lastId();
    }

    /** A thread is "answered" once the host replied after the listener comment. */
    private function markAnswered(int $threadId): void
    {
        $lastHost = $this->db->value('SELECT MAX(posted_at) FROM comments WHERE parent_id = ? AND from_host = 1', [$threadId]);
        if ($lastHost !== null) {
            $this->db->run("UPDATE comments SET status = 'answered', answered_at = COALESCE(answered_at, ?) WHERE id = ? AND status = 'new'", [(string) $lastHost, $threadId]);
        }
    }
}
