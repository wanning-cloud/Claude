<?php

declare(strict_types=1);

namespace Cockpit\Connectors;

use Cockpit\Clock;
use Cockpit\Db;
use Cockpit\HttpClient;

/** Episode master data from feed.xml – the source of truth. Read only, the feed is never changed. */
final class FeedConnector implements Connector
{
    public function __construct(private readonly Db $db, private readonly HttpClient $http, private readonly string $feedUrl)
    {
    }

    public function id(): string
    {
        return 'feed';
    }

    public function label(): string
    {
        return 'Feed (Folgen)';
    }

    public function sync(): SyncOutcome
    {
        $res = $this->http->request('GET', $this->feedUrl, ['Accept' => 'application/rss+xml, application/xml']);
        if ($res['status'] !== 200) {
            throw new \RuntimeException("Feed nicht erreichbar (HTTP {$res['status']}).");
        }
        $items = self::parse($res['body']);
        $now = Clock::nowIso();
        $this->db->tx(function () use ($items, $now): void {
            foreach ($items as $item) {
                $this->db->run(
                    'INSERT INTO episodes (guid, number, title, published_at, mp3_url, mp3_path, bytes, duration_s, updated_at)
                     VALUES (:guid, :number, :title, :published_at, :mp3_url, :mp3_path, :bytes, :duration_s, :now)
                     ON CONFLICT (guid) DO UPDATE SET number = excluded.number, title = excluded.title,
                       published_at = excluded.published_at, mp3_url = excluded.mp3_url, mp3_path = excluded.mp3_path,
                       bytes = excluded.bytes, duration_s = excluded.duration_s, updated_at = excluded.updated_at',
                    $item + ['now' => $now],
                );
            }
        });
        return new SyncOutcome(count($items), count($items) . ' Folgen im Feed.');
    }

    /**
     * Only published items (pubDate not in the future).
     * @return list<array{guid: string, number: int|null, title: string, published_at: string, mp3_url: string, mp3_path: string, bytes: int|null, duration_s: int|null}>
     */
    public static function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($previous);
        if ($doc === false || !isset($doc->channel)) {
            throw new \RuntimeException('Der Feed ist kein gültiges RSS.');
        }
        $now = Clock::now();
        $out = [];
        foreach ($doc->channel->item as $item) {
            $itunes = $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
            $enclosure = $item->enclosure;
            $url = $enclosure ? (string) $enclosure['url'] : '';
            $title = trim((string) $item->title);
            $pub = trim((string) $item->pubDate);
            if ($url === '' || $title === '' || $pub === '') {
                continue;
            }
            $published = Clock::parse($pub);
            if ($published > $now) {
                continue;
            }
            $guid = trim((string) $item->guid) ?: $url;
            $number = trim((string) $itunes->episode);
            if ($number === '' && preg_match('/Folge\s*0*(\d+)/iu', $title, $m)) {
                $number = $m[1];
            }
            $length = (int) ($enclosure['length'] ?? 0);
            $out[] = [
                'guid' => $guid,
                'number' => $number === '' ? null : (int) $number,
                'title' => $title,
                'published_at' => Clock::iso($published),
                'mp3_url' => $url,
                'mp3_path' => rawurldecode((string) parse_url($url, PHP_URL_PATH)),
                'bytes' => $length > 0 ? $length : null,
                'duration_s' => self::duration(trim((string) $itunes->duration)),
            ];
        }
        return $out;
    }

    /** "1:02:03", "62:03" or "3723" → seconds. */
    public static function duration(string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $parts = array_map('intval', explode(':', $value));
        $seconds = 0;
        foreach ($parts as $part) {
            $seconds = $seconds * 60 + $part;
        }
        return $seconds > 0 ? $seconds : null;
    }
}
