<?php

namespace App\Day;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * When the person is busy in their own calendar, today and tomorrow (the calendar canvas, owner 17
 * Nov 2026: "titles stay on phone, today and tomorrow only, build it"). The phone reads the
 * calendar and sends only times, with each look at Today, in the `Expadu-Busy` header (a header,
 * so no access log keeps it): "2026-11-18T09:00/2026-11-18T12:30,…" in Cologne's time. Used for
 * that answer and never kept: no title, place or person ever reaches the server.
 */
final class Busy
{
    public const HEADER = 'Expadu-Busy';

    /** More than this many is a broken or hostile header: the rest are ignored. */
    private const MOST = 40;

    /**
     * @return list<array{0: int, 1: int}> start and end, as timestamps, soonest first
     */
    public static function from(Request $request, CarbonImmutable $now): array
    {
        $header = $request->header(self::HEADER);

        return is_string($header) ? self::parse($header, $now) : [];
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    public static function parse(string $header, CarbonImmutable $now): array
    {
        $now = $now->setTimezone('Europe/Berlin');
        $from = $now->startOfDay()->getTimestamp();
        $to = $now->addDay()->endOfDay()->getTimestamp();
        $spans = [];
        foreach (array_slice(explode(',', $header), 0, self::MOST) as $piece) {
            if (preg_match('/^\s*(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})\/(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})\s*$/', $piece, $m) !== 1) {
                continue;
            }
            $start = rescue(fn () => CarbonImmutable::createFromFormat('Y-m-d\TH:i', $m[1], 'Europe/Berlin'), null, false);
            $end = rescue(fn () => CarbonImmutable::createFromFormat('Y-m-d\TH:i', $m[2], 'Europe/Berlin'), null, false);
            if ($start === null || $end === null || $end <= $start) {
                continue;
            }
            // Today and tomorrow only.
            $s = max($start->getTimestamp(), $from);
            $e = min($end->getTimestamp(), $to);
            if ($e > $s) {
                $spans[] = [$s, $e];
            }
        }
        usort($spans, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return $spans;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $busy
     */
    public static function clashes(array $busy, int $start, ?int $end): bool
    {
        $end ??= $start + 3600;
        foreach ($busy as [$s, $e]) {
            if ($start < $e && $end > $s) {
                return true;
            }
        }

        return false;
    }

    /**
     * The busy stretch that's on now or comes next today, run together where one meets the next
     * (within a quarter of an hour): [start, end], or null.
     *
     * @param  list<array{0: int, 1: int}>  $busy
     * @return array{0: int, 1: int}|null
     */
    public static function nextStretch(array $busy, CarbonImmutable $now): ?array
    {
        $at = $now->getTimestamp();
        $midnight = $now->setTimezone('Europe/Berlin')->endOfDay()->getTimestamp();
        $stretch = null;
        foreach ($busy as [$s, $e]) {
            if ($e <= $at || $s > $midnight) {
                continue;
            }
            if ($stretch === null) {
                $stretch = [$s, $e];
            } elseif ($s <= $stretch[1] + 15 * 60) {
                $stretch[1] = max($stretch[1], $e);
            } else {
                break;
            }
        }

        return $stretch;
    }
}
