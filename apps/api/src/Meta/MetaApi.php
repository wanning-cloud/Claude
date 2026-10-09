<?php

declare(strict_types=1);

namespace Cockpit\Meta;

use Cockpit\HttpClient;

/**
 * Thin client for the Graph API (Facebook page and the linked Instagram professional account,
 * "Instagram API with Facebook Login"). Every call uses the page access token.
 *
 * Meta renames and retires insight metrics often (impressions → views in 2025, page_impressions →
 * page_media_view on 15.11.2025). insights() therefore never lets one unknown metric break a sync:
 * it asks for all metrics at once and, if Meta rejects the list, asks one by one and skips the
 * rejected names.
 */
final class MetaApi
{
    public const DEFAULT_VERSION = 'v25.0';

    /** @var list<string> metric names Meta rejected during this run, reported in the sync message */
    public array $skippedMetrics = [];

    public function __construct(
        private readonly HttpClient $http,
        private readonly MetaAuth $auth,
        private readonly string $version = self::DEFAULT_VERSION,
    ) {
    }

    /** @param array<string, string|int> $query @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        return $this->call('GET', $this->url($path) . '?' . http_build_query($query + $this->auth->tokenParams()));
    }

    /** @param array<string, string|int> $params @return array<string, mixed> */
    public function post(string $path, array $params): array
    {
        return $this->call('POST', $this->url($path), http_build_query($params + $this->auth->tokenParams()));
    }

    /**
     * Follows paging.next up to $maxPages pages.
     * @param array<string, string|int> $query @return list<array<string, mixed>>
     */
    public function all(string $path, array $query, int $maxPages = 10): array
    {
        $out = [];
        $data = $this->get($path, $query);
        for ($page = 1; ; $page++) {
            array_push($out, ...($data['data'] ?? []));
            $next = $data['paging']['next'] ?? null;
            if (!is_string($next) || $page >= $maxPages) {
                break;
            }
            $data = $this->call('GET', $next);
        }
        return $out;
    }

    /**
     * Insight values of one object. Returns metric => value (lifetime, total_value or the last value
     * of a series). Missing data is left out, never 0.
     * @param list<string> $metrics @param array<string, string|int> $query
     * @return array<string, float>
     */
    public function insights(string $objectId, array $metrics, array $query = []): array
    {
        try {
            return self::parseInsights($this->get($objectId . '/insights', ['metric' => implode(',', $metrics)] + $query));
        } catch (MetaException $e) {
            if ($e->isNotEnoughData()) {
                return [];
            }
            if (!$e->isInvalidParameter()) {
                throw $e;
            }
            if (count($metrics) === 1) {
                $this->skippedMetrics[] = $metrics[0];
                return [];
            }
        }
        $out = [];
        foreach ($metrics as $metric) {
            $out += $this->insights($objectId, [$metric], $query);
        }
        return $out;
    }

    /**
     * Daily series of page or account insights. @param list<string> $metrics @param array<string, string|int> $query
     * @return array<string, array<string, float>> metric => [date (Europe/Berlin) => value]
     */
    public function dailyInsights(string $objectId, array $metrics, array $query): array
    {
        try {
            return self::parseDaily($this->get($objectId . '/insights', ['metric' => implode(',', $metrics), 'period' => 'day'] + $query));
        } catch (MetaException $e) {
            if ($e->isNotEnoughData()) {
                return [];
            }
            if (!$e->isInvalidParameter()) {
                throw $e;
            }
            if (count($metrics) === 1) {
                $this->skippedMetrics[] = $metrics[0];
                return [];
            }
        }
        $out = [];
        foreach ($metrics as $metric) {
            $out += $this->dailyInsights($objectId, [$metric], $query);
        }
        return $out;
    }

    /** @param array<string, mixed> $data @return array<string, float> */
    public static function parseInsights(array $data): array
    {
        $out = [];
        foreach ($data['data'] ?? [] as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (isset($row['total_value']['value']) && is_numeric($row['total_value']['value'])) {
                $out[$name] = (float) $row['total_value']['value'];
                continue;
            }
            $values = $row['values'] ?? [];
            $last = end($values);
            if (is_array($last) && isset($last['value']) && is_numeric($last['value'])) {
                $out[$name] = (float) $last['value'];
            } elseif (is_array($last) && isset($last['value']) && is_array($last['value'])) {
                // Breakdown objects such as {"like": 3, "love": 1}: the sum is the total.
                $out[$name] = (float) array_sum(array_filter($last['value'], 'is_numeric'));
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $data @return array<string, array<string, float>> */
    public static function parseDaily(array $data): array
    {
        $out = [];
        foreach ($data['data'] ?? [] as $row) {
            $name = (string) ($row['name'] ?? '');
            foreach ($row['values'] ?? [] as $v) {
                if (!isset($v['end_time']) || !is_numeric($v['value'] ?? null)) {
                    continue;
                }
                // end_time marks the end of the day in Pacific time (e.g. 2026-10-08T07:00:00+0000 for 7 Oct).
                $end = new \DateTimeImmutable((string) $v['end_time']);
                $day = $end->setTimezone(new \DateTimeZone('America/Los_Angeles'))->modify('-1 second')->format('Y-m-d');
                $out[$name][$day] = (float) $v['value'];
            }
        }
        return $out;
    }

    private function url(string $path): string
    {
        return 'https://graph.facebook.com/' . $this->version . '/' . ltrim($path, '/');
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $url, ?string $body = null): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        $res = $this->http->request($method, $url, $headers, $body);
        $data = json_decode($res['body'], true);
        if ($res['status'] >= 200 && $res['status'] < 300 && is_array($data) && !isset($data['error'])) {
            return $data;
        }
        $error = is_array($data) ? ($data['error'] ?? []) : [];
        $code = (int) ($error['code'] ?? 0);
        $raw = (string) ($error['message'] ?? '');
        $message = match (true) {
            $code === 190 || $res['status'] === 401 => 'Meta hat die Anmeldung abgelehnt. Bitte unter Automatik „Meta verbinden“ erneut klicken.',
            in_array($code, [4, 17, 32, 613, 80001, 80002], true) => 'Meta-Abfragegrenze erreicht. Der nächste Lauf versucht es wieder.',
            $code === 10 && stripos($raw, 'not enough') !== false => 'Noch zu wenig Daten für diesen Beitrag.',
            in_array($code, [10, 200], true) => "Meta verweigert den Zugriff ({$raw}). Fehlt eine Berechtigung in der Meta-App?",
            default => "Meta-Fehler HTTP {$res['status']}: {$raw}",
        };
        throw new MetaException($message, $code, $res['status'], $raw);
    }
}
