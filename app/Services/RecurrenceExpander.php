<?php

namespace App\Services;

use App\Models\Event;
use Carbon\CarbonImmutable;

/**
 * Expands an event's RRULE into concrete occurrence start times within
 * a window — on the fly, no materialized table (documented choice: the
 * windows are ≤ a week and the catalogue is hundreds of events, so
 * expansion is microseconds; a nightly materializer would only add a
 * staleness failure mode).
 *
 * Supported subset (what community events actually use):
 *   FREQ=DAILY|WEEKLY|MONTHLY · INTERVAL=n · BYDAY=MO,…,SU · UNTIL=…
 * BYDAY applies to WEEKLY; MONTHLY repeats on the DTSTART day-of-month.
 * DTSTART = events.starts_at (carries the time of day);
 * events.recurrence_until caps the series (UNTIL inside the rule too).
 */
class RecurrenceExpander
{
    private const WEEKDAYS = [
        'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4,
        'FR' => 5, 'SA' => 6, 'SU' => 7,
    ];

    /**
     * @return list<CarbonImmutable> occurrence start times inside [from, to]
     */
    public function expand(Event $event, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = CarbonImmutable::parse($event->starts_at);

        if (! $event->recurrence) {
            return $start->between($from, $to) ? [$start] : [];
        }

        $rule = $this->parse($event->recurrence);
        if ($rule === null) {
            return $start->between($from, $to) ? [$start] : [];
        }

        $seriesEnd = collect([
            $rule['until'],
            // endOfDay like the in-rule UNTIL — a date-valued (midnight)
            // recurrence_until must not drop its own final day.
            $event->recurrence_until ? CarbonImmutable::parse($event->recurrence_until)->endOfDay() : null,
        ])->filter()->min() ?? $to;

        $windowEnd = $to->min($seriesEnd);
        if ($windowEnd->lessThan($from)) {
            return [];
        }

        return match ($rule['freq']) {
            'DAILY' => $this->stepped($start, $from, $windowEnd, $rule['interval'], 'days'),
            'MONTHLY' => $this->stepped($start, $from, $windowEnd, $rule['interval'], 'months'),
            'WEEKLY' => $this->weekly($start, $from, $windowEnd, $rule),
            default => [],
        };
    }

    /**
     * @return array{freq: string, interval: int, byday: list<int>, until: ?CarbonImmutable}|null
     */
    private function parse(string $rrule): ?array
    {
        $parts = [];
        foreach (explode(';', strtoupper(trim($rrule))) as $piece) {
            [$key, $value] = array_pad(explode('=', $piece, 2), 2, '');
            $parts[$key] = $value;
        }

        if (! in_array($parts['FREQ'] ?? '', ['DAILY', 'WEEKLY', 'MONTHLY'], true)) {
            return null;
        }

        $byday = [];
        foreach (array_filter(explode(',', $parts['BYDAY'] ?? '')) as $day) {
            if (isset(self::WEEKDAYS[$day])) {
                $byday[] = self::WEEKDAYS[$day];
            }
        }

        return [
            'freq' => $parts['FREQ'],
            'interval' => max(1, (int) ($parts['INTERVAL'] ?? 1)),
            'byday' => $byday,
            'until' => isset($parts['UNTIL'])
                ? CarbonImmutable::parse($parts['UNTIL'])->endOfDay()
                : null,
        ];
    }

    /**
     * Occurrence n is always derived FROM DTSTART (not by walking a
     * cursor) so month-end overflow stays deterministic regardless of
     * where the window starts: Jan 31 + n months (no overflow) is the
     * same series whether you ask for March or for June.
     *
     * @return list<CarbonImmutable>
     */
    private function stepped(CarbonImmutable $start, CarbonImmutable $from, CarbonImmutable $to, int $interval, string $unit): array
    {
        $occurrenceAt = fn (int $n): CarbonImmutable => $unit === 'days'
            ? $start->addDays($n * $interval)
            : $start->addMonthsNoOverflow($n * $interval);

        // Jump close to the window instead of iterating from DTSTART
        $n = 0;
        if ($start->lessThan($from)) {
            $perStep = $unit === 'days' ? $interval : $interval * 28; // months: conservative
            $n = intdiv(max(0, (int) $start->diffInDays($from)), max(1, $perStep));
        }

        $occurrences = [];
        while (($occurrence = $occurrenceAt($n))->lessThanOrEqualTo($to)) {
            if ($occurrence->greaterThanOrEqualTo($from)) {
                $occurrences[] = $occurrence;
            }
            $n++;
        }

        return $occurrences;
    }

    /**
     * @param  array{interval: int, byday: list<int>}  $rule
     * @return list<CarbonImmutable>
     */
    private function weekly(CarbonImmutable $start, CarbonImmutable $from, CarbonImmutable $to, array $rule): array
    {
        $days = $rule['byday'] !== [] ? $rule['byday'] : [$start->isoWeekday()];
        $timeOfDay = $start->format('H:i:s');

        $occurrences = [];

        // Walk week-blocks anchored on DTSTART's week (respects INTERVAL)
        $week = $start->startOfWeek();
        if ($week->lessThan($from->startOfWeek())) {
            $weeksAhead = intdiv((int) $week->diffInWeeks($from->startOfWeek()), $rule['interval']) * $rule['interval'];
            $week = $week->addWeeks($weeksAhead);
        }

        while ($week->lessThanOrEqualTo($to)) {
            foreach ($days as $isoDay) {
                $occurrence = $week->addDays($isoDay - 1)->setTimeFromTimeString($timeOfDay);
                if ($occurrence->between($from, $to) && $occurrence->greaterThanOrEqualTo($start)) {
                    $occurrences[] = $occurrence;
                }
            }
            $week = $week->addWeeks($rule['interval']);
        }

        sort($occurrences);

        return array_values($occurrences);
    }
}
