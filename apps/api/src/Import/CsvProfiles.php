<?php

declare(strict_types=1);

namespace Cockpit\Import;

/**
 * Known CSV exports, recognised by their header row (formats checked against real exports from 08.10.2026):
 *
 * - spotify_performance: Spotify for Creators › Analytics › "Leistung" export.
 *   "Datum,Wiedergaben,Hörer*innen", one row per day, show level. Dates like 9.9.2026.
 * - spotify_trends_first7: Spotify for Creators › "Trends" export (plays in the first 7 days per episode).
 *   "Name der Folge,Veröffentlichungsdatum,Leistung,Wiedergaben". Empty cells mean "no data yet", not 0.
 * - amazon_overview: Amazon Music for Podcasters › podcast table download.
 *   "Musikverlag,ID des Podcasts,Titel,Starts,Plays,Hörer:innen,Begeisterte Hörer,Follower", one row for the
 *   show, period from the file name ("Überblick_01.10.2026-07.10.2026.csv") or entered by hand.
 *
 * Unknown CSVs fall back to the Spotify column mapping stored in the cockpit.
 */
final class CsvProfiles
{
    public const LABELS = [
        'spotify_performance' => 'Spotify: Leistung pro Tag',
        'spotify_trends_first7' => 'Spotify: Wiedergaben in den ersten 7 Tagen je Folge',
        'amazon_overview' => 'Amazon Music: Überblick für einen Zeitraum',
    ];

    /** @param list<string> $headers */
    public static function detect(array $headers): ?string
    {
        $h = array_map(static fn (string $x): string => mb_strtolower(trim($x)), $headers);
        $has = static fn (string ...$names): bool => array_diff(array_map('mb_strtolower', $names), $h) === [];
        return match (true) {
            $has('ID des Podcasts', 'Starts', 'Plays') => 'amazon_overview',
            $has('Name der Folge', 'Wiedergaben') => 'spotify_trends_first7',
            $has('Datum', 'Wiedergaben') => 'spotify_performance',
            default => null,
        };
    }

    public static function source(string $profile): string
    {
        return str_starts_with($profile, 'amazon') ? 'amazon' : 'spotify';
    }

    /**
     * @param list<array<string, string>> $rows
     * @return array{rows: list<array{guid: null, title: string|null, date: string|null, metrics: array<string, int>, total: bool}>, followers: int|null, errors: list<string>}
     */
    public static function apply(string $profile, array $rows): array
    {
        $out = [];
        $errors = [];
        $followers = null;
        $get = static function (array $row, string ...$names): ?string {
            foreach ($row as $key => $value) {
                if (in_array(mb_strtolower(trim((string) $key)), array_map('mb_strtolower', $names), true)) {
                    return trim((string) $value);
                }
            }
            return null;
        };
        $num = static function (?string $value, int $line, string $column) use (&$errors): ?int {
            if ($value === null || $value === '' || $value === '–' || $value === '-') {
                return null;
            }
            $n = SpotifyCsv::number($value);
            if ($n === null) {
                $errors[] = "Zeile {$line}: „{$value}“ in „{$column}“ ist keine Zahl.";
            }
            return $n;
        };
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            if ($profile === 'spotify_performance') {
                $date = SpotifyCsv::date((string) $get($row, 'Datum'));
                if ($date === null) {
                    $errors[] = "Zeile {$line}: Datum „{$get($row, 'Datum')}“ nicht lesbar.";
                    continue;
                }
                $metrics = array_filter([
                    'plays' => $num($get($row, 'Wiedergaben'), $line, 'Wiedergaben'),
                    'listeners' => $num($get($row, 'Hörer*innen', 'Hörer:innen', 'Hörer'), $line, 'Hörer*innen'),
                    'streams' => $num($get($row, 'Streams'), $line, 'Streams'),
                ], static fn ($v): bool => $v !== null);
                $out[] = ['guid' => null, 'title' => null, 'date' => $date, 'metrics' => $metrics, 'total' => false];
            } elseif ($profile === 'spotify_trends_first7') {
                $plays = $num($get($row, 'Wiedergaben'), $line, 'Wiedergaben');
                $title = (string) $get($row, 'Name der Folge');
                if ($plays === null || $title === '') {
                    continue; // Spotify has no value yet for this episode
                }
                $out[] = ['guid' => null, 'title' => $title, 'date' => null, 'metrics' => ['plays_first7d' => $plays], 'total' => true];
            } elseif ($profile === 'amazon_overview') {
                $metrics = array_filter([
                    'plays' => $num($get($row, 'Plays'), $line, 'Plays'),
                    'starts' => $num($get($row, 'Starts'), $line, 'Starts'),
                    'listeners' => $num($get($row, 'Hörer:innen', 'Hörer*innen', 'Hörer'), $line, 'Hörer:innen'),
                    'engaged' => $num($get($row, 'Begeisterte Hörer', 'Begeisterte Hörer:innen'), $line, 'Begeisterte Hörer'),
                ], static fn ($v): bool => $v !== null);
                $followers = $num($get($row, 'Follower', 'Follower:innen'), $line, 'Follower') ?? $followers;
                $out[] = ['guid' => null, 'title' => null, 'date' => null, 'metrics' => $metrics, 'total' => false];
            }
        }
        return ['rows' => $out, 'followers' => $followers, 'errors' => $errors];
    }

    /** "Überblick_01.10.2026-07.10.2026.csv" → period. @return array{from: string, to: string}|null */
    public static function periodFromFileName(string $name): ?array
    {
        if (!preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})\s*[-–_]\s*(\d{1,2})\.(\d{1,2})\.(\d{4})/', $name, $m)) {
            return null;
        }
        $from = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        $to = sprintf('%04d-%02d-%02d', $m[6], $m[5], $m[4]);
        return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) && checkdate((int) $m[5], (int) $m[4], (int) $m[6]) ? ['from' => $from, 'to' => $to] : null;
    }
}
