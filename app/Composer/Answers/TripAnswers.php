<?php

namespace App\Composer\Answers;

use App\Day\DayProjection;
use App\Models\ActiveTrip;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Composer's answers about a trip, as the approved Composer gives them (composer-studio/
 * app.js, the appointment's trip and `tripAnswer`), from Travel's own data:
 *
 *  - before you set off, when to leave for today's appointment, by Today's rule and
 *    routes (DayProjection's next trip, App\Day\NextTrip): the same trip Today and Your day
 *    show;
 *  - once you're on your way (Travel's "I'm on my way", ActiveTrip), where you get off,
 *    when you'll be there and what's next, at the trip's own times.
 *
 * Every time is the trip's own; nothing is estimated, and a time is said to be live only
 * when its journey is. The message carries the journey, and the page draws the way with
 * Travel's own rows (components/travel/trip/trip-model.ts, way.tsx), so a trip reads the
 * same wherever it shows. Steps here follow trip-model.ts `tripSteps`.
 */
final class TripAnswers
{
    private const TIMEZONE = 'Europe/Berlin';

    public function __construct(private DayProjection $day) {}

    /** The trip under way, when it's to the plan starting at this time (the appointment). */
    public function onWayTo(User $user, ?string $start): bool
    {
        $target = ActiveTrip::currentFor($user)?->target;

        return $start !== null && is_array($target)
            && $this->clock($target['start_at'] ?? null) === $start;
    }

    /**
     * "When should I leave for my 14:00?" and "Is my tram on time?" before setting off: the
     * next trip Today plans, when it's to that appointment.
     *
     * @param  array<string, mixed>  $request
     * @return array{text: string, extra: array<string, mixed>}|null
     */
    public function appointment(User $user, array $request, CarbonImmutable $now, ?string $start): ?array
    {
        $now = $now->setTimezone(self::TIMEZONE);
        $trip = $this->day->for($user, $now->toDateString(), null, $now)['next_trip'] ?? null;
        $target = is_array($trip) ? $trip['target'] : null;
        if ($target === null || $start === null || $target['kind'] !== 'appointment' || $target['start_time'] !== $start) {
            return null;
        }

        $which = "your {$target['title']} at {$start}";
        if ($trip['status'] === 'unavailable') {
            $text = match ($trip['reason']) {
                'location_unknown' => "I don’t know where you’re starting from, so I can’t time the trip to {$which}. Travel can plan it from where you are.",
                'destination_unknown' => ucfirst($which).' has no place recorded, so I can’t plan the trip there.',
                'routing_unavailable', 'no_route', 'no_connection_in_time' => "I can’t find a connection to {$which} right now. Travel can look again.",
                default => null,
            };

            return $text === null ? null : $this->reply($text, null, null, [['label' => 'Open Travel', 'href' => '/timetable']]);
        }

        $legs = $trip['journey']['legs'] ?? [];
        $ride = collect($legs)->first(fn (array $leg): bool => $this->rides($leg));
        $city = $trip['city'] ?? null;
        $vehicle = $ride === null ? null : $this->vehicle($ride['mode']);
        $unnamed = ($request['unnamed'] ?? false) ? "That’s for {$which}. " : '';
        $official = (bool) $target['official'];
        $needs = ($official ? 'official appointments need ' : 'plans need ').$trip['margin_min'];

        if (($request['status'] ?? false) && $ride !== null) {
            $title = "{$vehicle} {$ride['line']} at {$ride['depart_time']} from {$this->stop($ride['from']['name'], $city)}.";
            $text = $this->running($ride).' It’s the '.mb_strtolower($vehicle)." for {$which}.";
        } else {
            $title = $trip['leave_in_min'] <= 0 ? "Leave now — it’s {$now->format('H:i')}." : "Leave by {$trip['leave_time']}.";
            $skipped = $trip['skipped'];
            $text = $unnamed.match ($trip['status']) {
                'fits' => "You’ll be there at {$trip['arrive_time']} with {$trip['spare_min']} minutes to spare."
                    .($trip['reason'] === 'earlier_for_margin' && $skipped !== null
                        ? ' I skipped the '.($skipped['ride']['time'] ?? $skipped['leave_time']).($skipped['in_plan'] ? ' in your plan' : '')
                            .": it leaves only {$skipped['spare_min']}, and {$needs}."
                        : ''),
                'tight' => "You’ll be there at {$trip['arrive_time']} with only {$trip['spare_min']} minutes to spare; {$needs}.",
                default => "The soonest connection gets you there at {$trip['arrive_time']}, {$trip['late_by_min']} minutes after {$start}.",
            };
        }

        return $this->reply($text, $title, [
            'journey' => $trip['journey'],
            'for' => $this->for($target['title'], $target['location'], $target['start_time'], $target['start_at'], $official, 'appointment'),
            'city' => $city,
            // Travel's Plan a trip to it, by its start, as Today links it.
            'link' => 'plan',
            'label' => 'Open in Travel',
            'basis' => $trip['time_basis'],
        ]);
    }

    /**
     * On your way: what was asked first ("Where do I get off?": `stop`, `arrive`, `next`,
     * `status`, or `leave` for "When should I leave?"), then where you are on the trip, at
     * its own times.
     *
     * @return array{text: string, extra: array<string, mixed>}|null
     */
    public function onTheWay(User $user, string $ask, CarbonImmutable $now): ?array
    {
        $active = ActiveTrip::currentFor($user);
        $journey = $active?->journey;
        if (! is_array($journey) || ($journey['legs'] ?? []) === []) {
            return null;
        }
        $now = $now->setTimezone(self::TIMEZONE);
        $target = is_array($active->target) ? $active->target : [];
        $city = config('geocoding.search_default.city');
        $title = (string) ($target['title'] ?? $active->destination['name'] ?? 'Your journey');
        $start = $this->clock($target['start_at'] ?? null);
        $kind = $target['kind'] ?? null;
        $steps = $this->steps($journey, $title, $start, $kind, $city);

        // Where you are: the last step whose time has come (the timetable's, as Travel
        // follows it without your location).
        $at = 0;
        foreach ($steps as $index => $step) {
            if ($step['at'] <= $now->getTimestamp()) {
                $at = $index;
            }
        }
        $board = $this->find($steps, fn (array $s): bool => $s['kind'] === 'transit');
        $off = $board === null ? null : $this->find($steps, fn (array $s, int $i): bool => $i > $board && $s['kind'] === 'alight');
        $here = $steps[$at];
        $stage = match (true) {
            $here['kind'] === 'arrive' => 'arrived',
            $board === null || $at <= $board => 'before',
            $off === null || $at <= $off => 'riding',
            default => 'after',
        };

        $boarding = $board === null ? null : $steps[$board];
        $leg = $boarding['leg'] ?? null;
        $vehicle = $leg === null ? 'Line' : $this->vehicle($leg['mode']);
        $ride = trim(mb_strtolower($vehicle).' '.($leg['line'] ?? ''));
        $Ride = ucfirst($ride);
        $towards = $leg !== null && $leg['headsign'] ? ' towards '.$this->stop($leg['headsign'], $city) : '';
        $arrive = $journey['arrive_time'] ?? null;
        $spare = $start !== null && $arrive !== null && $this->minutes($start) > $this->minutes($arrive)
            ? ', '.($this->minutes($start) - $this->minutes($arrive))." minutes before {$start}" : '';
        $there = $arrive !== null && $stage !== 'arrived' ? "You’ll be there at {$arrive}{$spare}." : '';
        $leaving = $off === null ? null : $steps[$off];
        $offAt = $leaving === null ? '' : "Get off at {$leaving['place']} at {$leaving['time']}.";
        $where = match ($stage) {
            'before' => $boarding === null ? '' : ($at === $board
                ? "Your {$ride} leaves {$boarding['place']} at {$boarding['time']}."
                : "{$here['title']}: your {$ride} leaves at {$boarding['time']}."),
            'riding' => $offAt,
            'after' => "{$here['title']}.",
            default => $here['detail'] !== '' ? "{$here['detail']}." : '',
        };
        $join = fn (string ...$parts): string => implode(' ', array_filter($parts));

        if ($ask === 'status' && $boarding !== null) {
            $heading = match ($stage) {
                'before' => "{$Ride} at {$boarding['time']} from {$boarding['place']}.",
                'riding' => "You’re on {$ride}{$towards}.",
                default => 'You’re off the '.mb_strtolower($vehicle).'.',
            };
            $text = in_array($stage, ['before', 'riding'], true) ? $join($this->running($leg), $stage === 'riding' ? $offAt : '') : $join($where, $there);
        } elseif ($ask === 'stop' && $leaving !== null) {
            $heading = in_array($stage, ['before', 'riding'], true) ? $offAt : "You got off at {$leaving['place']}.";
            $text = $join($stage === 'before' ? "Your {$ride} leaves at {$boarding['time']}." : '', $stage === 'after' ? $where : '', $there);
        } elseif ($ask === 'arrive' && $arrive !== null) {
            $heading = $stage === 'arrived' ? "You’re there: {$title}." : $there;
            $text = $where;
        } else {
            // "When should I leave?" or "What's next?": you're already going.
            $heading = match ($stage) {
                'riding' => "You’re on {$ride}{$towards}.",
                'after' => 'You’re nearly there.',
                'arrived' => "You’re there: {$title}.",
                default => 'You’re on your way.',
            };
            $text = $join($where, $there);
        }

        return $this->reply($text, $heading, [
            'journey' => $journey,
            'for' => $this->for($title, $target['label'] ?? null, $start, $target['start_at'] ?? null, (bool) ($target['official'] ?? false), $kind),
            'city' => $city,
            // The way from where you are, at this moment.
            'at' => CarbonImmutable::createFromTimestamp($steps[$at]['at'], self::TIMEZONE)->toIso8601String(),
            'link' => 'trip',
            'label' => 'Back to your trip',
            'basis' => collect($journey['legs'])->contains(fn (array $l): bool => $this->rides($l) && ($l['realtime'] ?? false)) ? 'live' : 'scheduled',
        ]);
    }

    /**
     * The trip's steps, each at its time and place: trip-model.ts `tripSteps`, in its words.
     *
     * @param  array<string, mixed>  $journey
     * @return list<array{kind: string, time: string, at: int, title: string, detail: string, place: string, leg?: array<string, mixed>}>
     */
    private function steps(array $journey, string $title, ?string $start, ?string $kind, ?string $city): array
    {
        $steps = [];
        $legs = array_values($journey['legs']);
        foreach ($legs as $index => $leg) {
            $last = $index === count($legs) - 1;
            $at = CarbonImmutable::parse($leg['depart_at'])->getTimestamp();
            if (! $this->rides($leg)) {
                $to = $last ? ($kind === 'appointment' ? 'your appointment' : $title) : $this->stop($leg['to']['name'], $city);
                $bike = $leg['mode'] === 'bike';
                $steps[] = ['kind' => $bike ? 'bike' : 'walk', 'time' => $leg['depart_time'], 'at' => $at,
                    'title' => ($bike ? 'Cycle' : 'Walk')." to {$to}", 'detail' => "{$leg['duration_min']} min ".($bike ? 'by bike' : 'on foot'),
                    'place' => $this->stop($leg['to']['name'], $city)];

                continue;
            }
            $steps[] = ['kind' => 'transit', 'time' => $leg['depart_time'], 'at' => $at,
                'title' => "{$this->vehicle($leg['mode'])} {$leg['line']} to {$this->stop($leg['to']['name'], $city)}",
                'detail' => 'From '.$this->stop($leg['from']['name'], $city), 'place' => $this->stop($leg['from']['name'], $city), 'leg' => $leg];
            $steps[] = ['kind' => 'alight', 'time' => $leg['arrive_time'], 'at' => CarbonImmutable::parse($leg['arrive_at'])->getTimestamp(),
                'title' => 'Get off at '.$this->stop($leg['to']['name'], $city), 'detail' => $last ? 'You’re there' : 'Your stop',
                'place' => $this->stop($leg['to']['name'], $city), 'leg' => $leg];
        }
        $steps[] = ['kind' => 'arrive', 'time' => $journey['arrive_time'], 'at' => CarbonImmutable::parse($journey['arrive_at'])->getTimestamp(),
            'title' => $title, 'detail' => $start !== null ? "Starts at {$start}" : 'You’re there', 'place' => $title];

        return $steps;
    }

    /** Whether a ride's on time, as far as its times say: live only when they are. */
    private function running(?array $leg): string
    {
        if ($leg === null || ! ($leg['realtime'] ?? false)) {
            return 'There are no live times for it, so I can’t tell if it’s running on time.';
        }
        $delay = (int) ($leg['delay_min'] ?? 0);

        return $delay > 0 ? "Live times have it {$delay} minutes late." : 'Live times have it on time.';
    }

    /**
     * The plan the trip leads to, as Travel's trip names it (trip-model.ts `TripFor`).
     *
     * @return array<string, mixed>
     */
    private function for(string $place, ?string $label, ?string $start, ?string $startAt, bool $official, ?string $kind): array
    {
        return ['place' => $place, 'label' => $label, 'start' => $start, 'startAt' => $startAt, 'official' => $official,
            'kind' => in_array($kind, ['appointment', 'plan', 'event'], true) ? $kind : null];
    }

    /** @param  array<string, mixed>  $leg */
    private function rides(array $leg): bool
    {
        return ! in_array($leg['mode'] ?? 'walk', ['walk', 'bike'], true);
    }

    /** Travel's word for a leg's vehicle (line-look.ts `vehicleWord`, `vehicleOf`). */
    private function vehicle(?string $mode): string
    {
        return match ($mode) {
            'bus' => 'Bus',
            'rail', 'train' => 'Train',
            default => 'Tram',
        };
    }

    /** "Köln Neumarkt" → "Neumarkt", as the stop signs say (stop-name.ts). */
    private function stop(string $name, ?string $city): string
    {
        return $city !== null && str_starts_with($name, "{$city} ") ? mb_substr($name, mb_strlen($city) + 1) : $name;
    }

    private function clock(?string $iso): ?string
    {
        return $iso === null ? null : CarbonImmutable::parse($iso)->setTimezone(self::TIMEZONE)->format('H:i');
    }

    private function minutes(string $clock): int
    {
        return (int) substr($clock, 0, 2) * 60 + (int) substr($clock, 3, 2);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function find(array $steps, callable $match): ?int
    {
        foreach ($steps as $index => $step) {
            if ($match($step, $index)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $trip
     * @param  list<array<string, string>>  $suggestions
     * @return array{text: string, extra: array<string, mixed>}
     */
    private function reply(string $text, ?string $title, ?array $trip, array $suggestions = []): array
    {
        return ['text' => $text, 'extra' => array_filter([
            'kind' => $trip === null ? 'text' : 'trip',
            'title' => $title,
            'trip' => $trip,
            'suggestions' => $suggestions ?: null,
        ], fn ($value) => $value !== null)];
    }
}
