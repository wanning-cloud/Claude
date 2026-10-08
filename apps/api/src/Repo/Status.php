<?php

declare(strict_types=1);

namespace Cockpit\Repo;

use Cockpit\Clock;
use Cockpit\Db;
use Cockpit\Sync\Schedule;

/** Data stamps ("Stand … · Quelle …"), source states for the Automatik page and warnings. */
final class Status
{
    public const STALE_DAYS = 8;

    public const SERVER_SOURCES = [
        'feed' => 'Feed (Folgen)',
        'youtube_comments' => 'YouTube-Kommentare',
        'youtube_stats' => 'YouTube-Zahlen',
        'apple_reviews' => 'Apple-Bewertungen',
        'downloads' => 'Downloads (Server-Logs)',
        'backup' => 'Datenbank-Sicherung',
    ];

    /** Routine sources of stage 1 (Spotify) and stage 2 (Apple, Amazon). */
    public const ROUTINE_SOURCES = ['spotify' => 'Spotify (Routine)', 'apple' => 'Apple Podcasts (Routine)', 'amazon' => 'Amazon Music (Routine)'];

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array{at: string|null, via: string|null, missing?: string} */
    public function stamp(string $platform): array
    {
        return match ($platform) {
            'youtube' => $this->serverStamp('youtube_stats', 'API', 'YouTube ist noch nicht verbunden.'),
            'downloads' => $this->serverStamp('downloads', 'Server', 'Die Download-Messung läuft noch nicht. Erst Zugriffs-Logs prüfen (Stufe 0).'),
            'website' => ['at' => null, 'via' => null, 'missing' => 'Der Website-Player wird in Stufe 2 angebunden.'],
            default => $this->routineStamp($platform),
        };
    }

    /** @return array{at: string|null, via: string|null, missing?: string} */
    private function serverStamp(string $source, string $via, string $never): array
    {
        $at = $this->lastSuccess($source);
        if ($at === null) {
            return ['at' => null, 'via' => null, 'missing' => $never];
        }
        $stamp = ['at' => $at, 'via' => $via];
        if ($this->isStale($at)) {
            $stamp['missing'] = 'Zuletzt am ' . Clock::parse($at)->format('d.m.Y') . ' geholt.';
        }
        return $stamp;
    }

    /** @return array{at: string|null, via: string|null, missing?: string} */
    private function routineStamp(string $platform): array
    {
        $at = $this->db->value("SELECT MAX(finished_at) FROM routine_runs WHERE source = ? AND kind = 'metrics' AND ok = 1", [$platform]);
        if ($at === null) {
            $hint = $platform === 'spotify'
                ? 'Spotify-Daten kommen mit dem nächsten Routine-Lauf (' . $this->nextRoutineLabel() . ').'
                : 'Kommt in Stufe 2 über die Routine.';
            return ['at' => null, 'via' => null, 'missing' => $hint];
        }
        $stamp = ['at' => (string) $at, 'via' => 'Routine'];
        if ($this->isStale((string) $at)) {
            $stamp['missing'] = 'Zuletzt am ' . Clock::parse((string) $at)->format('d.m.Y') . ' geholt.';
        }
        return $stamp;
    }

    public function nextRoutineLabel(): string
    {
        $now = Clock::now();
        $next = $now->modify('monday this week')->setTime(8, 20);
        if ($next <= $now) {
            $next = $next->modify('+1 week');
        }
        $days = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
        return $days[(int) $next->format('w')] . ', ' . $next->format('d.m.') . ', 08:20';
    }

    public function lastSuccess(string $source): ?string
    {
        $at = $this->db->value('SELECT MAX(finished_at) FROM sync_runs WHERE source = ? AND ok = 1', [$source]);
        return $at === null ? null : (string) $at;
    }

    private function isStale(string $at): bool
    {
        return Clock::parse($at) < Clock::now()->modify('-' . self::STALE_DAYS . ' days');
    }

    /** @return list<array<string, mixed>> */
    public function sources(array $enabled): array
    {
        $out = [];
        $now = Clock::now();
        foreach (self::SERVER_SOURCES as $id => $label) {
            $last = $this->db->one('SELECT started_at, finished_at, ok, message FROM sync_runs WHERE source = ? ORDER BY started_at DESC LIMIT 1', [$id]);
            $success = $this->lastSuccess($id);
            $next = in_array($id, $enabled, true) ? Schedule::next($id, $last['started_at'] ?? null, $now) : null;
            $out[] = [
                'id' => $id,
                'label' => $label,
                'kind' => 'server',
                'lastRun' => $last === null ? null : [
                    'startedAt' => $last['started_at'],
                    'finishedAt' => $last['finished_at'],
                    'ok' => (bool) $last['ok'],
                    'message' => $last['message'],
                ],
                'lastSuccessAt' => $success,
                'nextRun' => $next === null ? null : Clock::iso($next),
                'stale' => $success !== null && $this->isStale($success),
                'canRunNow' => in_array($id, $enabled, true) && $id !== 'backup',
            ];
        }
        foreach (self::ROUTINE_SOURCES as $id => $label) {
            $last = $this->db->one('SELECT started_at, finished_at, ok, message FROM routine_runs WHERE source = ? ORDER BY finished_at DESC LIMIT 1', [$id]);
            $success = $this->db->value('SELECT MAX(finished_at) FROM routine_runs WHERE source = ? AND ok = 1', [$id]);
            $out[] = [
                'id' => 'routine:' . $id,
                'label' => $label,
                'kind' => 'routine',
                'lastRun' => $last === null ? null : [
                    'startedAt' => $last['started_at'],
                    'finishedAt' => $last['finished_at'],
                    'ok' => (bool) $last['ok'],
                    'message' => $last['message'],
                ],
                'lastSuccessAt' => $success,
                'nextRun' => null,
                'stale' => $success !== null && $this->isStale((string) $success),
                'canRunNow' => false,
            ];
        }
        return $out;
    }

    /** Warnings for the overview: failed last runs and sources older than 8 days. @return list<array{level: string, text: string}> */
    public function warnings(array $enabled): array
    {
        $out = [];
        foreach ($this->sources($enabled) as $source) {
            if ($source['lastRun'] !== null && !$source['lastRun']['ok']) {
                $out[] = ['level' => 'error', 'text' => "{$source['label']}: {$source['lastRun']['message']}"];
            } elseif ($source['stale']) {
                $out[] = ['level' => 'warn', 'text' => "{$source['label']}: zuletzt am " . Clock::parse((string) $source['lastSuccessAt'])->format('d.m.Y') . ' aktualisiert.'];
            }
        }
        $pending = (int) $this->db->value("SELECT COUNT(*) FROM episode_aliases WHERE status = 'pending'");
        if ($pending > 0) {
            $out[] = ['level' => 'warn', 'text' => ($pending === 1 ? 'Eine Folge' : "{$pending} Folgen") . ' aus Portal-Daten ' . ($pending === 1 ? 'wartet' : 'warten') . ' auf deine Zuordnung (Automatik › Zuordnung von Folgen).'];
        }
        return $out;
    }
}
