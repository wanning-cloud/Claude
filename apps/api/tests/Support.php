<?php

declare(strict_types=1);

namespace Cockpit\Tests;

use Cockpit\Clock;
use Cockpit\Db;
use Cockpit\HttpClient;

/** TEST DATA ONLY. Helpers for an in-memory database with sample episodes. */
final class Support
{
    public static function db(): Db
    {
        $db = new Db(':memory:');
        $db->migrate();
        return $db;
    }

    /** Two sample episodes: 60 minutes, 86,400,000 bytes → 1,440,000 bytes per minute. */
    public static function seedEpisodes(Db $db): void
    {
        foreach ([[1, 'Folge 01: Warum Monteure?', '2026-09-01T06:00:00+02:00'], [2, 'Folge 02: Der richtige Standort', '2026-09-05T06:00:00+02:00']] as [$n, $title, $pub]) {
            $db->run(
                'INSERT INTO episodes (guid, number, title, published_at, mp3_url, mp3_path, bytes, duration_s, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                ["test-guid-{$n}", $n, $title, $pub, "https://monteur-podcast.de/media/folge-0{$n}.mp3", "/media/folge-0{$n}.mp3", 86_400_000, 3600, Clock::nowIso()],
            );
        }
    }

    public static function logLine(string $ip, string $time, string $method, string $path, int $status, int $bytes, string $ua): string
    {
        return sprintf('%s - - [%s] "%s %s HTTP/1.1" %d %d "-" "%s"', $ip, $time, $method, $path, $status, $bytes, $ua);
    }
}

/** Replays canned responses keyed by "METHOD url-prefix" and records requests. */
final class FakeHttp implements HttpClient
{
    /** @var list<array{method: string, url: string, body: string|null}> */
    public array $requests = [];

    /** @param array<string, array{status: int, body: string}|callable> $routes */
    public function __construct(public array $routes = [])
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
        foreach ($this->routes as $prefix => $response) {
            [$m, $u] = explode(' ', $prefix, 2);
            if ($m === $method && str_starts_with($url, $u)) {
                $response = is_callable($response) ? $response($url, $body) : $response;
                return $response + ['headers' => []];
            }
        }
        return ['status' => 404, 'body' => '{}', 'headers' => []];
    }
}
