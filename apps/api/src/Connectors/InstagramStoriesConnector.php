<?php

declare(strict_types=1);

namespace Cockpit\Connectors;

use Cockpit\Clock;
use Cockpit\Db;
use Cockpit\Meta\MetaApi;
use Cockpit\Meta\MetaAuth;
use Cockpit\Repo\Social;

/**
 * Instagram stories every 2 hours. Meta only reports story numbers while the story is live (24 hours),
 * so every run stores the current values; after expiry the last stored values remain.
 * Stories under 5 views have no numbers at all ("not enough viewers").
 */
final class InstagramStoriesConnector implements Connector
{
    public const METRICS = ['views', 'reach', 'replies', 'shares', 'navigation', 'follows', 'profile_visits'];

    public function __construct(private readonly Db $db, private readonly MetaApi $api, private readonly MetaAuth $auth)
    {
    }

    public function id(): string
    {
        return 'instagram_stories';
    }

    public function label(): string
    {
        return 'Instagram-Stories';
    }

    public function sync(): SyncOutcome
    {
        $ig = $this->auth->igUserId() ?? throw new \RuntimeException('Kein Instagram-Profikonto mit der Facebook-Seite verknüpft.');
        $social = new Social($this->db);
        $this->api->skippedMetrics = [];
        $stories = $this->api->all($ig . '/stories', ['fields' => 'id,caption,media_type,permalink,thumbnail_url,media_url,timestamp', 'limit' => 50], 2);
        $withNumbers = 0;
        foreach ($stories as $story) {
            $at = Clock::parse((string) $story['timestamp']);
            $id = $social->upsertPost('instagram', (string) $story['id'], [
                'format' => 'story',
                'caption' => isset($story['caption']) ? (string) $story['caption'] : null,
                'permalink' => $story['permalink'] ?? null,
                'thumbnail' => $story['thumbnail_url'] ?? $story['media_url'] ?? null,
                'publishedAt' => Clock::iso($at),
                'expiresAt' => Clock::iso($at->modify('+24 hours')),
            ]);
            $values = InstagramConnector::mapInsights($this->api->insights((string) $story['id'], self::METRICS));
            if ($values !== []) {
                $withNumbers++;
            }
            $social->storePostMetrics($id, $values);
        }
        $message = count($stories) === 0 ? 'Gerade keine Story live.' : count($stories) . " Stories live, {$withNumbers} mit Zahlen (unter 5 Aufrufen liefert Meta keine).";
        return new SyncOutcome(count($stories), $message);
    }
}
