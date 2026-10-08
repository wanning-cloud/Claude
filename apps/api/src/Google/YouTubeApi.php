<?php

declare(strict_types=1);

namespace Cockpit\Google;

use Cockpit\HttpClient;

/**
 * Thin client for YouTube Data API v3 and YouTube Analytics API v2.
 * Quota (Data API, 10,000 units/day): list calls 1 unit, comments.insert and setModerationStatus 50 each.
 * search.list (100) is never used; videos come from the uploads playlist.
 */
final class YouTubeApi
{
    private const DATA = 'https://www.googleapis.com/youtube/v3/';
    private const ANALYTICS = 'https://youtubeanalytics.googleapis.com/v2/reports';

    public function __construct(private readonly HttpClient $http, private readonly YouTubeAuth $auth)
    {
    }

    /** @param array<string, string|int> $query @return array<string, mixed> */
    public function get(string $resource, array $query): array
    {
        return $this->call('GET', self::DATA . $resource . '?' . http_build_query($query));
    }

    /** @param array<string, string|int> $query @param array<string, mixed>|null $body @return array<string, mixed> */
    public function post(string $resource, array $query, ?array $body = null): array
    {
        return $this->call('POST', self::DATA . $resource . '?' . http_build_query($query), $body);
    }

    /** @param array<string, string|int> $query @return list<array<string, mixed>> rows keyed by column name */
    public function report(array $query): array
    {
        $data = $this->call('GET', self::ANALYTICS . '?' . http_build_query($query));
        $columns = array_map(static fn (array $h): string => (string) $h['name'], $data['columnHeaders'] ?? []);
        $rows = [];
        foreach ($data['rows'] ?? [] as $row) {
            $rows[] = array_combine($columns, $row);
        }
        return $rows;
    }

    /** @param array<string, mixed>|null $body @return array<string, mixed> */
    private function call(string $method, string $url, ?array $body = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->auth->accessToken(), 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $res = $this->http->request($method, $url, $headers, $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_UNICODE));
        $data = $res['body'] === '' ? [] : json_decode($res['body'], true);
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return is_array($data) ? $data : [];
        }
        $reason = (string) ($data['error']['errors'][0]['reason'] ?? $data['error']['status'] ?? '');
        $message = (string) ($data['error']['message'] ?? '');
        throw new \RuntimeException(match (true) {
            in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true) => 'YouTube-Tageskontingent ist aufgebraucht. Es setzt sich um 9 Uhr (Pacific Time) zurück.',
            $res['status'] === 401 => 'YouTube hat die Anmeldung abgelehnt. Bitte unter Automatik „YouTube verbinden“ erneut klicken.',
            $res['status'] === 403 && $reason === 'commentsDisabled' => 'Kommentare sind bei diesem Video deaktiviert.',
            $res['status'] === 403 => "YouTube verweigert den Zugriff ({$reason}). Sind beide APIs im Google-Cloud-Projekt aktiviert?",
            default => "YouTube-Fehler HTTP {$res['status']}: {$message}",
        });
    }
}
