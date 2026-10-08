<?php

declare(strict_types=1);

namespace Cockpit\Sync;

use Cockpit\Clock;
use Cockpit\Connectors\Connector;
use Cockpit\Db;

/** Runs one connector with a per-source lock and logs every run to sync_runs. */
final class SyncRunner
{
    /** @param array<string, Connector> $connectors */
    public function __construct(private readonly Db $db, private readonly array $connectors)
    {
    }

    /** @return array<string, Connector> */
    public function connectors(): array
    {
        return $this->connectors;
    }

    /** @return array{source: string, ok: bool, message: string, items: int, startedAt: string, finishedAt: string} */
    public function run(string $source, string $trigger): array
    {
        $connector = $this->connectors[$source] ?? null;
        if ($connector === null) {
            throw new \InvalidArgumentException("Unbekannte Quelle {$source}.");
        }
        $startedAt = Clock::nowIso();
        if (!$this->lock($source)) {
            return ['source' => $source, 'ok' => false, 'message' => 'Läuft bereits. Bitte kurz warten.', 'items' => 0, 'startedAt' => $startedAt, 'finishedAt' => $startedAt];
        }
        $this->db->run('INSERT INTO sync_runs (source, trigger, started_at) VALUES (?, ?, ?)', [$source, $trigger, $startedAt]);
        $runId = $this->db->lastId();
        $ok = true;
        $items = 0;
        try {
            $outcome = $connector->sync();
            $items = $outcome->items;
            $message = $outcome->message;
        } catch (\Throwable $e) {
            $ok = false;
            $message = $e->getMessage();
            error_log("[cockpit] sync {$source} failed: " . $e::class . ': ' . $e->getMessage());
        } finally {
            $this->unlock($source);
        }
        $finishedAt = Clock::nowIso();
        $this->db->run('UPDATE sync_runs SET finished_at = ?, ok = ?, items = ?, message = ? WHERE id = ?', [$finishedAt, $ok ? 1 : 0, $items, $message, $runId]);
        $this->db->run('DELETE FROM sync_runs WHERE started_at < ?', [Clock::iso(Clock::now()->modify('-90 days'))]);
        return ['source' => $source, 'ok' => $ok, 'message' => $message, 'items' => $items, 'startedAt' => $startedAt, 'finishedAt' => $finishedAt];
    }

    /** Runs every job that is due. @return list<array<string, mixed>> */
    public function runDue(): array
    {
        $results = [];
        foreach (Schedule::ORDER as $source) {
            if (!isset($this->connectors[$source])) {
                continue;
            }
            $last = $this->db->value('SELECT MAX(started_at) FROM sync_runs WHERE source = ?', [$source]);
            if (Schedule::isDue($source, $last === null ? null : (string) $last, Clock::now())) {
                $results[] = $this->run($source, 'cron');
            }
        }
        return $results;
    }

    private function lock(string $source): bool
    {
        return $this->db->tx(function () use ($source): bool {
            $until = $this->db->value('SELECT locked_until FROM sync_locks WHERE source = ?', [$source]);
            if ($until !== null && Clock::parse((string) $until) > Clock::now()) {
                return false;
            }
            $this->db->run(
                'INSERT INTO sync_locks (source, locked_until) VALUES (?, ?) ON CONFLICT (source) DO UPDATE SET locked_until = excluded.locked_until',
                [$source, Clock::iso(Clock::now()->modify('+15 minutes'))],
            );
            return true;
        });
    }

    private function unlock(string $source): void
    {
        $this->db->run('DELETE FROM sync_locks WHERE source = ?', [$source]);
    }
}
