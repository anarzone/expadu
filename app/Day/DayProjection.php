<?php

namespace App\Day;

use App\Composer\PlanCatalogue;
use App\Day\Contracts\RecordedAppointments;
use App\Models\ComposerWorkspace;
use App\Models\User;
use App\Models\UserPlace;
use App\Services\UserLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\DeferProp;
use Inertia\Inertia;

/**
 * One person's day, worked out on the server once for Today, Travel's My day
 * and Composer's Your day: the clock, their recorded appointments and
 * confirmed plans (with Mark as done), the trip to the next thing, the trips
 * between plans and the trip they're on.
 *
 * Everything is read for the signed-in account only. Days are calendar days
 * in Europe/Berlin, so the 25-hour Sunday when summer time ends (25 Oct 2026)
 * and the 23-hour one in spring are whole days, and a trip that leaves
 * before midnight for something just after it is still found.
 */
class DayProjection
{
    public const TIMEZONE = 'Europe/Berlin';

    /** How far past midnight the next trip may look from tonight. */
    private const OVERNIGHT_HOURS = 3;

    /** With a trip ahead, re-read at least this often (live times move). */
    private const TRIP_REFRESH_MINUTES = 5;

    public function __construct(
        private readonly RecordedAppointments $appointments,
        private readonly PlanCatalogue $catalogue,
        private readonly NextTrip $nextTrip,
        private readonly PlanLinks $links,
        private readonly UserLocationService $locations,
    ) {}

    /** Today's projection as a deferred Inertia prop, for the pages that show it. */
    public static function deferred(Request $request): DeferProp
    {
        return Inertia::defer(
            fn () => app(self::class)->for($request->user(), CarbonImmutable::now(self::TIMEZONE)->format('Y-m-d'), $request),
            'day',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, string $date, ?Request $request = null, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE);
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::TIMEZONE);
        $next = $day->addDay();
        $today = $now->format('Y-m-d');
        $relation = $date === $today ? 'today' : ($date < $today ? 'past' : 'future');

        $workspace = ComposerWorkspace::query()->where('user_id', $user->id)->first();
        $plans = $workspace?->state['plans'] ?? [];
        $items = $this->items($user, $plans, $day, $next);

        $nextTrip = null;
        if ($relation === 'today') {
            $overnight = $this->items($user, $plans, $next, $next->addDay());
            $target = $this->target([...$items, ...$overnight], $now);
            if ($target !== null) {
                $nextTrip = $this->nextTrip->for($target, $this->origin($user, $request, $items, $target, $now), $now);
            }
        }

        $home = $user->places()->where('category', 'home')->orderBy('sort_order')->first(['id', 'name', 'lat', 'lng']);

        return [
            'date' => $date,
            'timezone' => self::TIMEZONE,
            'now' => $now->toIso8601String(),
            'utc_offset' => $now->format('P'),
            'relation' => $relation,
            'day_start' => $day->toIso8601String(),
            'day_end' => $next->toIso8601String(),
            'hours_in_day' => intdiv($next->getTimestamp() - $day->getTimestamp(), 3600),
            // The workspace revision a Mark as done command must send.
            'revision' => $workspace?->revision ?? 0,
            'items' => array_map(fn (DayItem $item) => $item->toArray($now), $items),
            'links' => $relation === 'past' ? [] : $this->links->between($items, $now),
            'next_trip' => $nextTrip,
            'active_trip' => $user->activeTrip?->toShared(),
            'home' => $home === null ? null : $this->home($home),
            'refresh_at' => $this->refreshAt($items, $nextTrip, $now, $relation),
        ];
    }

    /**
     * Recorded appointments and confirmed plans starting in [$from, $to).
     *
     * @param  array<string, array<string, mixed>>  $plans
     * @return list<DayItem>
     */
    private function items(User $user, array $plans, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $items = [];
        foreach ($this->appointments->between($user, $from, $to) as $appointment) {
            $items[$appointment->id] = $appointment;
        }

        $plan = $plans[$from->format('Y-m-d')] ?? null;
        foreach ($plan === null ? [] : ($this->catalogue->project($plan)['slots'] ?? []) as $slot) {
            $item = $this->fromSlot($slot);
            if ($item === null || $item->startAt->lessThan($from) || ! $item->startAt->lessThan($to)) {
                continue;
            }
            // A Paperwork appointment Composer planned around is the same appointment.
            $items[$item->id] = isset($items[$item->id]) ? $items[$item->id] : $item;
        }

        $items = array_values($items);
        usort($items, fn (DayItem $a, DayItem $b) => [$a->startAt, $a->id] <=> [$b->startAt, $b->id]);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $slot
     */
    private function fromSlot(array $slot): ?DayItem
    {
        if (! is_string($slot['id'] ?? null) || ! is_string($slot['start_at'] ?? null)) {
            return null;
        }
        $type = (string) ($slot['type'] ?? '');
        $category = isset($slot['category']) ? (string) $slot['category'] : null;
        $location = is_string($slot['location'] ?? null) && $slot['location'] !== '' ? $slot['location'] : null;
        $lat = is_numeric($slot['lat'] ?? null) ? (float) $slot['lat'] : null;
        $lng = is_numeric($slot['lng'] ?? null) ? (float) $slot['lng'] : null;

        return new DayItem(
            id: $slot['id'],
            kind: match (true) {
                $type === 'appointment' || $category === 'appointment' => 'appointment',
                $type === 'event' => 'event',
                default => 'plan',
            },
            source: 'workspace',
            title: (string) ($slot['name'] ?? 'Your plan'),
            startAt: CarbonImmutable::parse($slot['start_at'])->setTimezone(self::TIMEZONE),
            endAt: is_string($slot['end_at'] ?? null) ? CarbonImmutable::parse($slot['end_at'])->setTimezone(self::TIMEZONE) : null,
            official: $type === 'appointment',
            locationLabel: $location ?? (is_string($slot['veedel'] ?? null) ? $slot['veedel'] : null),
            lat: $lat,
            lng: $lng,
            completedAt: is_string($slot['completed_at'] ?? null) ? CarbonImmutable::parse($slot['completed_at']) : null,
            fixed: in_array($type, ['event', 'appointment'], true),
            completable: true,
            available: ($slot['recommendation_status'] ?? 'available') !== 'unavailable',
            category: $category,
            subtitle: is_string($slot['subtitle'] ?? null) ? $slot['subtitle'] : null,
        );
    }

    /**
     * The next thing to travel to: the first one still ahead that has a place
     * (a recorded appointment always counts, even before its place is known,
     * so the trip says what's missing rather than disappearing).
     *
     * @param  list<DayItem>  $items
     */
    private function target(array $items, CarbonImmutable $now): ?DayItem
    {
        $horizon = $now->setTime(0, 0)->addDay()->addHours(self::OVERNIGHT_HOURS);
        foreach ($items as $item) {
            if ($item->completed() || ! $item->available || ! $item->startAt->greaterThan($now)) {
                continue;
            }
            if ($item->startAt->greaterThanOrEqualTo($horizon) && $item->startAt->format('Y-m-d') !== $now->format('Y-m-d')) {
                continue;
            }
            if ($item->kind === 'appointment' || $item->routable() || $item->locationLabel !== null) {
                return $item;
            }
        }

        return null;
    }

    /**
     * From the plan you're at until it ends, else from where you are now.
     * Never from a guessed home or the city centre.
     *
     * @param  list<DayItem>  $items
     */
    private function origin(User $user, ?Request $request, array $items, DayItem $target, CarbonImmutable $now): ?TripOrigin
    {
        foreach (array_reverse($items) as $item) {
            if ($item->id !== $target->id && ! $item->completed() && $item->state($now) === 'ongoing'
                && $item->routable() && ! $item->endAt->greaterThan($target->startAt)) {
                return new TripOrigin($item->point(), $item->locationLabel ?? $item->title, 'plan', $item->endAt, $item->id);
            }
        }

        $location = $this->locations->context($user, $request);
        $point = $location->toGeoPoint();

        return $point === null ? null : new TripOrigin($point, $location->label ?? 'Your location', $location->source->value);
    }

    /**
     * @return array<string, mixed>
     */
    private function home(UserPlace $home): array
    {
        return ['id' => $home->id, 'name' => $home->name, 'lat' => $home->lat, 'lng' => $home->lng];
    }

    /**
     * When the projection next changes on its own: an item starting or ending,
     * the time to leave, or midnight. The client re-reads then (and when the
     * tab comes back), so an elapsed appointment or a new day never lingers.
     *
     * @param  list<DayItem>  $items
     * @param  array<string, mixed>|null  $nextTrip
     */
    private function refreshAt(array $items, ?array $nextTrip, CarbonImmutable $now, string $relation): ?string
    {
        if ($relation === 'past') {
            return null;
        }
        $moments = [$now->setTime(0, 0)->addDay()];
        foreach ($items as $item) {
            foreach ([$item->startAt, $item->endAt] as $moment) {
                if ($moment !== null && $moment->greaterThan($now)) {
                    $moments[] = $moment;
                }
            }
        }
        if (($nextTrip['leave_at'] ?? null) !== null) {
            $moments[] = CarbonImmutable::parse($nextTrip['leave_at']);
            $moments[] = $now->addMinutes(self::TRIP_REFRESH_MINUTES);
        }
        $moments = array_filter($moments, fn (CarbonImmutable $m) => $m->greaterThan($now));

        return $moments === [] ? null : min($moments)->setTimezone(self::TIMEZONE)->toIso8601String();
    }
}
