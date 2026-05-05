<?php

namespace App\ContextEngine;

use App\Models\User;
use App\Models\UserPlace;
use App\Models\UserRouteCache;
use App\Services\VrsTriasService;
use Illuminate\Support\Facades\Cache;

/**
 * Re-plans a saved route excluding lines blocked by an active disruption.
 *
 * Strategy: fetch up to 5 trip alternatives via the existing TRIAS planner,
 * then post-filter locally — independent of whether the prod TRIAS endpoint
 * accepts <LineFilter><Exclude>true</Exclude>. The post-filter path always works.
 */
class AlternativeRoutePlanner
{
    private const MAX_EXTRA_MIN = 15;

    public function __construct(private VrsTriasService $trias) {}

    /**
     * @param  list<string>  $disruptedLines
     * @return array{summary: string, total_min: int, extra_min: int, segments: list<array<string, mixed>>}|null
     */
    public function propose(User $user, UserRouteCache $route, array $disruptedLines): ?array
    {
        if (empty($disruptedLines)) {
            return null;
        }

        $cacheKey = sprintf(
            'alt_route:%d:%d:%d:%s',
            $user->id,
            $route->from_place_id,
            $route->to_place_id,
            md5(implode(',', $disruptedLines))
        );

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($route, $disruptedLines): ?array {
            $from = UserPlace::find($route->from_place_id);
            $to = UserPlace::find($route->to_place_id);
            if (! $from || ! $to) {
                return null;
            }

            $plan = $this->trias->planJourney($from->lat, $from->lng, $to->lat, $to->lng, 5);
            if (! $plan || empty($plan['trips'])) {
                return null;
            }

            $origMin = $this->originalDuration($route, $plan['trips']);

            foreach ($plan['trips'] as $trip) {
                $tripLines = $this->extractLines($trip['segments'] ?? []);
                if (array_intersect($tripLines, $disruptedLines)) {
                    continue; // uses a disrupted line
                }

                $totalMin = (int) ($trip['total_duration_min'] ?? 0);
                $extraMin = $origMin > 0 ? max(0, $totalMin - $origMin) : 0;

                if ($extraMin > self::MAX_EXTRA_MIN) {
                    continue;
                }

                return [
                    'summary' => $this->summarize($tripLines, $totalMin),
                    'total_min' => $totalMin,
                    'extra_min' => $extraMin,
                    'segments' => $trip['segments'] ?? [],
                ];
            }

            return null;
        });
    }

    /** @param  list<array<string, mixed>>  $segments */
    private function extractLines(array $segments): array
    {
        $lines = [];
        foreach ($segments as $seg) {
            if (($seg['type'] ?? null) === 'transit' && ! empty($seg['line'])) {
                $lines[] = (string) $seg['line'];
            }
        }

        return array_values(array_unique($lines));
    }

    /**
     * @param  list<array<string, mixed>>  $trips
     */
    private function originalDuration(UserRouteCache $route, array $trips): int
    {
        $cachedLines = $route->lines ?? [];
        foreach ($trips as $trip) {
            $tripLines = $this->extractLines($trip['segments'] ?? []);
            sort($tripLines);
            $sortedCached = $cachedLines;
            sort($sortedCached);
            if ($tripLines === $sortedCached) {
                return (int) ($trip['total_duration_min'] ?? 0);
            }
        }

        return (int) ($trips[0]['total_duration_min'] ?? 0);
    }

    /** @param  list<string>  $lines */
    private function summarize(array $lines, int $totalMin): string
    {
        if (empty($lines)) {
            return "Walking route, {$totalMin} min";
        }

        return 'Via '.implode(' → ', $lines).", {$totalMin} min";
    }
}
