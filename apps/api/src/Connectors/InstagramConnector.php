<?php

declare(strict_types=1);

namespace Cockpit\Connectors;

use Cockpit\Clock;
use Cockpit\Db;
use Cockpit\Meta\MetaApi;
use Cockpit\Meta\MetaAuth;
use Cockpit\Repo\Social;

/**
 * Instagram professional account (via the Facebook page), every 6 hours:
 * 1. Follower count as a daily snapshot.
 * 2. Posts and reels: likes and comments from the media fields; views, reach, saves and shares from
 *    the media insights. Posts younger than 30 days every run, older ones once a week.
 * 3. Account values per day (reach, views, interactions …), one request per missing day, at most 28.
 */
final class InstagramConnector implements Connector
{
    public const MEDIA_FIELDS = 'id,caption,media_type,media_product_type,permalink,thumbnail_url,media_url,timestamp,like_count,comments_count';
    public const FEED_METRICS = ['views', 'reach', 'saved', 'shares', 'total_interactions'];
    public const REEL_METRICS = ['views', 'reach', 'saved', 'shares', 'total_interactions', 'ig_reels_avg_watch_time'];
    public const ACCOUNT_METRICS = ['reach', 'views', 'accounts_engaged', 'total_interactions', 'likes', 'comments', 'saves', 'shares', 'profile_links_taps'];
    private const BACKFILL_DAYS = 28;

    public function __construct(private readonly Db $db, private readonly MetaApi $api, private readonly MetaAuth $auth)
    {
    }

    public function id(): string
    {
        return 'instagram';
    }

    public function label(): string
    {
        return 'Instagram (Beiträge und Zahlen)';
    }

    public function sync(): SyncOutcome
    {
        $ig = $this->auth->igUserId() ?? throw new \RuntimeException('Kein Instagram-Profikonto mit der Facebook-Seite verknüpft. In Instagram das Profikonto mit der Seite „Der Monteur Podcast“ verbinden, dann „Meta verbinden“ erneut klicken.');
        $social = new Social($this->db);
        $this->api->skippedMetrics = [];

        $account = $this->api->get($ig, ['fields' => 'followers_count,media_count,username']);
        if (isset($account['followers_count'])) {
            $social->storeAccountDaily('instagram', 'followers', Clock::today(), (float) $account['followers_count']);
        }

        $media = $this->api->all($ig . '/media', ['fields' => self::MEDIA_FIELDS, 'limit' => 50], 4);
        $refreshOldBefore = Clock::addDays(Clock::today(), -30);
        $weekAgo = Clock::addDays(Clock::today(), -7);
        $insightCalls = 0;
        foreach ($media as $item) {
            $published = Clock::iso(Clock::parse((string) $item['timestamp']));
            $id = $social->upsertPost('instagram', (string) $item['id'], [
                'format' => self::format($item),
                'caption' => isset($item['caption']) ? (string) $item['caption'] : null,
                'permalink' => $item['permalink'] ?? null,
                'thumbnail' => $item['thumbnail_url'] ?? $item['media_url'] ?? null,
                'publishedAt' => $published,
            ]);
            $values = ['likes' => $item['like_count'] ?? null, 'comments' => $item['comments_count'] ?? null];
            $old = Clock::dateOf($published) < $refreshOldBefore;
            $lastInsight = $this->db->value("SELECT MAX(date) FROM social_post_metrics_daily WHERE post_id = ? AND metric IN ('views', 'reach')", [$id]);
            if (!$old || $lastInsight === null || (string) $lastInsight < $weekAgo) {
                $metrics = ($item['media_product_type'] ?? '') === 'REELS' ? self::REEL_METRICS : self::FEED_METRICS;
                $insights = $this->api->insights((string) $item['id'], $metrics);
                $insightCalls++;
                $values += self::mapInsights($insights);
            }
            $social->storePostMetrics($id, $values);
        }

        $days = $this->accountDays($ig, $social);
        $message = count($media) . " Beiträge, {$insightCalls} mit neuen Statistiken, {$days} Tage Kontowerte.";
        if ($this->api->skippedMetrics !== []) {
            $message .= ' Von Meta nicht mehr geliefert und übersprungen: ' . implode(', ', array_unique($this->api->skippedMetrics)) . '.';
        }
        return new SyncOutcome(count($media), $message);
    }

    /** Account values for every missing day up to yesterday (the last 3 days again, Meta corrects late). */
    private function accountDays(string $ig, Social $social): int
    {
        $yesterday = Clock::addDays(Clock::today(), -1);
        $last = $social->lastAccountDate('instagram', 'reach');
        $start = $last === null ? Clock::addDays($yesterday, -(self::BACKFILL_DAYS - 1)) : max(Clock::addDays($last, -2), Clock::addDays($yesterday, -(self::BACKFILL_DAYS - 1)));
        $count = 0;
        foreach (Clock::dates($start, $yesterday) as $day) {
            $since = (new \DateTimeImmutable($day . ' 00:00:00', Clock::tz()))->getTimestamp();
            $values = $this->api->insights($ig, self::ACCOUNT_METRICS, ['metric_type' => 'total_value', 'period' => 'day', 'since' => $since, 'until' => $since + 86400]);
            foreach ($values as $metric => $value) {
                $social->storeAccountDaily('instagram', (string) $metric, $day, $value);
            }
            $count++;
        }
        return $count;
    }

    /** @param array<string, mixed> $item */
    public static function format(array $item): string
    {
        $product = (string) ($item['media_product_type'] ?? 'FEED');
        $type = (string) ($item['media_type'] ?? '');
        return match (true) {
            $product === 'REELS' => 'reel',
            $product === 'STORY' => 'story',
            $type === 'CAROUSEL_ALBUM' => 'carousel',
            $type === 'VIDEO' => 'video',
            default => 'image',
        };
    }

    /** Meta names → cockpit names. @param array<string, float> $insights @return array<string, float> */
    public static function mapInsights(array $insights): array
    {
        $map = ['views' => 'views', 'reach' => 'reach', 'saved' => 'saves', 'shares' => 'shares', 'total_interactions' => 'total_interactions', 'ig_reels_avg_watch_time' => 'avg_watch_ms', 'replies' => 'replies', 'navigation' => 'navigation', 'follows' => 'follows', 'profile_visits' => 'profile_visits'];
        $out = [];
        foreach ($insights as $name => $value) {
            if (isset($map[$name])) {
                $out[$map[$name]] = $value;
            }
        }
        return $out;
    }
}
