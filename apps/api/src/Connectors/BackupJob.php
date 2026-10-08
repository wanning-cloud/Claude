<?php

declare(strict_types=1);

namespace Cockpit\Connectors;

use Cockpit\Clock;
use Cockpit\Db;

/** Daily consistent copy of the SQLite database (VACUUM INTO), keeps 14 days. */
final class BackupJob implements Connector
{
    public const KEEP_DAYS = 14;

    public function __construct(private readonly Db $db, private readonly string $dir)
    {
    }

    public function id(): string
    {
        return 'backup';
    }

    public function label(): string
    {
        return 'Datenbank-Sicherung';
    }

    public function sync(): SyncOutcome
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true)) {
            throw new \RuntimeException('Sicherungsordner lässt sich nicht anlegen. BACKUP_DIR prüfen.');
        }
        $target = $this->dir . '/cockpit-' . Clock::now()->format('Y-m-d_His') . '.sqlite';
        $this->db->pdo->exec('VACUUM INTO ' . $this->db->pdo->quote($target));
        $files = glob($this->dir . '/cockpit-*.sqlite') ?: [];
        sort($files);
        $removed = 0;
        $cutoff = Clock::addDays(Clock::today(), -self::KEEP_DAYS);
        foreach ($files as $file) {
            if (preg_match('/cockpit-(\d{4}-\d{2}-\d{2})_/', $file, $m) && $m[1] < $cutoff) {
                unlink($file);
                $removed++;
            }
        }
        $size = round(filesize($target) / 1024 / 1024, 1);
        return new SyncOutcome(1, "Sicherung {$size} MB erstellt, {$removed} alte gelöscht.");
    }
}
