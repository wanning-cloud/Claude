<?php

declare(strict_types=1);

namespace Cockpit\Connectors;

use Cockpit\Counting\IabCounter;
use Cockpit\Counting\LogParser;
use Cockpit\Db;

/**
 * Download measurement variant A: reads the all-inkl access logs incrementally (plain or .gz),
 * counts after IAB 2.2 and keeps no IP addresses. Progress per file lives in settings "logs.state".
 */
final class DownloadsLogsConnector implements Connector
{
    public function __construct(
        private readonly Db $db,
        private readonly IabCounter $counter,
        private readonly ?string $logDir,
        private readonly string $glob = 'access*log*',
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
        usort($files, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b) ?: strcmp($b, $a));
        /** @var array<string, array{size: int, offset: int, mtime: int}> $state */
        $state = json_decode($this->db->setting('logs.state') ?? '{}', true) ?: [];
        // High-water mark: events older than the newest processed event are skipped, so a rotated
        // or re-compressed file can never be counted twice.
        $hwm = $this->db->setting('logs.hwm');
        $newHwm = $hwm;
        $lines = 0;
        $unparsed = 0;

        foreach ($files as $file) {
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
                    $lines++;
                    if (++$batch >= 2000) {
                        $this->db->pdo->exec('COMMIT');
                        $this->db->pdo->exec('BEGIN IMMEDIATE');
                        $batch = 0;
                    }
                }
                $state[$name] = ['size' => $size, 'offset' => $gz ? $size : $offset, 'mtime' => $mtime];
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
        $this->counter->cleanup();
        $message = "{$lines} Log-Zeilen gelesen, {$this->counter->counted} Downloads gezählt.";
        if ($unparsed > 0) {
            $message .= " {$unparsed} Zeilen im unbekannten Format übersprungen.";
        }
        return new SyncOutcome($this->counter->counted, $message);
    }
}
