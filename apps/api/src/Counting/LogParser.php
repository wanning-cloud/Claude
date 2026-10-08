<?php

declare(strict_types=1);

namespace Cockpit\Counting;

/**
 * Parses Apache combined log lines as written by all-inkl (an optional vhost field in front is allowed).
 * Example: 1.2.3.0 - - [12/Oct/2026:08:31:02 +0200] "GET /media/folge-07.mp3 HTTP/2.0" 206 1572864 "-" "AppleCoreMedia/1.0"
 */
final class LogParser
{
    private const PATTERN = '/^(?:\S+\s+)?(?P<ip>[0-9a-fA-F:.x*]+)\s+\S+\s+\S+\s+\[(?P<time>[^\]]+)\]\s+"(?P<method>[A-Z]+)\s+(?P<path>\S+)(?:\s+[^"]*)?"\s+(?P<status>\d{3})\s+(?P<bytes>\d+|-)(?:\s+"(?P<referer>(?:[^"\\\\]|\\\\.)*)"\s+"(?P<ua>(?:[^"\\\\]|\\\\.)*)")?/';

    /** @return array{ip: string, time: string, method: string, path: string, status: int, bytes: int, ua: string}|null */
    public static function parse(string $line): ?array
    {
        if (!preg_match(self::PATTERN, $line, $m)) {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $m['time']);
        if ($time === false) {
            return null;
        }
        return [
            'ip' => $m['ip'],
            'time' => $time->format(DATE_ATOM),
            'method' => $m['method'],
            'path' => rawurldecode((string) parse_url($m['path'], PHP_URL_PATH)),
            'status' => (int) $m['status'],
            'bytes' => $m['bytes'] === '-' ? 0 : (int) $m['bytes'],
            'ua' => stripcslashes($m['ua'] ?? ''),
        ];
    }
}
