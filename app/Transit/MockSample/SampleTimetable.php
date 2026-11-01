<?php

namespace App\Transit\MockSample;

use App\Services\TimetableService;
use App\Support\MockClock;
use App\Support\MockScenario;
use Carbon\CarbonImmutable;

/**
 * The mock's departure boards (SampleBoards) while the local mock clock is
 * on, so Travel's Nearby and Today's board compare against the mock's
 * captures at the sample moment: departure feeds have nothing for a past
 * day. A stop the mock has a capture for answers with it, counted from the
 * mock clock; any other stop answers with nothing, never the live board.
 * In the mock's "live times paused" situation (MockScenario `stale`) the
 * board is the last one read, from the capture's own time. No network call
 * is made. Every request without the mock clock reads the real board.
 * Bound only in local (TravelServiceProvider).
 */
final class SampleTimetable extends TimetableService
{
    /** A boarding area belongs to a captured stop within this distance of it. */
    private const NEAR_DEGREES = 0.004;

    public function pointBoard(array $point): ?array
    {
        if (! MockClock::active()) {
            return parent::pointBoard($point);
        }

        $station = SampleBoards::STATIONS[$point['name']] ?? null;
        if ($station === null
            || abs((float) $point['lat'] - $station['lat']) > self::NEAR_DEGREES
            || abs((float) $point['lng'] - $station['lng']) > self::NEAR_DEGREES) {
            return null;
        }

        $paused = MockScenario::is('stale');
        // Paused, the last reading's times count from when it was read.
        $now = ($paused ? CarbonImmutable::parse($station['observed_at']) : CarbonImmutable::now())
            ->setTimezone('Europe/Berlin')->startOfMinute();
        $basis = $paused ? 'stale' : $station['time_basis'];
        $departures = [];
        foreach ($station['rows'] as $i => [$line, $destination, $mode, $minutes, $delay, $disrupted, $via]) {
            $services = [];
            foreach ($minutes as $j => $minute) {
                $at = $now->addMinutes($minute);
                $services[] = [
                    'id' => "sample:{$line}:{$i}:{$j}",
                    'departure_at' => $at->toIso8601String(),
                    'scheduled_at' => $at->subMinutes($delay)->toIso8601String(),
                    'delay' => $delay,
                    'cancelled' => false,
                    'time_basis' => $basis,
                ];
            }
            $departures[] = [
                'line' => $line,
                'destination' => $destination,
                'type' => $mode,
                'via' => $via,
                'direction' => null,
                'minutes' => $minutes,
                'services' => $services,
                'delay' => $delay,
                'cancelled' => false,
                'disrupted' => $disrupted,
            ];
        }

        return [
            'source' => $station['source'],
            'time_basis' => $basis,
            'is_stale' => $paused,
            // Paused: the last reading is the capture's own.
            'fetched_at' => $paused ? $station['observed_at'] : $now->toIso8601String(),
            'generated_at' => $now->toIso8601String(),
            'departures' => $departures,
        ];
    }
}
