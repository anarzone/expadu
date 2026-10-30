<?php

namespace App\Composer\Eval;

use App\Enums\SpotCategory;
use App\Models\Spot;
use App\Places\PlaceFacts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Checks one composed plan for the Composer evaluation (composer:eval).
 *
 * Gates must stay at zero for a release: a stop the database doesn't know
 * (unknown_id), a stop whose name, category, place or start differs from its
 * row (fact_mismatch), and a plan that can't be followed (infeasible: outside
 * the window, overlapping stops, too little time to travel, ending after the
 * place closes). Expectations are the request's own wants ("indoors", "with
 * the kids", "in Ehrenfeld"), reported as pass rates, not gates.
 */
final class PlanChecks
{
    public const GATES = ['unknown_id', 'fact_mismatch', 'infeasible'];

    /** Never part of a day out with children. */
    private const ADULT_ONLY = ['bar', 'nightclub', 'pub', 'casino', 'tattoo'];

    /** Shops and services a leisure plan should not offer unless asked. */
    private const ERRANDS = ['pharmacy', 'supermarket', 'convenience', 'drugstore', 'greengrocer', 'kiosk', 'bank', 'laundry', 'dry_cleaning'];

    /** How far a stop's coordinates may sit from its row (routing entrances move them). */
    private const PLACE_TOLERANCE_M = 400;

    /**
     * @param  list<array<string, mixed>>  $slots  the compose response's plan.slots
     * @return array{gates: array<string, list<string>>, metrics: array<string, mixed>}
     */
    public function gates(array $slots, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $gates = array_fill_keys(self::GATES, []);
        $previousEnd = null;

        foreach ($slots as $i => $slot) {
            $id = (string) ($slot['id'] ?? '');
            $label = "#{$i} {$id}";
            $start = isset($slot['start_at']) ? CarbonImmutable::parse($slot['start_at']) : null;
            $end = isset($slot['end_at']) ? CarbonImmutable::parse($slot['end_at']) : null;

            foreach ($this->factProblems($slot) as $problem) {
                $gates[$problem['gate']][] = "{$label}: {$problem['why']}";
            }

            if ($start === null || $end === null || $end->lessThan($start)) {
                $gates['infeasible'][] = "{$label}: missing or reversed times";

                continue;
            }
            if ($start->lessThan($windowStart->subMinute()) || $end->greaterThan($windowEnd->addMinute())) {
                $gates['infeasible'][] = "{$label}: {$start->format('H:i')}–{$end->format('H:i')} is outside the window";
            }
            $travel = (int) ($slot['travel_min_from_previous'] ?? 0);
            if ($previousEnd !== null && $previousEnd->addMinutes($travel)->greaterThan($start->addMinute())) {
                $gates['infeasible'][] = "{$label}: starts before the previous stop ends plus {$travel} min travel";
            }
            $closes = $slot['closes_at'] ?? null;
            if (is_string($closes) && ! ($slot['hours_assumed'] ?? false) && ($slot['type'] ?? null) === 'spot' && ($slot['duration_known'] ?? true)) {
                [$h, $m] = array_map('intval', explode(':', $closes));
                $closesAt = $end->setTime($h, $m);
                // A closing time after midnight belongs to the next day.
                if ($closesAt->lessThan($start)) {
                    $closesAt = $closesAt->addDay();
                }
                if ($end->greaterThan($closesAt->addMinute())) {
                    $gates['infeasible'][] = "{$label}: ends {$end->format('H:i')}, after it closes at {$closes}";
                }
            }
            $previousEnd = $end;
        }

        return ['gates' => $gates, 'metrics' => [
            'slots' => count($slots),
            'legs_min' => array_values(array_map(fn (array $s): int => (int) ($s['travel_min_from_previous'] ?? 0), $slots)),
        ]];
    }

    /**
     * The request's own wants, each true or false; only the ones it states.
     *
     * @param  array<string, mixed>  $expect
     * @param  list<array<string, mixed>>  $slots
     * @param  array<string, mixed>|null  $constraints  the parsed constraints
     * @return array<string, bool>
     */
    public function expectations(array $expect, array $slots, ?array $constraints): array
    {
        $n = count($slots);
        $categories = array_map(fn (array $s): string => (string) ($s['category'] ?? ''), $slots);
        $coarse = array_map(fn (string $c): string => SpotCategory::tryFrom($c)?->coarse() ?? $c, $categories);
        $share = fn (callable $test): float => $n === 0 ? 0.0 : count(array_filter($slots, $test)) / $n;
        $results = [];

        if ($n === 0) {
            return ['non_empty' => (bool) ($expect['allow_empty'] ?? false)];
        }
        $results['non_empty'] = true;

        foreach ($expect as $key => $want) {
            $results[$key] = match ($key) {
                'min_slots' => $n >= $want,
                'max_slots' => $n <= $want,
                'categories_any' => array_intersect($want, [...$categories, ...$coarse]) !== [],
                'types_any' => array_intersect($want, array_column($slots, 'type')) !== [],
                'indoor_min_share' => $share(fn (array $s): bool => ! ($s['outdoor'] ?? false)) >= $want,
                'outdoor_min_share' => $share(fn (array $s): bool => (bool) ($s['outdoor'] ?? false)) >= $want,
                'budget' => array_diff(array_column($slots, 'cost_tier'), $want === 'free' ? ['free'] : ['free', 'low']) === [],
                'kid_safe' => array_intersect($categories, self::ADULT_ONLY) === [],
                'step_free' => $share(fn (array $s): bool => $this->stepFree($s)) === 1.0,
                'area' => $share(fn (array $s): bool => ($s['veedel'] ?? null) === $want) >= 0.5,
                'no_errands' => array_intersect($categories, self::ERRANDS) === [],
                'max_leg_min' => max(array_map(fn (array $s): int => (int) ($s['travel_min_from_previous'] ?? 0), $slots)) <= $want,
                'ends_with_any' => in_array(end($categories), $want, true) || in_array(end($coarse), $want, true),
                // A plan is one day: no stop may end on the next day.
                'same_day' => collect($slots)->every(fn (array $s): bool => isset($s['start_at'], $s['end_at'])
                    && CarbonImmutable::parse($s['end_at'])->toDateString() === CarbonImmutable::parse($slots[0]['start_at'])->toDateString()),
                'max_window_min' => $constraints !== null && isset($constraints['window_start'], $constraints['window_end'])
                    && CarbonImmutable::parse($constraints['window_start'])->diffInMinutes(CarbonImmutable::parse($constraints['window_end'])) <= $want,
                default => null,
            };
        }

        return array_filter($results, fn ($value): bool => $value !== null);
    }

    private function stepFree(array $slot): bool
    {
        // OSM's wheelchair=yes|designated, as the place's practical facts carry it.
        return in_array($slot['place_facts']['practical']['wheelchair']['value'] ?? null, ['yes', 'designated'], true);
    }

    /**
     * @return list<array{gate: string, why: string}>
     */
    private function factProblems(array $slot): array
    {
        $id = (string) ($slot['id'] ?? '');
        $parts = explode(':', $id);
        $problems = [];

        if (($slot['type'] ?? null) === 'appointment' || $parts[0] === 'appointment') {
            return [];
        }

        if ($parts[0] === 'spot' && ctype_digit($parts[1] ?? '')) {
            $row = DB::table('spots')->where('id', (int) $parts[1])->first(['name', 'category', 'lat', 'lng', 'is_active']);
            if ($row === null) {
                return [['gate' => 'unknown_id', 'why' => 'no such place']];
            }
            if ($row->is_active === false) {
                $problems[] = ['gate' => 'fact_mismatch', 'why' => 'place is inactive'];
            }
            // The name the app shows is the place's display name ("Picnic spot in Kwattapark" for a "Picknickplatz").
            $shown = fn (): ?string => app(PlaceFacts::class)->resolve(Spot::query()->findOrFail((int) $parts[1]))['display_name'] ?? null;
            if (trim((string) $row->name) !== trim((string) ($slot['name'] ?? '')) && trim((string) $shown()) !== trim((string) ($slot['name'] ?? ''))) {
                $problems[] = ['gate' => 'fact_mismatch', 'why' => "name \"{$slot['name']}\" ≠ \"{$row->name}\""];
            }
            if ((string) $row->category !== (string) ($slot['category'] ?? '')) {
                $problems[] = ['gate' => 'fact_mismatch', 'why' => "category {$slot['category']} ≠ {$row->category}"];
            }
            if (isset($slot['lat'], $slot['lng']) && $row->lat !== null
                && $this->metres((float) $row->lat, (float) $row->lng, (float) $slot['lat'], (float) $slot['lng']) > self::PLACE_TOLERANCE_M) {
                $problems[] = ['gate' => 'fact_mismatch', 'why' => 'coordinates far from the place'];
            }

            return $problems;
        }

        if ($parts[0] === 'event' && ctype_digit($parts[1] ?? '')) {
            $row = DB::table('events')->where('id', (int) $parts[1])->first(['title', 'title_en', 'starts_at', 'status']);
            if ($row === null) {
                return [['gate' => 'unknown_id', 'why' => 'no such event']];
            }
            if ($row->status !== 'active') {
                $problems[] = ['gate' => 'fact_mismatch', 'why' => "event is {$row->status}"];
            }
            $name = trim((string) ($slot['name'] ?? ''));
            if ($name !== trim((string) $row->title) && $name !== trim((string) $row->title_en)) {
                $problems[] = ['gate' => 'fact_mismatch', 'why' => "title \"{$name}\" is not the event's"];
            }
            // A recurring event names its occurrence; a one-off starts when its row says.
            $occurrence = isset($parts[2]) && ctype_digit($parts[2])
                ? CarbonImmutable::createFromTimestamp((int) $parts[2])
                : CarbonImmutable::parse($row->starts_at, config('app.timezone'));
            if (isset($slot['start_at']) && abs(CarbonImmutable::parse($slot['start_at'])->diffInMinutes($occurrence)) > 1) {
                $problems[] = ['gate' => 'fact_mismatch', 'why' => 'start differs from the event\'s'];
            }

            return $problems;
        }

        return [['gate' => 'unknown_id', 'why' => 'unrecognised id']];
    }

    private function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
