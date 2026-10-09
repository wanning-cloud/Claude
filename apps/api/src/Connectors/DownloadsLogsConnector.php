<?php

declare(strict_types=1);

namespace Cockpit\Connectors;

use Cockpit\Counting\IabCounter;
use Cockpit\Counting\LogParser;
use Cockpit\Counting\SocialVisits;
use Cockpit\Db;

/**
 * Download measurement variant A: reads the all-inkl access logs incrementally (plain or .gz),
 * counts after IAB 2.2 and keeps no IP addresses. Progress per file lives in settings "logs.state".
 *
 * all-inkl writes one gzip file per day, e.g. /logs/access_log_monteur-podcast_de_2026-10-07.gz,
 * kept for 190 days. Files are processed in the order of the date in their name. The first run has
 * up to 190 days to catch up; each run stops after a time budget and the next run continues.
 */
final class DownloadsLogsConnector implements Connector
{
    public function __construct(
        private readonly Db $db,
        private readonly IabCounter $counter,
        private readonly ?string $logDir,
        private readonly string $glob = 'access_log*',
        private readonly int $budgetSeconds = 200,
        private readonly ?SocialVisits $socialVisits = null,
    ) {
    }

    public function id(): string
    {
        return 'downloads';
    }

    public function label(): string
    {
        return 'Downloads (Server-Logs)';
    }

    public function sync(): SyncOutcome
    {
        if ($this->logDir === null || !is_dir($this->logDir)) {
            throw new \RuntimeException('Zugriffs-Logs nicht gefunden. ACCESS_LOG_DIR in der analytics.env prüfen.');
        }
        $files = glob(rtrim($this->logDir, '/') . '/' . $this->glob) ?: [];
        if ($files === []) {
            throw new \RuntimeException('Im Log-Ordner liegen keine Zugriffs-Logs. Log-Stufe im KAS prüfen.');
        }
        usort($files, static fn (string $a, string $b): int => self::sortKey($a) <=> self::sortKey($b));
        $started = microtime(true);
        $remaining = 0;
        $read = 0;
        /** @var array<string, array{size: int, offset: int, mtime: int}> $state */
        $state = json_decode($this->db->setting('logs.state') ?? '{}', true) ?: [];
        // High-water mark: events older than the newest processed event are skipped, so a rotated
        // or re-compressed file can never be counted twice.
        $hwm = $this->db->setting('logs.hwm');
        $newHwm = $hwm;
        $lines = 0;
        $unparsed = 0;

        foreach ($files as $file) {
            // Every run reads at least one file, so a slow host still makes progress.
            if ($read > 0 && microtime(true) - $started > $this->budgetSeconds) {
                $remaining++;
                continue;
            }
            $gz = str_ends_with($file, '.gz');
            // Plain files are tracked by inode so a rotated file keeps its offset.
            $name = $gz ? basename($file) : 'ino:' . fileinode($file);
            $size = (int) filesize($file);
            $mtime = (int) filemtime($file);
            $known = $state[$name] ?? null;
            if ($gz && $known !== null && $known['size'] === $size && $known['mtime'] === $mtime) {
                continue;
            }
            $offset = (!$gz && $known !== null && $known['size'] <= $size) ? $known['offset'] : 0;
            if (!$gz && $offset === $size) {
                continue;
            }
            $handle = $gz ? gzopen($file, 'rb') : fopen($file, 'rb');
            if ($handle === false) {
                throw new \RuntimeException("Log-Datei {$name} lässt sich nicht lesen.");
            }
            if (!$gz && $offset > 0) {
                fseek($handle, $offset);
            }
            $this->db->pdo->exec('BEGIN IMMEDIATE');
            try {
                $batch = 0;
                while (($line = $gz ? gzgets($handle) : fgets($handle)) !== false) {
                    if (!$gz && !str_ends_with($line, "\n")) {
                        break; // incomplete last line, read again next time
                    }
                    $offset += strlen($line);
                    $event = LogParser::parse($line);
                    if ($event === null) {
                        $unparsed++;
                        continue;
                    }
                    $utc = gmdate('Y-m-d\\TH:i:s\\Z', strtotime($event['time']));
                    if ($hwm !== null && $utc < $hwm) {
                        continue;
                    }
                    if ($newHwm === null || $utc > $newHwm) {
                        $newHwm = $utc;
                    }
                    $this->counter->add($event);
                    $this->socialVisits?->add($event);
                    $lines++;
                    if (++$batch >= 2000) {
                        $this->db->pdo->exec('COMMIT');
                        $this->db->pdo->exec('BEGIN IMMEDIATE');
                        $batch = 0;
                    }
                }
                $state[$name] = ['size' => $size, 'offset' => $gz ? $size : $offset, 'mtime' => $mtime];
                $read++;
                $this->db->setSetting('logs.state', (string) json_encode($state));
                if ($newHwm !== null) {
                    $this->db->setSetting('logs.hwm', $newHwm);
                }
                $this->db->pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                $this->db->pdo->exec('ROLLBACK');
                throw $e;
            } finally {
                $gz ? gzclose($handle) : fclose($handle);
            }
        }
        $this->counter->cleanup($newHwm);
        $message = "{$lines} Log-Zeilen gelesen, {$this->counter->counted} Downloads gezählt.";
        if ($remaining > 0) {
            $message .= " Noch {$remaining} Log-Dateien offen, geht beim nächsten Lauf weiter.";
        }
        if ($unparsed > 0) {
            $message .= " {$unparsed} Zeilen im unbekannten Format übersprungen.";
        }
        return new SyncOutcome($this->counter->counted, $message);
    }

    /** Date in the file name (access_log_…_2026-10-07.gz) first, then modification time. */
    private static function sortKey(string $file): string
    {
        $date = preg_match('/(\d{4}-\d{2}-\d{2})/', basename($file), $m) ? $m[1] : '9999-99-99';
        // A plain file without date is the one being written right now: always last.
        return $date . '|' . str_pad((string) filemtime($file), 12, '0', STR_PAD_LEFT) . '|' . basename($file);
    }
}
