<?php

namespace App\ContextEngine;

use App\Composer\WorkspaceStore;
use App\Models\User;
use App\Services\DisruptionService;
use App\Services\GtfsDepartureService;
use App\Services\NearbyStopService;
use App\Services\OpeningHoursParser;
use App\Services\WeatherService;
use App\Transit\TransitNotices;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * What threatens the stops of their plan for a day (coordinator, 26 Oct 2026, phase 2): a KVB
 * disruption on a line serving a stop near it (500 m), major or naming one of those stops, a
 * delay there when it's under two hours away, rain or a hazard (frost, heat, storms) forecast
 * while they're at an outdoor stop, and a stop listed closed that day. Disruptions and delays are live, so on the day itself only. Their
 * own plans and appointments have no place of ours, so nothing is said of them. Each risk has its
 * own line and the facts it comes from (DayBriefs, BriefWriter), and weighs on the day
 * (DayImportance); one under two hours away pushes (notifications:watch-plans).
 */
final class PlanRisks
{
    /** Stops this close serve the place (NearbyStopService). */
    private const NEAR_METRES = 500;

    /** Live delays are looked up for a stop starting within this. */
    public const SOON_MINUTES = 120;

    /** A delay counts from this many minutes (CheckTransitDelays). */
    private const DELAY_MINUTES = 10;

    /** Rain from this much an hour (WeatherService's rain start). */
    private const RAIN_MM = 0.1;

    /** Frost below, heat above (CheckWeatherAlerts). */
    private const FREEZE_BELOW = 0;

    private const HEAT_ABOVE = 33;

    public function __construct(private WorkspaceStore $workspace) {}

    /**
     * @return list<array{slot: string, name: string, start: string, at: CarbonImmutable, kind: string, severity: string, text: string, facts: string}>
     */
    public function for(User $user, CarbonImmutable $day): array
    {
        $day = $day->setTimezone('Europe/Berlin')->startOfDay();
        $now = CarbonImmutable::now('Europe/Berlin');
        $today = $day->isSameDay($now);
        $slots = collect($this->workspace->plan($user, $day->toDateString())['slots'] ?? [])
            ->filter(fn (array $slot): bool => ($slot['type'] ?? null) === 'spot' && isset($slot['start_at'], $slot['end_at']) && ! ($slot['is_appointment'] ?? false))
            ->sortBy('start_at')->values();
        if ($slots->isEmpty()) {
            return [];
        }
        // KVB's own notices: VRS's and DELFI's show on boards and aren't pushed (CheckTransitDisruptions, PlannedTripWatch).
        $disruptions = $today ? array_values(array_filter(self::quietly(fn () => app(DisruptionService::class)->getLineDisruptions(), []),
            fn (array $d): bool => ! in_array($d['source'] ?? null, TransitNotices::SOURCES, true))) : [];
        $hours = $slots->contains(fn (array $slot): bool => ($slot['outdoor'] ?? false) === true)
            ? self::quietly(fn () => app(WeatherService::class)->getDayForecast($day->toDateString())['hours'] ?? [], []) : [];

        $risks = [];
        foreach ($slots as $slot) {
            $start = CarbonImmutable::parse($slot['start_at'])->setTimezone('Europe/Berlin');
            $end = CarbonImmutable::parse($slot['end_at'])->setTimezone('Europe/Berlin');
            $name = (string) $slot['name'];
            $at = $start->format('H:i');
            $span = $at.'–'.$end->format('H:i');
            $add = function (string $kind, string $severity, string $text, string $facts) use (&$risks, $slot, $name, $at, $start): void {
                $risks[] = ['slot' => (string) $slot['id'], 'name' => $name, 'start' => $at, 'at' => $start, 'kind' => $kind, 'severity' => $severity, 'text' => $text, 'facts' => $facts];
            };

            if (self::closed($slot, $day)) {
                $when = $today ? 'today' : 'that day';
                $add('closed', 'major', "{$name} is listed closed {$when}.", "\"{$name}\", in their plan at {$at}, is listed closed {$when}.");
            }

            $point = is_numeric($slot['lat'] ?? null) && is_numeric($slot['lng'] ?? null) ? [(float) $slot['lat'], (float) $slot['lng']] : null;
            if ($today && $point !== null && $end->greaterThan($now)) {
                $near = collect(self::quietly(fn () => app(NearbyStopService::class)->getWalkableStops($point[0], $point[1], self::NEAR_METRES), []));
                $lines = $near->flatMap(fn (array $stop): array => array_map('strval', $stop['lines'] ?? []))->unique()->values()->all();
                $stops = $near->map(fn (array $stop): string => self::stopKey((string) ($stop['name'] ?? '')))->filter()->values()->all();
                // A major notice on one of those lines counts wherever it is; an everyday one only when it names
                // one of those stops (owner's two tiers: everyday stays in place). One risk a line, its worst.
                $worst = [];
                foreach ($disruptions as $disruption) {
                    $major = ($disruption['scale'] ?? null) === 'major';
                    $here = collect($disruption['affected_stops'] ?? [])->contains(fn (mixed $stop): bool => is_string($stop) && in_array(self::stopKey($stop), $stops, true));
                    if (! $major && ! $here) {
                        continue;
                    }
                    foreach (array_intersect(array_map('strval', $disruption['affected_lines'] ?? []), $lines) as $line) {
                        $worst[$line] = $major || ($worst[$line] ?? null) === 'critical' ? 'critical' : 'moderate';
                    }
                }
                foreach ($worst as $line => $severity) {
                    $add('disruption', $severity, "Line {$line} disrupted near {$name} ({$at}).",
                        "KVB disruption on line {$line}, which serves a stop near \"{$name}\", in their plan at {$at}.");
                }
                if ($start->lessThanOrEqualTo($now->addMinutes(self::SOON_MINUTES))) {
                    $late = collect(self::quietly(fn () => app(GtfsDepartureService::class)->getDeparturesNearby($point[0], $point[1], 10)['departures'] ?? [], []))
                        ->filter(fn (array $dep): bool => ((int) ($dep['delay'] ?? 0) >= self::DELAY_MINUTES || ($dep['cancelled'] ?? false) === true) && in_array((string) ($dep['line'] ?? ''), $lines, true))
                        ->first();
                    if ($late !== null) {
                        $what = ($late['cancelled'] ?? false) === true ? 'a cancelled ride' : ((int) $late['delay']).' min late';
                        $add('delay', ($late['cancelled'] ?? false) === true ? 'major' : 'moderate', "Line {$late['line']} running {$what} near {$name} ({$at}).",
                            "Line {$late['line']} has {$what} at a stop near \"{$name}\", in their plan at {$at}.");
                    }
                }
            }

            if (($slot['outdoor'] ?? false) === true) {
                $during = collect($hours)->filter(fn (array $hour): bool => is_string($hour['time'] ?? null)
                    && CarbonImmutable::parse($hour['time'])->setTimezone('Europe/Berlin')->addHour()->greaterThan($start)
                    && CarbonImmutable::parse($hour['time'])->setTimezone('Europe/Berlin')->lessThan($end))->values();
                $rain = $during->first(fn (array $hour): bool => (float) ($hour['precip'] ?? 0) > self::RAIN_MM);
                $storm = $during->first(fn (array $hour): bool => in_array($hour['icon'] ?? null, ['thunderstorm', 'hail'], true));
                $cold = $during->first(fn (array $hour): bool => is_numeric($hour['temperature'] ?? null) && $hour['temperature'] < self::FREEZE_BELOW);
                $hot = $during->first(fn (array $hour): bool => is_numeric($hour['temperature'] ?? null) && $hour['temperature'] > self::HEAT_ABOVE);
                $hazard = match (true) {
                    $storm !== null => ['Storms forecast from '.$storm['hour'], "Thunderstorms forecast from {$storm['hour']}"],
                    $cold !== null => ['Frost ('.round((float) $cold['temperature']).'°C) forecast', 'Freezing, '.round((float) $cold['temperature']).'°C, forecast from '.$cold['hour']],
                    $hot !== null => ['Heat ('.round((float) $hot['temperature']).'°C) forecast', 'Heat, '.round((float) $hot['temperature']).'°C, forecast from '.$hot['hour']],
                    default => null,
                };
                if ($hazard !== null) {
                    $add('hazard', 'major', "{$hazard[0]} during {$name} ({$span}), outdoors.", "{$hazard[1]}, during \"{$name}\" ({$span}), an outdoor stop in their plan.");
                } elseif ($rain !== null) {
                    $add('rain', 'moderate', "Rain from {$rain['hour']} during {$name} ({$span}), outdoors.", "Rain forecast from {$rain['hour']}, during \"{$name}\" ({$span}), an outdoor stop in their plan.");
                }
            }
        }

        return $risks;
    }

    /** Listed closed that day by its own hours: none that day, or one of its closing dates (OpeningHoursParser). */
    private static function closed(array $slot, CarbonImmutable $day): bool
    {
        $hours = $slot['place_facts']['hours'] ?? [];
        if (($hours['status'] ?? null) !== 'known' || ! is_array($hours['parsed'] ?? null)) {
            return false;
        }

        return ($hours['parsed'][strtolower($day->format('D'))] ?? []) === [] || OpeningHoursParser::closedOn($hours['raw'] ?? null, $day);
    }

    /** A stop's name as both KVB's stops and its notices say it ("Köln Bocklemünd", "Bocklemünd"; "Straße", "Str."). */
    private static function stopKey(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = (string) preg_replace(['/^köln\s+/u', '/straße\b/u', '/[^\p{L}\p{N}]+/u'], ['', 'str', ''], $name);

        return $name;
    }

    /** A lookup that fails says nothing rather than stopping the rest. */
    private static function quietly(callable $lookup, mixed $otherwise): mixed
    {
        try {
            return $lookup();
        } catch (Throwable) {
            return $otherwise;
        }
    }
}
