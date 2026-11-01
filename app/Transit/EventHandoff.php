<?php

namespace App\Transit;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Events' "Open in Travel" hands an event to Plan a trip by its id and the
 * occurrence's local start (`/travel?to=<venue>&context=<id>&date=&arrive=`,
 * as the mock), never by coordinates. The occurrence is found as Events lists it (visible
 * events only, Event::occurringBetween); a hidden, unknown, pointless or
 * finished one gives nothing, and the page opens empty.
 */
final class EventHandoff
{
    /** An occurrence with no end listed counts as over this long after it starts. */
    private const ASSUMED_LENGTH_HOURS = 3;

    /**
     * The occurrence starting on `$date` (Y-m-d) at `$time` (H:i), Cologne's
     * time: the link Events builds.
     *
     * @return array{id: int, start: string, title: string, place: string, area: ?string, lat: float, lng: float, start_at: string, start_time: string, end_at: ?string, end_time: ?string}|null
     */
    public function forLocal(mixed $id, mixed $date, mixed $time, CarbonImmutable $now): ?array
    {
        if (! is_string($date) || ! is_string($time)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            return null;
        }

        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$time}", 'Europe/Berlin');
        } catch (Throwable) {
            return null;
        }

        return $start === false || $start === null ? null : $this->for($id, $start->toIso8601String(), $now);
    }

    /**
     * @return array{id: int, start: string, title: string, place: string, area: ?string, lat: float, lng: float, start_at: string, start_time: string, end_at: ?string, end_time: ?string}|null
     */
    public function for(mixed $id, mixed $start, CarbonImmutable $now): ?array
    {
        if (! is_numeric($id) || ! is_string($start) || strlen($start) > 40) {
            return null;
        }
        try {
            $at = CarbonImmutable::parse($start)->setTimezone('Europe/Berlin');
        } catch (Throwable) {
            return null;
        }

        $occurrence = Event::occurringBetween($at->subMinute(), $at->addMinute(), [(int) $id])
            ->first(fn (array $o) => $o['starts_at']->getTimestamp() === $at->getTimestamp());
        if ($occurrence === null) {
            return null;
        }
        $ends = $occurrence['ends_at'];
        if (($ends ?? $occurrence['starts_at']->addHours(self::ASSUMED_LENGTH_HOURS))->lessThan($now)) {
            return null;
        }

        /** @var Event $event */
        $event = $occurrence['event'];
        $venue = $event->venue;
        $lat = $venue?->lat ?? $event->lat;
        $lng = $venue?->lng ?? $event->lng;
        if ($lat === null || $lng === null) {
            return null;
        }
        $tz = 'Europe/Berlin';

        return [
            'id' => $event->id,
            'start' => $occurrence['starts_at']->toIso8601String(),
            'title' => (string) ($event->title_en ?: $event->title),
            'place' => (string) ($venue?->name ?: $event->location_name ?: $event->title),
            'area' => $venue?->veedel,
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'start_at' => $occurrence['starts_at']->setTimezone($tz)->toIso8601String(),
            'start_time' => $occurrence['starts_at']->setTimezone($tz)->format('H:i'),
            'end_at' => $ends?->setTimezone($tz)->toIso8601String(),
            'end_time' => $ends?->setTimezone($tz)->format('H:i'),
        ];
    }
}
