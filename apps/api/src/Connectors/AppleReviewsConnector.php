<?php

declare(strict_types=1);

namespace Cockpit\Connectors;

use Cockpit\Clock;
use Cockpit\Db;
use Cockpit\HttpClient;

/**
 * Apple Podcasts ratings and reviews from the public iTunes customer reviews feed (read only,
 * up to ~500 per storefront). Replies are not possible at Apple.
 * Feed: https://itunes.apple.com/{cc}/rss/customerreviews/id={id}/sortBy=mostRecent/json
 * The format (feed.entry[] with author.name.label, im:rating.label, title.label, content.label,
 * id.label, updated.label) must be checked against the live feed before go-live.
 */
final class AppleReviewsConnector implements Connector
{
    /** @param list<string> $storefronts */
    public function __construct(
        private readonly Db $db,
        private readonly HttpClient $http,
        private readonly string $podcastId,
        private readonly array $storefronts = ['de'],
    ) {
    }

    public function id(): string
    {
        return 'apple_reviews';
    }

    public function label(): string
    {
        return 'Apple-Bewertungen';
    }

    public function sync(): SyncOutcome
    {
        $new = 0;
        foreach ($this->storefronts as $cc) {
            for ($page = 1; $page <= 10; $page++) {
                $url = sprintf('https://itunes.apple.com/%s/rss/customerreviews/page=%d/id=%s/sortBy=mostRecent/json', $cc, $page, $this->podcastId);
                $res = $this->http->request('GET', $url, ['Accept' => 'application/json']);
                if ($res['status'] !== 200 && $page > 1) {
                    break; // past the last page
                }
                if ($res['status'] !== 200) {
                    throw new \RuntimeException("Der Bewertungs-Feed von Apple ({$cc}) antwortet nicht (HTTP {$res['status']}). Nächster Versuch beim nächsten Lauf.");
                }
                $reviews = self::parse($res['body']);
                $known = 0;
                foreach ($reviews as $review) {
                    $inserted = $this->db->run(
                        "INSERT OR IGNORE INTO comments (platform, external_id, author_name, title, rating, text, posted_at, status, raw_json)
                         VALUES ('apple', ?, ?, ?, ?, ?, ?, 'new', ?)",
                        [$cc . ':' . $review['id'], $review['author'], $review['title'], $review['rating'], $review['text'] ?: '(ohne Text)', $review['posted_at'], $review['raw']],
                    );
                    $inserted ? $new++ : $known++;
                }
                if ($reviews === [] || $known > 0) {
                    break;
                }
            }
        }
        return new SyncOutcome($new, $new === 0 ? 'Keine neuen Bewertungen.' : "{$new} neue Bewertungen.");
    }

    /** @return list<array{id: string, author: string, title: string, rating: int, text: string, posted_at: string, raw: string}> */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Apple hat kein gültiges JSON geliefert.');
        }
        $entries = $data['feed']['entry'] ?? [];
        if (isset($entries['id'])) {
            $entries = [$entries]; // a single entry is not wrapped in a list
        }
        $out = [];
        foreach ($entries as $entry) {
            if (!isset($entry['im:rating']['label'])) {
                continue; // first entry of older feeds describes the podcast itself
            }
            $out[] = [
                'id' => (string) ($entry['id']['label'] ?? ''),
                'author' => (string) ($entry['author']['name']['label'] ?? 'Unbekannt'),
                'title' => (string) ($entry['title']['label'] ?? ''),
                'rating' => (int) $entry['im:rating']['label'],
                'text' => trim((string) ($entry['content']['label'] ?? '')),
                'posted_at' => isset($entry['updated']['label']) ? Clock::iso(Clock::parse((string) $entry['updated']['label'])) : Clock::nowIso(),
                'raw' => (string) json_encode($entry, JSON_UNESCAPED_UNICODE),
            ];
        }
        return array_values(array_filter($out, static fn (array $r): bool => $r['id'] !== ''));
    }
}
