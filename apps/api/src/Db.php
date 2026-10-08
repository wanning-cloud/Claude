<?php

declare(strict_types=1);

namespace Cockpit;

use PDO;

final class Db
{
    public readonly PDO $pdo;

    public function __construct(string $path)
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 10000');
        if ($path !== ':memory:') {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        }
    }

    /** Applies migrations/NNN_*.sql that have not run yet. */
    public function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
        $done = $this->pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec((string) file_get_contents($file));
                $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)');
                $stmt->execute([$name, Clock::nowIso()]);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }
    }

    /** @param array<int|string, mixed> $params @return list<array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @param array<int|string, mixed> $params @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<int|string, mixed> $params */
    public function run(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /** @template T @param callable(): T $fn @return T */
    public function tx(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    public function setting(string $key): ?string
    {
        $value = $this->value('SELECT value FROM settings WHERE key = ?', [$key]);
        return $value === null ? null : (string) $value;
    }

    public function setSetting(string $key, string $value): void
    {
        $this->run('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value', [$key, $value]);
    }

    public function audit(string $actor, string $action, ?string $target = null, mixed $detail = null): void
    {
        $this->run(
            'INSERT INTO audit_log (at, actor, action, target, detail) VALUES (?, ?, ?, ?, ?)',
            [Clock::nowIso(), $actor, $action, $target, $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE)],
        );
    }
}
