<?php

declare(strict_types=1);

namespace Cockpit;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** All calendar logic runs in Europe/Berlin. Tests can freeze "now". */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    public static function tz(): DateTimeZone
    {
        static $tz = null;
        return $tz ??= new DateTimeZone('Europe/Berlin');
    }

    public static function freeze(?string $iso): void
    {
        self::$frozen = $iso === null ? null : new DateTimeImmutable($iso, self::tz());
    }

    public static function now(): DateTimeImmutable
    {
        return (self::$frozen ?? new DateTimeImmutable('now'))->setTimezone(self::tz());
    }

    public static function nowIso(): string
    {
        return self::now()->format(DateTimeInterface::ATOM);
    }

    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    public static function parse(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTimezone(self::tz());
    }

    public static function iso(DateTimeInterface $dt): string
    {
        return DateTimeImmutable::createFromInterface($dt)->setTimezone(self::tz())->format(DateTimeInterface::ATOM);
    }

    /** Berlin calendar date of a timestamp. */
    public static function dateOf(string $iso): string
    {
        return self::parse($iso)->format('Y-m-d');
    }

    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date . ' 12:00:00', self::tz()))->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    public static function daysBetween(string $from, string $to): int
    {
        $a = new DateTimeImmutable($from . ' 12:00:00', self::tz());
        $b = new DateTimeImmutable($to . ' 12:00:00', self::tz());
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    /** @return list<string> */
    public static function dates(string $from, string $to): array
    {
        $out = [];
        for ($d = $from; $d <= $to; $d = self::addDays($d, 1)) {
            $out[] = $d;
        }
        return $out;
    }

    public static function isDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y);
    }
}
