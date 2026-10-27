<?php

namespace App\Composer\Actions;

use App\Day\DayItem;
use App\Day\DayProjection;
use App\Day\NextTrip;
use App\Day\PlannedTrips;
use App\Day\TripOrigin;
use App\Enums\TransportMode;
use App\Http\Requests\Concerns\StoredJourney;
use App\Models\User;
use App\Services\LocationContext;
use App\Transit\DepartureTime;
use App\Transit\FavouriteStops;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Travel's own saves, as Travel makes them: a ★ favourite stop (FavouriteStops), how they
 * travel and whether they have a Deutschlandticket (as the From control and the fare line
 * keep them), and the way to a plan on their day with its leave reminder (PlannedTrips, as
 * Plan a trip's Add to plan). A plan's name and a Paperwork appointment's stay out of what
 * the AI is told: its card says them.
 */
final class TravelActions implements ComposerAction
{
    private const LINK = ['label' => 'See it in Travel →', 'href' => '/travel'];

    /** How they travel, as said; null is "fastest realistic". */
    private const MODES = ['transit' => 'by bus and train', 'bike' => 'by bike', 'walk' => 'on foot', 'fastest' => 'the fastest way'];

    public function __construct(private FavouriteStops $stops, private PlannedTrips $planned, private DayProjection $day, private NextTrip $trips) {}

    public function prepare(User $user, string $action, array $input, CarbonImmutable $now): array
    {
        return match ($action) {
            'save_stop' => $this->prepareStop($user, $input),
            'set_travel_prefs' => $this->preparePrefs($user, $input),
            default => $this->prepareWay($user, $input, $now),
        };
    }

    public function run(User $user, string $action, array $prepared, CarbonImmutable $now): array
    {
        if ($action === 'save_stop') {
            $this->star($user, $prepared['stop'], $prepared['saved']);
            $title = $prepared['saved'] ? "{$prepared['stop']} is a favourite stop" : "{$prepared['stop']} isn’t a favourite stop any more";

            return ['title' => $title, 'link' => self::LINK, 'said' => "{$title}.", 'undo' => ['stop' => $prepared['stop'], 'saved' => $prepared['saved']]];
        }
        if ($action === 'set_travel_prefs') {
            $this->setPrefs($user, $prepared['set']);
            $title = self::prefsTitle($prepared['set']);

            return ['title' => $title, 'link' => self::LINK, 'said' => "{$title}.", 'undo' => ['previous' => $prepared['previous']]];
        }
        if (DepartureTime::parse($prepared['journey']['depart_at'])?->isPast() ?? true) {
            throw new ActionRefused('That way has left already. Ask again for the next one.');
        }
        $plan = $this->plan($user, $prepared['date'], $prepared['plan']);
        $previous = $this->planned->for($user, $prepared['date'], $plan->id);
        $this->planned->keep($user, $prepared['date'], $plan, $prepared['journey'], $prepared['remind']);
        $title = "The way to {$prepared['title']} is in your plan";

        return array_filter(['title' => $title, 'link' => self::LINK,
            // Without push on, the reminder needs it turned on.
            'sub' => $prepared['sub'].($prepared['remind'] && ! $user->pushSubscriptions()->exists() ? ' · Turn on notifications in Settings to get it' : ''),
            'said' => 'The way to the plan they meant is in their plan, leaving at '.$prepared['leave'].($prepared['remind'] ? ', with a reminder five minutes before' : '').'.',
            'undo' => ['date' => $prepared['date'], 'plan' => $plan->id, 'title' => $prepared['title'],
                'previous' => $previous === null ? null : $this->planned->snapshot($previous)]], fn ($v) => $v !== null);
    }

    public function undo(User $user, string $action, array $undo, CarbonImmutable $now): array
    {
        if ($action === 'save_stop') {
            $this->star($user, $undo['stop'], ! $undo['saved']);

            return ['title' => $undo['saved'] ? "Undone. {$undo['stop']} isn’t a favourite stop." : "Undone. {$undo['stop']} is a favourite stop again."];
        }
        if ($action === 'set_travel_prefs') {
            $this->setPrefs($user, $undo['previous']);

            return ['title' => 'Undone. '.self::prefsTitle($undo['previous'], again: true)];
        }
        if ($undo['previous'] === null) {
            $this->planned->forget($user, $undo['date'], $undo['plan']);

            return ['title' => "Undone. No way is planned to {$undo['title']}."];
        }
        $this->planned->restore($user, $undo['date'], $undo['plan'], $undo['previous']);

        return ['title' => "Undone. The way to {$undo['title']} is the one you had."];
    }

    /** @return array<string, mixed> */
    private function prepareStop(User $user, array $input): array
    {
        $said = trim((string) ($input['stop'] ?? ''));
        if ($said === '') {
            throw new ActionRefused('Say which stop.');
        }
        $found = $this->stops->stations($said);
        if ($found === []) {
            throw new ActionRefused($this->stops->readable() ? "No KVB stop is called \"{$said}\"." : FavouriteStops::UNREADABLE);
        }
        // Several fit: they're asked which, never one picked for them.
        if (count($found) > 1) {
            throw new ActionRefused("Several KVB stops fit \"{$said}\": ".implode(', ', $found).'. Ask them which one.');
        }
        $stop = $found[0];
        $saved = ($input['saved'] ?? true) !== false;
        if ($this->stops->has($user->id, $stop) === $saved) {
            throw new ActionRefused($saved ? "{$stop} is a favourite stop already." : "{$stop} isn’t a favourite stop.");
        }

        return ['stop' => $stop, 'saved' => $saved, 'propose' => $saved ? "Make {$stop} a favourite stop?" : "Take {$stop} off your favourite stops?"];
    }

    /** @return array<string, mixed> */
    private function preparePrefs(User $user, array $input): array
    {
        $previous = ['mode' => $user->transport_mode?->value ?? 'fastest', 'deutschlandticket' => (bool) $user->has_deutschlandticket];
        $set = array_filter([
            'mode' => is_string($input['mode'] ?? null) && isset(self::MODES[$input['mode']]) ? $input['mode'] : null,
            'deutschlandticket' => is_bool($input['deutschlandticket'] ?? null) ? $input['deutschlandticket'] : null,
        ], fn ($v) => $v !== null);
        // Only what changes: "I have a Deutschlandticket" when it's noted already changes nothing.
        $set = array_filter($set, fn (mixed $value, string $key): bool => $previous[$key] !== $value, ARRAY_FILTER_USE_BOTH);
        if ($set === []) {
            throw new ActionRefused('That’s how Travel has it already.');
        }

        return ['set' => $set, 'previous' => array_intersect_key($previous, $set), 'propose' => self::prefsTitle($set, ask: true)];
    }

    /**
     * The way to a plan on their day: the one they named, else the next with a place, from
     * where they are, arriving with the plan's margin (NextTrip, as Today times it).
     *
     * @return array<string, mixed>
     */
    private function prepareWay(User $user, array $input, CarbonImmutable $now): array
    {
        $now = $now->setTimezone(DayProjection::TIMEZONE);
        $said = trim((string) ($input['plan'] ?? ''));
        $ahead = collect([...$this->day->itemsOn($user, $now->toDateString()), ...$this->day->itemsOn($user, $now->addDay()->toDateString())])
            ->filter(fn (DayItem $item): bool => ! $item->completed() && $item->available && $item->startAt->greaterThan($now)
                && ($item->kind === 'appointment' || $item->routable()))
            ->values();
        $key = fn (string $text): string => preg_replace('/[^a-z0-9]/', '', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text));
        $named = $said === '' || in_array($key($said), ['next', 'mynext', 'mynextplan'], true) ? $ahead->take(1)
            : $ahead->filter(fn (DayItem $item): bool => str_contains($key($item->title), $key($said)) || str_contains($key($said), $key($item->title)))->values();
        if ($named->isEmpty()) {
            throw new ActionRefused($ahead->isEmpty() ? 'There’s no plan with a place on their day today to plan the way to. Add one to their day first.'
                : 'No plan on their day today has that name.');
        }
        if ($named->count() > 1) {
            throw new ActionRefused('More than one plan on their day fits that. Ask them which one.');
        }
        $plan = $named->first();
        $from = ($input['_from'] ?? null) instanceof LocationContext ? $input['_from'] : null;
        $point = $from?->toGeoPoint();
        $trip = $this->trips->for($plan, $point === null ? null : new TripOrigin($point, $from->label ?? 'Your location', $from->source->value), $now, null, $user);
        if (($trip['journey'] ?? null) === null) {
            throw new ActionRefused(match ($trip['reason'] ?? null) {
                'location_unknown' => 'Where they’re starting from isn’t known: Travel can plan it from where they are.',
                'destination_unknown' => 'That plan has no place recorded, so there’s no way to plan to it.',
                'already_there' => 'They’re there already.',
                default => 'No connection there was found right now. Travel can look again.',
            });
        }
        if (StoredJourney::problem($trip['journey']) !== null) {
            throw new ActionRefused('That way can’t be kept. Travel can find a fresh one.');
        }
        $remind = ($input['remind'] ?? true) !== false;
        $leave = (string) $trip['leave_time'];

        return ['date' => $plan->startAt->setTimezone(DayProjection::TIMEZONE)->toDateString(), 'plan' => $plan->id, 'title' => $plan->title,
            'journey' => $trip['journey'], 'remind' => $remind, 'leave' => $leave,
            'sub' => "Leave by {$leave}, there at {$trip['arrive_time']}".($trip['status'] === 'late' ? ', after it starts' : '').($remind ? ' · Reminder 5 min before' : ''),
            'propose' => "Add the way to {$plan->title} to your plan?",
            'tell' => 'Add the way to the plan they meant, leaving at '.$leave.($remind ? ', with a reminder' : '').'?'];
    }

    /** @throws ActionRefused */
    private function star(User $user, string $stop, bool $saved): void
    {
        try {
            $saved ? $this->stops->add($user->id, $stop) : $this->stops->remove($user->id, $stop);
        } catch (ValidationException $error) {
            throw new ActionRefused((string) collect($error->errors())->flatten()->first());
        }
    }

    /** @param  array{mode?: string, deutschlandticket?: bool}  $set */
    private function setPrefs(User $user, array $set): void
    {
        if (isset($set['mode'])) {
            $user->update(['transport_mode' => $set['mode'] === 'fastest' ? null : TransportMode::from($set['mode'])]);
        }
        if (isset($set['deutschlandticket'])) {
            $user->forceFill(['has_deutschlandticket' => $set['deutschlandticket']])->save();
        }
    }

    /** "You travel by bike by default; you have a Deutschlandticket", or the question asking it. */
    private static function prefsTitle(array $set, bool $ask = false, bool $again = false): string
    {
        $parts = [];
        if (isset($set['mode'])) {
            $parts[] = $ask ? 'travel '.self::MODES[$set['mode']].' by default' : 'You travel '.self::MODES[$set['mode']].' by default'.($again ? ' again' : '');
        }
        if (isset($set['deutschlandticket'])) {
            $parts[] = $set['deutschlandticket'] ? ($ask ? 'note that you have a Deutschlandticket' : 'you have a Deutschlandticket')
                : ($ask ? 'note that you don’t have a Deutschlandticket' : 'you don’t have a Deutschlandticket');
        }
        $line = implode($ask ? ' and ' : '; ', $parts);

        return $ask ? ucfirst($line).'?' : ucfirst($line);
    }

    private function plan(User $user, string $date, string $id): DayItem
    {
        return collect($this->day->itemsOn($user, $date))->first(fn (DayItem $item): bool => $item->id === $id)
            ?? throw new ActionRefused('That plan isn’t on your day any more.');
    }
}
