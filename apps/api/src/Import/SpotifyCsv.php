<?php

declare(strict_types=1);

namespace Cockpit\Import;

/**
 * Spotify for Creators CSV export. Column names are never hard-coded: Markus maps them once in the
 * cockpit (settings "spotify.mapping"), the mapping is reused for every later import.
 * Mapping: {"date": "<column>|null", "episode": "<column>|null", "metrics": {"plays": "<column>", ...}}
 */
final class SpotifyCsv
{
    /** @return array{headers: list<string>, rows: list<array<string, string>>} */
    public static function read(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\R/', trim($content)) ?: [];
        if ($lines === [] || trim($lines[0]) === '') {
            throw new \RuntimeException('Die CSV-Datei ist leer.');
        }
        $first = $lines[0];
        $delimiter = ',';
        foreach ([';', "\t"] as $candidate) {
            if (substr_count($first, $candidate) > substr_count($first, $delimiter)) {
                $delimiter = $candidate;
            }
        }
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, implode("\n", $lines));
        rewind($handle);
        $headers = array_map('trim', fgetcsv($handle, null, $delimiter, '"', '') ?: []);
        $rows = [];
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            if ($row === [null] || count(array_filter($row, static fn ($v): bool => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $rows[] = array_combine($headers, array_pad(array_slice(array_map('trim', array_map('strval', $row)), 0, count($headers)), count($headers), ''));
        }
        fclose($handle);
        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param array{date?: string|null, episode?: string|null, metrics: array<string, string>} $mapping
     * @return list<array{date: string|null, episode: string|null, metrics: array<string, int>}>
     */
    public static function apply(array $headers, array $rows, array $mapping): array
    {
        $needed = array_filter([$mapping['date'] ?? null, $mapping['episode'] ?? null, ...array_values($mapping['metrics'])]);
        foreach ($needed as $column) {
            if (!in_array($column, $headers, true)) {
                throw new \RuntimeException("Spalte „{$column}“ fehlt in der CSV. Hat Spotify das Format geändert? Zuordnung unter Automatik › Import prüfen.");
            }
        }
        if ($mapping['metrics'] === []) {
            throw new \RuntimeException('Für den Spotify-Import ist noch keine Spalte als Kennzahl zugeordnet.');
        }
        $out = [];
        foreach ($rows as $i => $row) {
            $date = null;
            if (!empty($mapping['date'])) {
                $date = self::date($row[$mapping['date']]);
                if ($date === null) {
                    throw new \RuntimeException('Zeile ' . ($i + 2) . ": Datum „{$row[$mapping['date']]}“ nicht lesbar.");
                }
            }
            $metrics = [];
            foreach ($mapping['metrics'] as $metric => $column) {
                $value = self::number($row[$column]);
                if ($value === null) {
                    throw new \RuntimeException('Zeile ' . ($i + 2) . ": Wert „{$row[$column]}“ in „{$column}“ ist keine Zahl.");
                }
                $metrics[$metric] = $value;
            }
            $out[] = [
                'date' => $date,
                'episode' => empty($mapping['episode']) ? null : ($row[$mapping['episode']] ?: null),
                'metrics' => $metrics,
            ];
        }
        return $out;
    }

    public static function date(string $value): ?string
    {
        $value = trim($value);
        foreach (['Y-m-d', 'd.m.Y', 'j.n.Y', 'm/d/Y', 'Y/m/d', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s'] as $format) {
            $d = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($d !== false && $d->format($format) === $value) {
                return $d->format('Y-m-d');
            }
        }
        return null;
    }

    public static function number(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || $value === '-') {
            return 0;
        }
        $clean = str_replace(["\u{00a0}", ' ', '.', ','], '', $value);
        return ctype_digit($clean) ? (int) $clean : null;
    }
}
