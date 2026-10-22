<?php

namespace App\Composer\Assistant;

use App\Composer\Constraints;
use App\Enums\SpotCategory;
use App\Http\Controllers\ComposerController;
use App\Models\User;
use App\Places\PlaceCapabilities;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Composer's planner as one of the assistant's tools (plan_day). The AI fills in what the
 * person asked for (a day, a time, kinds of places, an area, a mood), as the old request
 * reader did; Composer's own planner then puts the day together from where they start
 * (ComposerController::compose), and the plan is kept for the answer to show and add.
 * The planner picks the places, never the AI.
 */
final class PlanDay
{
    /** Shortest stretch the planner can fill with an activity and the way there. */
    private const MIN_WINDOW_MINUTES = 180;

    /** Areas as people in Cologne call them, by the Veedel the planner knows (owner's test, 22 Oct 2026: "Südstadt"). */
    private const AREA_NAMES = [
        'südstadt' => 'Neustadt-Süd', 'suedstadt' => 'Neustadt-Süd', 'sudstadt' => 'Neustadt-Süd', 'kwartier latäng' => 'Neustadt-Süd',
        'belgisches viertel' => 'Neustadt-Nord', 'belgian quarter' => 'Neustadt-Nord', 'agnesviertel' => 'Neustadt-Nord',
        'altstadt' => 'Altstadt-Nord', 'old town' => 'Altstadt-Nord', 'innenstadt' => 'Altstadt-Nord', 'city centre' => 'Altstadt-Nord',
        'city center' => 'Altstadt-Nord', 'zentrum' => 'Altstadt-Nord', 'rheinauhafen' => 'Altstadt-Süd',
    ];

    /** @return array<string, mixed> the tool, as the Messages API takes it */
    public static function definition(): array
    {
        $kinds = self::kinds();

        return ['name' => 'plan_day', 'description' => 'Put a day or an evening together with Expadu\'s planner: places and listed events in order, with the way between them, from where the person starts. Use it when they ask to plan, or ask for a stretch of time with things to do ("my Saturday", "this evening in Ehrenfeld", "a museum and then coffee"). Fill in only what they said. Returns the plan, which you can show.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'day' => ['type' => 'string', 'description' => 'today, tomorrow, a weekday (Saturday) or YYYY-MM-DD, within 3 days'],
                'from' => ['type' => 'string', 'description' => 'HH:MM. "after work" 17:30; "tonight" 18:00; "this afternoon" 13:00; "this morning" 09:00; a day named alone 10:00; none said: now'],
                'until' => ['type' => 'string', 'description' => 'HH:MM. "after work" 20:00; "tonight" 23:00; "this afternoon" 18:00; "this morning" 12:00; a day named alone 20:00'],
                'areas' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Cologne Veedel they named (Südstadt is Neustadt-Süd, the old town Altstadt-Nord). Never their home Veedel unless named.'],
                'kinds' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $kinds], 'description' => 'Kinds of place they named or clearly meant ("brunch": cafe, restaurant, bakery; "drinks": bar; "what\'s on": event). Empty for "surprise me".'],
                'sequence' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $kinds], 'description' => 'When they ordered two or more stops ("a museum, then coffee"), one kind per stop in that order.'],
                'activities' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => PlaceCapabilities::ACTIVITIES], 'description' => 'Sports they named; football is soccer.'],
                'avoid' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $kinds], 'description' => 'Kinds they ruled out ("no museums").'],
                'moods' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => Constraints::MOODS]],
                'needs' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => Constraints::NEEDS], 'description' => 'step_free only when they say wheelchair, step-free or no stairs.'],
                'companions' => ['type' => 'string', 'enum' => ['alone', 'partner', 'friends', 'kids']],
                'budget' => ['type' => 'string', 'enum' => ['free', 'low', 'normal'], 'description' => '"costs nothing": free; "cheap": low'],
                'radius_km' => ['type' => 'number', 'description' => 'A distance they named; "nearby" is 2.5'],
            ], 'required' => ['day']]];
    }

    /**
     * The plan for what the AI filled in: its slots and the token the workspace adds it
     * with, or why there's none, in words the AI can pass on.
     *
     * @param  array<string, mixed>  $input
     * @return array{plan: array<string, mixed>, token: string, origin: ?string}|string
     */
    public function plan(User $user, array $input, CarbonImmutable $now): array|string
    {
        $now = $now->setTimezone('Europe/Berlin');
        $named = mb_strtolower(trim((string) ($input['day'] ?? 'today')));
        $day = match (true) {
            $named === 'today' => $now->startOfDay(),
            $named === 'tomorrow' => $now->startOfDay()->addDay(),
            // "Saturday": the next one, today included.
            in_array($named, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], true) => $now->startOfDay()->addDays((array_search($named, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], true) + 1 - $now->dayOfWeekIso + 7) % 7),
            default => rescue(fn () => CarbonImmutable::parse((string) $input['day'], 'Europe/Berlin')->startOfDay(), null, false),
        };
        if ($day === null || $day->lessThan($now->startOfDay()) || $day->greaterThan($now->startOfDay()->addDays(3))) {
            return 'The planner plans today and the next 3 days only.';
        }
        $from = self::clock($input['from'] ?? null, $day) ?? ($day->isSameDay($now) ? $now : $day->setTime(10, 0));
        // A day named alone ("plan my Saturday") is the day, until 20:00; a start alone, four hours.
        $until = self::clock($input['until'] ?? null, $day)
            ?? (! isset($input['from']) ? $from->addHours(4)->max($day->setTime(20, 0)) : $from->addHours(4));
        $sequence = self::listOf($input['sequence'] ?? [], self::kinds());
        $moods = self::listOf($input['moods'] ?? [], Constraints::MOODS);
        $areas = self::areas($input['areas'] ?? []);
        // An area it doesn't know is said, never planned somewhere else instead.
        $unknown = array_values(array_filter(is_array($input['areas'] ?? null) ? $input['areas'] : [], fn (mixed $area): bool => is_string($area) && self::areas([$area]) === []));
        if ($unknown !== []) {
            return 'The planner doesn\'t know the area "'.implode('", "', $unknown).'". It plans by Cologne\'s Veedel (e.g. Neustadt-Süd for the Südstadt).';
        }
        $constraints = $this->clamped(new Constraints(
            windowStart: $from,
            windowEnd: $until->greaterThan($from) ? $until : $from->addHours(4),
            areas: $areas,
            categories: array_values(array_unique([...self::listOf($input['kinds'] ?? [], self::kinds()), ...(count($sequence) >= 2 ? $sequence : [])])),
            companions: self::oneOf($input['companions'] ?? null, ['alone', 'partner', 'friends', 'kids']),
            budget: self::oneOf($input['budget'] ?? null, ['free', 'low', 'normal']),
            vibe: match (true) {
                array_intersect($moods, ['active', 'lively']) !== [] => 'active',
                array_intersect($moods, ['quiet', 'cosy', 'relaxed']) !== [] => 'chill',
                default => null,
            },
            activities: self::listOf($input['activities'] ?? [], PlaceCapabilities::ACTIVITIES),
            radiusKm: is_numeric($input['radius_km'] ?? null) && $input['radius_km'] >= 0.1 && $input['radius_km'] <= 50 ? (float) $input['radius_km'] : null,
            moods: $moods,
            avoid: array_slice(self::listOf($input['avoid'] ?? [], self::kinds()), 0, 60),
            sequence: count($sequence) >= 2 ? $sequence : [],
            // Their own step-free need, from their settings, whatever the AI was told: it never is.
            needs: array_values(array_unique([...self::listOf($input['needs'] ?? [], Constraints::NEEDS), ...($user->needs_step_free ? ['step_free'] : [])])),
        ), $now, $from);

        // The planner as the page calls it, from the start the person chose on the page (or
        // where they are), so its rules and limits are the page's own.
        $origin = array_filter(request()->only(['lat', 'lng', 'from_label', 'from_place', 'from_area']), fn ($value) => $value !== null && $value !== '');
        $call = Request::create('/composer/compose', 'POST', ['constraints' => $constraints->toArray(), ...$origin]);
        $call->setUserResolver(fn () => $user);
        try {
            $body = app()->call([app(ComposerController::class), 'compose'], ['request' => $call])->getData(true);
        } catch (ValidationException $error) {
            return 'The planner couldn\'t plan that: '.collect($error->errors())->flatten()->first();
        } catch (Throwable) {
            return 'The planner isn\'t answering right now.';
        }
        if (! is_string($body['handoff_token'] ?? null) || ($body['plan']['slots'] ?? []) === []) {
            return 'Nothing the planner knows fits that time. Another time, area or kind of place may work.';
        }

        return ['plan' => $body['plan'], 'token' => $body['handoff_token'], 'origin' => $body['origin']['label'] ?? null];
    }

    /**
     * The plan as the AI reads it: its day and stops, by name, kind and Veedel. The person's
     * own appointments in it stay theirs: the AI only learns a stop is theirs.
     *
     * @param  array<string, mixed>  $plan
     */
    public static function text(array $plan): string
    {
        $slots = collect($plan['slots']);
        $first = CarbonImmutable::parse($slots->first()['start_at'])->setTimezone('Europe/Berlin');

        return 'A plan for '.$first->format('l j F').":\n".$slots->map(function (array $slot): string {
            $start = CarbonImmutable::parse($slot['start_at'])->setTimezone('Europe/Berlin')->format('H:i');
            if ($slot['is_appointment'] ?? false) {
                return "- {$start}: one of their own plans (not shown to you)";
            }
            $kind = SpotCategory::tryFrom((string) $slot['category'])?->label() ?? ucfirst(str_replace('_', ' ', (string) $slot['category']));

            return "- {$start}".($slot['end_time'] !== null ? "–{$slot['end_time']}" : '').": \"{$slot['name']}\" ({$kind})".(filled($slot['veedel']) ? " in {$slot['veedel']}" : '');
        })->implode("\n");
    }

    /**
     * Within the planner's reach: never in the past, at most 72 hours ahead, on one day, and
     * long enough to fill when the time now cut it short.
     */
    private function clamped(Constraints $constraints, CarbonImmutable $now, CarbonImmutable $asked): Constraints
    {
        $horizon = $now->addHours(72);
        $start = self::quarter($constraints->windowStart->max($now), up: true);
        $end = self::quarter($constraints->windowEnd->min($horizon), up: false);
        // As long as asked ("two hours after work"); only a stretch cut short by the time now
        // ("this afternoon" at 17:00), or gone by, gets room for something to do.
        if ($start->diffInMinutes($end, false) <= 0 || ($start->greaterThan(self::quarter($asked, up: true)) && $start->diffInMinutes($end) < self::MIN_WINDOW_MINUTES)) {
            $end = $start->addMinutes(self::MIN_WINDOW_MINUTES)->min($horizon);
        }

        return $constraints->with(['windowStart' => $start, 'windowEnd' => $end->min($start->setTime(23, 59))]);
    }

    /** @return list<string> */
    private static function kinds(): array
    {
        return array_values(array_unique([...array_map(fn (SpotCategory $c): string => $c->value, SpotCategory::cases()), ...SpotCategory::placesCoarse(), 'event']));
    }

    /** @return list<string> the Veedel named, as Cologne spells them ("Südstadt" is Neustadt-Süd) */
    public static function areas(mixed $areas): array
    {
        $known = collect(config('veedels', []))->flatten()->filter(fn (mixed $area): bool => is_string($area))
            ->mapWithKeys(fn (string $area): array => [mb_strtolower($area) => $area])->all() + self::AREA_NAMES;

        return collect(is_array($areas) ? $areas : [])->filter(fn (mixed $area): bool => is_string($area))
            ->map(fn (string $area): ?string => $known[mb_strtolower(trim(preg_replace('/^(the|die|das|der)\s+/iu', '', trim($area))))] ?? null)
            ->filter()->unique()->values()->all();
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function listOf(mixed $values, array $allowed): array
    {
        return is_array($values) ? array_values(array_unique(array_intersect(array_filter($values, 'is_string'), $allowed))) : [];
    }

    /** @param  list<string>  $allowed */
    private static function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private static function clock(mixed $value, CarbonImmutable $day): ?CarbonImmutable
    {
        return is_string($value) && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value) === 1 ? $day->setTimeFromTimeString($value) : null;
    }

    /** A time on a quarter hour (15:52 → 16:00), as plans are shown. */
    private static function quarter(CarbonImmutable $time, bool $up): CarbonImmutable
    {
        $time = $time->startOfMinute();
        $over = $time->minute % 15;

        return $over === 0 ? $time : ($up ? $time->addMinutes(15 - $over) : $time->subMinutes($over));
    }
}
