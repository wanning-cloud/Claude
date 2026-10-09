<?php

declare(strict_types=1);

namespace Cockpit\Connectors;

use Cockpit\Clock;
use Cockpit\Db;
use Cockpit\Meta\MetaApi;
use Cockpit\Meta\MetaAuth;
use Cockpit\Repo\Social;

/**
 * Facebook page "Der Monteur Podcast", every 6 hours:
 * 1. Follower count as a daily snapshot.
 * 2. Page values per day: views (page_media_view), reached accounts (page_total_media_view_unique),
 *    interactions (page_post_engagements). Since 15.11.2025 Meta has no "impressions" any more.
 * 3. Posts: reactions, comments and shares from the post fields, views and reach from post insights.
 * Facebook groups are not covered: Meta switched the Groups API off in 2024.
 */
final class FacebookConnector implements Connector
{
    public const POST_FIELDS = 'id,message,created_time,permalink_url,full_picture,attachments{media_type},reactions.summary(total_count).limit(0),comments.filter(stream).summary(true).limit(0),shares';
    public const PAGE_METRICS = ['page_media_view' => 'views', 'page_total_media_view_unique' => 'reach', 'page_post_engagements' => 'interactions'];
    public const POST_METRICS = ['post_media_view' => 'views', 'post_total_media_view_unique' => 'reach', 'post_clicks' => 'clicks'];
    private const BACKFILL_DAYS = 28;

    public function __construct(private readonly Db $db, private readonly MetaApi $api, private readonly MetaAuth $auth)
    {
    }

    public function id(): string
    {
        return 'facebook';
    }

    public function label(): string
    {
        return 'Facebook-Seite (Beiträge und Zahlen)';
    }

    public function sync(): SyncOutcome
    {
        $page = $this->auth->pageId() ?? throw new \RuntimeException('Keine Facebook-Seite verbunden. Unter Automatik „Meta verbinden“ klicken.');
        $social = new Social($this->db);
        $this->api->skippedMetrics = [];

        $info = $this->api->get($page, ['fields' => 'followers_count,fan_count']);
        $followers = $info['followers_count'] ?? $info['fan_count'] ?? null;
        if ($followers !== null) {
            $social->storeAccountDaily('facebook', 'followers', Clock::today(), (float) $followers);
        }

        $yesterday = Clock::addDays(Clock::today(), -1);
        $last = $social->lastAccountDate('facebook', 'views');
        $start = $last === null ? Clock::addDays($yesterday, -(self::BACKFILL_DAYS - 1)) : max(Clock::addDays($last, -2), Clock::addDays($yesterday, -(self::BACKFILL_DAYS - 1)));
        $since = (new \DateTimeImmutable($start . ' 00:00:00', Clock::tz()))->getTimestamp();
        $until = (new \DateTimeImmutable(Clock::today() . ' 00:00:00', Clock::tz()))->getTimestamp();
        $daily = $this->api->dailyInsights($page, array_keys(self::PAGE_METRICS), ['since' => $since, 'until' => $until]);
        $dayValues = 0;
        foreach ($daily as $metaName => $series) {
            foreach ($series as $day => $value) {
                if ($day >= $start && $day <= $yesterday) {
                    $social->storeAccountDaily('facebook', self::PAGE_METRICS[$metaName] ?? $metaName, $day, $value);
                    $dayValues++;
                }
            }
        }

        $posts = $this->api->all($page . '/published_posts', ['fields' => self::POST_FIELDS, 'limit' => 50], 4);
        $refreshOldBefore = Clock::addDays(Clock::today(), -30);
        $weekAgo = Clock::addDays(Clock::today(), -7);
        foreach ($posts as $post) {
            $published = Clock::iso(Clock::parse((string) $post['created_time']));
            $id = $social->upsertPost('facebook', (string) $post['id'], [
                'format' => self::format($post),
                'caption' => isset($post['message']) ? (string) $post['message'] : null,
                'permalink' => $post['permalink_url'] ?? null,
                'thumbnail' => $post['full_picture'] ?? null,
                'publishedAt' => $published,
            ]);
            $values = [
                'likes' => $post['reactions']['summary']['total_count'] ?? null,
                'comments' => $post['comments']['summary']['total_count'] ?? null,
                'shares' => $post['shares']['count'] ?? (isset($post['id']) ? 0 : null),
            ];
            $lastInsight = $this->db->value("SELECT MAX(date) FROM social_post_metrics_daily WHERE post_id = ? AND metric IN ('views', 'reach')", [$id]);
            if (Clock::dateOf($published) >= $refreshOldBefore || $lastInsight === null || (string) $lastInsight < $weekAgo) {
                foreach ($this->api->insights((string) $post['id'], array_keys(self::POST_METRICS), ['period' => 'lifetime']) as $metaName => $value) {
                    $values[self::POST_METRICS[$metaName] ?? $metaName] = $value;
                }
            }
            $social->storePostMetrics($id, $values);
        }

        $message = count($posts) . " Beiträge, {$dayValues} Tageswerte der Seite.";
        if ($this->api->skippedMetrics !== []) {
            $message .= ' Von Meta nicht mehr geliefert und übersprungen: ' . implode(', ', array_unique($this->api->skippedMetrics)) . '.';
        }
        return new SyncOutcome(count($posts), $message);
    }

    /** @param array<string, mixed> $post */
    public static function format(array $post): string
    {
        $type = strtolower((string) ($post['attachments']['data'][0]['media_type'] ?? ''));
        return match (true) {
            $type === 'photo' => 'image',
            $type === 'album' => 'carousel',
            str_contains($type, 'video') || $type === 'reel' => 'video',
            default => 'text',
        };
    }
}
