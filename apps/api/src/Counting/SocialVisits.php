<?php

declare(strict_types=1);

namespace Cockpit\Counting;

use Cockpit\Clock;
use Cockpit\Db;

/**
 * Page views on monteur-podcast.de that came through a tagged social link (utm_source=…).
 * Read from the same access logs as the downloads. Counts requests, not people; no IP is stored.
 * This is the only bridge between social media and the website, and it is shown in the social
 * area only, never in the podcast reach.
 */
final class SocialVisits
{
    public const SOURCES = ['instagram', 'facebook', 'youtube_shorts', 'fb_gruppe'];

    public int $counted = 0;

    public function __construct(private readonly Db $db, private readonly UserAgents $agents)
    {
    }

    /** @param array{time: string, method: string, path: string, query?: string, status: int, ua: string} $event */
    public function add(array $event): void
    {
        $query = $event['query'] ?? '';
        if ($query === '' || stripos($query, 'utm_source=') === false) {
            return;
        }
        if ($event['method'] !== 'GET' || $event['status'] !== 200 || $this->agents->isBot($event['ua'])) {
            return;
        }
        // Pages only: no media, images, scripts or feeds.
        if (preg_match('/\.(mp3|m4a|mp4|jpe?g|png|webp|gif|svg|css|js|json|xml|woff2?|ico|txt)$/i', $event['path'])) {
            return;
        }
        parse_str($query, $params);
        $source = strtolower(trim((string) ($params['utm_source'] ?? '')));
        if (!in_array($source, self::SOURCES, true)) {
            return;
        }
        $day = (new \DateTimeImmutable($event['time']))->setTimezone(Clock::tz())->format('Y-m-d');
        $this->db->run(
            'INSERT INTO social_visits_daily (date, source, visits) VALUES (?, ?, 1)
             ON CONFLICT (date, source) DO UPDATE SET visits = visits + 1',
            [$day, $source],
        );
        $this->counted++;
    }
}
