<?php

declare(strict_types=1);

namespace Cockpit\Sync;

use Cockpit\Clock;
use DateTimeImmutable;

/**
 * Plan of the server jobs (master prompt 8.1). One all-inkl cronjob calls /api/cron?job=due every
 * 15 or 30 minutes; it runs every job that is due. Daily jobs run on the first tick after their time.
 */
final class Schedule
{
    /** @var array<string, array{every?: int, at?: string}> minutes or HH:MM */
    public const PLAN = [
        'feed' => ['every' => 60],
        'youtube_comments' => ['every' => 30],
        'youtube_stats' => ['at' => '06:00'],
        'apple_reviews' => ['at' => '06:10'],
        'downloads' => ['every' => 60],
        'meta_comments' => ['every' => 15],
        'instagram_stories' => ['every' => 120],
        'instagram' => ['every' => 360],
        'facebook' => ['every' => 360],
        'backup' => ['at' => '03:00'],
    ];

    /** Order in which a "due" tick runs the jobs: master data first. */
    public const ORDER = ['backup', 'feed', 'youtube_comments', 'meta_comments', 'instagram_stories', 'downloads', 'youtube_stats', 'apple_reviews', 'instagram', 'facebook'];

    public static function isDue(string $job, ?string $lastStart, DateTimeImmutable $now): bool
    {
        $rule = self::PLAN[$job] ?? null;
        if ($rule === null) {
            return false;
        }
        if ($lastStart === null) {
            return true;
        }
        $last = Clock::parse($lastStart);
        if (isset($rule['every'])) {
            // 2 minutes slack so a 30 minute cron does not skip every other run.
            return $now >= $last->modify('+' . ($rule['every'] - 2) . ' minutes');
        }
        $slot = self::lastSlot($rule['at'], $now);
        return $now >= $slot && $last < $slot;
    }

    public static function next(string $job, ?string $lastStart, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $rule = self::PLAN[$job] ?? null;
        if ($rule === null) {
            return null;
        }
        if (isset($rule['every'])) {
            if ($lastStart === null) {
                return $now;
            }
            $next = Clock::parse($lastStart)->modify('+' . $rule['every'] . ' minutes');
            return $next < $now ? $now : $next;
        }
        $slot = self::lastSlot($rule['at'], $now);
        if ($lastStart === null || Clock::parse($lastStart) < $slot) {
            return $now;
        }
        return $slot->modify('+1 day');
    }

    private static function lastSlot(string $at, DateTimeImmutable $now): DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $at));
        $slot = $now->setTime($h, $m);
        return $slot > $now ? $slot->modify('-1 day') : $slot;
    }
}
