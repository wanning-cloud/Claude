<?php

declare(strict_types=1);

namespace Cockpit\Counting;

use Cockpit\Clock;
use Cockpit\Db;

/**
 * Download counting after IAB Podcast Measurement Technical Guidelines 2.2:
 * - only GET with status 200/206, no bots (HEAD and range probes never reach the threshold),
 * - one download per IP + User-Agent + episode within 24 hours from the first request,
 * - counted once at least one minute of audio was transferred (summed over partial requests).
 *
 * IPs are never stored: the window key is an HMAC with a random daily salt, salts are deleted after
 * two days and windows after 48 hours.
 */
final class IabCounter
{
    /** Fallback when the feed has no length/duration: one minute at 192 kbit/s. */
    public const FALLBACK_BYTES_PER_MINUTE = 1_440_000;

    /** @var array<string, array{id: int, threshold: int}>|null */
    private ?array $episodes = null;
    /** @var array<string, string> */
    private array $salts = [];
    public int $counted = 0;
    public int $skipped = 0;

    public function __construct(private readonly Db $db, private readonly UserAgents $agents)
    {
    }

    /** @return array<string, array{id: int, threshold: int}> */
    private function episodes(): array
    {
        if ($this->episodes === null) {
            $this->episodes = [];
            foreach ($this->db->all('SELECT id, mp3_path, bytes, duration_s FROM episodes') as $row) {
                $this->episodes[(string) $row['mp3_path']] = [
                    'id' => (int) $row['id'],
                    'threshold' => self::threshold($row['bytes'] === null ? null : (int) $row['bytes'], $row['duration_s'] === null ? null : (int) $row['duration_s']),
                ];
            }
        }
        return $this->episodes;
    }

    public static function threshold(?int $bytes, ?int $durationSeconds): int
    {
        if (!$bytes || !$durationSeconds || $durationSeconds < 60) {
            return self::FALLBACK_BYTES_PER_MINUTE;
        }
        return (int) ceil($bytes / ($durationSeconds / 60));
    }

    /** @param array{ip: string, time: string, method: string, path: string, status: int, bytes: int, ua: string} $event */
    public function add(array $event): void
    {
        $episode = $this->episodes()[$event['path']] ?? null;
        if ($episode === null) {
            return;
        }
        if ($event['method'] !== 'GET' || !in_array($event['status'], [200, 206], true) || $this->agents->isBot($event['ua'])) {
            $this->skipped++;
            return;
        }
        $at = new \DateTimeImmutable($event['time']);
        $utc = $at->setTimezone(new \DateTimeZone('UTC'));
        $day = $at->setTimezone(Clock::tz())->format('Y-m-d');
        $material = $event['ip'] . '|' . $event['ua'];
        $keys = [$this->hash($material, $day), $this->hash($material, Clock::addDays($day, -1))];
        $since = $utc->modify('-24 hours')->format('Y-m-d\TH:i:s\Z');

        $window = $this->db->one(
            'SELECT id, bytes, counted, window_start FROM download_windows
             WHERE key_hash IN (?, ?) AND episode_id = ? AND window_start > ?
             ORDER BY window_start DESC LIMIT 1',
            [$keys[0], $keys[1], $episode['id'], $since],
        );
        if ($window === null) {
            $start = $utc->format('Y-m-d\TH:i:s\Z');
            $this->db->run(
                'INSERT INTO download_windows (key_hash, episode_id, window_start, bytes, counted, app) VALUES (?, ?, ?, ?, 0, ?)',
                [$keys[0], $episode['id'], $start, $event['bytes'], $this->agents->app($event['ua'])],
            );
            $window = ['id' => $this->db->lastId(), 'bytes' => $event['bytes'], 'counted' => 0, 'window_start' => $start];
        } else {
            $window['bytes'] = (int) $window['bytes'] + $event['bytes'];
            $this->db->run('UPDATE download_windows SET bytes = ? WHERE id = ?', [$window['bytes'], $window['id']]);
        }

        if (!(int) $window['counted'] && (int) $window['bytes'] >= $episode['threshold']) {
            $this->db->run('UPDATE download_windows SET counted = 1 WHERE id = ?', [$window['id']]);
            $app = (string) $this->db->value('SELECT app FROM download_windows WHERE id = ?', [$window['id']]);
            $date = Clock::dateOf((string) $window['window_start']);
            $this->db->run(
                'INSERT INTO download_daily (episode_id, date, app, downloads) VALUES (?, ?, ?, 1)
                 ON CONFLICT (episode_id, date, app) DO UPDATE SET downloads = downloads + 1',
                [$episode['id'], $date, $app],
            );
            $this->counted++;
        }
    }

    private function hash(string $material, string $day): string
    {
        if (!isset($this->salts[$day])) {
            $salt = $this->db->value('SELECT salt FROM daily_salts WHERE day = ?', [$day]);
            if ($salt === null) {
                $salt = bin2hex(random_bytes(32));
                $this->db->run('INSERT OR IGNORE INTO daily_salts (day, salt) VALUES (?, ?)', [$day, $salt]);
                $salt = $this->db->value('SELECT salt FROM daily_salts WHERE day = ?', [$day]);
            }
            $this->salts[$day] = (string) $salt;
        }
        return hash_hmac('sha256', $material, $this->salts[$day]);
    }

    /** Removes windows older than 48 hours and salts older than two days (relative to now). */
    public function cleanup(): void
    {
        $cutoff = Clock::now()->setTimezone(new \DateTimeZone('UTC'))->modify('-48 hours')->format('Y-m-d\TH:i:s\Z');
        $this->db->run('DELETE FROM download_windows WHERE window_start < ?', [$cutoff]);
        $this->db->run('DELETE FROM daily_salts WHERE day < ?', [Clock::addDays(Clock::today(), -2)]);
        $this->salts = [];
    }
}
