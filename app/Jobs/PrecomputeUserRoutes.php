<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserPlace;
use App\Models\UserRouteCache;
use App\Services\VrsTriasService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PrecomputeUserRoutes implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public int $userId) {}

    public function handle(VrsTriasService $trias): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $places = UserPlace::where('user_id', $this->userId)->get();
        $home = $places->firstWhere('category', 'home');
        if (! $home) {
            return;
        }

        $pairs = [];
        foreach ($places as $place) {
            if ($place->id === $home->id) {
                continue;
            }
            if ($place->category === 'work' || $place->arrive_by) {
                $pairs[] = [$home, $place];
                $pairs[] = [$place, $home];
            }
        }

        $kept = [];
        foreach ($pairs as [$from, $to]) {
            $row = $this->buildAndUpsert($trias, $from, $to);
            if ($row) {
                $kept[] = $row->id;
            }
        }

        // Drop stale cached pairs for this user that we did not just upsert
        UserRouteCache::where('user_id', $this->userId)
            ->whereNotIn('id', $kept ?: [0])
            ->delete();
    }

    private function buildAndUpsert(VrsTriasService $trias, UserPlace $from, UserPlace $to): ?UserRouteCache
    {
        $plan = $trias->planJourney($from->lat, $from->lng, $to->lat, $to->lng, 1);
        if (! $plan || empty($plan['trips'])) {
            Log::warning('PrecomputeUserRoutes: no journey returned', [
                'user_id' => $this->userId,
                'from' => $from->id,
                'to' => $to->id,
            ]);

            return null;
        }

        $trip = $plan['trips'][0];
        $segments = $trip['segments'] ?? [];

        $lines = [];
        $coords = [];
        foreach ($segments as $seg) {
            if (($seg['type'] ?? null) === 'transit' && ! empty($seg['line'])) {
                $lines[] = $seg['line'];
            }
            foreach (($seg['coordinates'] ?? []) as $c) {
                $coords[] = $c;
            }
        }
        $lines = array_values(array_unique($lines));

        $bbox = $this->boundingBox($coords);
        $window = $this->buildTypicalWindow($to);

        return DB::transaction(function () use ($from, $to, $lines, $bbox, $window) {
            return UserRouteCache::updateOrCreate(
                [
                    'user_id' => $this->userId,
                    'from_place_id' => $from->id,
                    'to_place_id' => $to->id,
                    'mode' => 'transit',
                ],
                [
                    'lines' => $lines,
                    'polyline' => null,
                    'bbox' => $bbox,
                    'typical_window' => $window,
                    'computed_at' => now(),
                ]
            );
        });
    }

    /**
     * @param  list<array{0: float, 1: float}>  $coords
     * @return array{minLat: float, minLng: float, maxLat: float, maxLng: float}|null
     */
    private function boundingBox(array $coords): ?array
    {
        if (empty($coords)) {
            return null;
        }

        $minLat = $maxLat = $coords[0][1];
        $minLng = $maxLng = $coords[0][0];

        foreach ($coords as [$lng, $lat]) {
            $minLat = min($minLat, $lat);
            $maxLat = max($maxLat, $lat);
            $minLng = min($minLng, $lng);
            $maxLng = max($maxLng, $lng);
        }

        // 200m padding so disruptions slightly off the path still match
        $pad = 0.002;

        return [
            'minLat' => $minLat - $pad,
            'minLng' => $minLng - $pad,
            'maxLat' => $maxLat + $pad,
            'maxLng' => $maxLng + $pad,
        ];
    }

    /**
     * Build typical_window from the destination place's arrive_by + day_mode.
     * Window = [arriveHour - 2, arriveHour] for inbound trips.
     *
     * @return array{weekday: list<list<int>>, weekend: list<list<int>>}
     */
    private function buildTypicalWindow(UserPlace $destination): array
    {
        $window = ['weekday' => [], 'weekend' => []];

        if (! $destination->arrive_by) {
            return $window;
        }

        $arriveHour = (int) explode(':', $destination->arrive_by)[0];
        $start = max(0, $arriveHour - 2);
        $range = [$start, $arriveHour];

        $mode = $destination->day_mode ?? 'all';
        if ($mode === 'all') {
            $window['weekday'][] = $range;
            $window['weekend'][] = $range;
        } elseif ($mode === 'weekdays') {
            $window['weekday'][] = $range;
        } elseif ($mode === 'weekends') {
            $window['weekend'][] = $range;
        } elseif ($mode === 'custom') {
            $custom = $destination->active_days ?? [];
            $weekdayKeys = ['mon', 'tue', 'wed', 'thu', 'fri'];
            if (array_intersect($weekdayKeys, $custom)) {
                $window['weekday'][] = $range;
            }
            if (array_intersect(['sat', 'sun'], $custom)) {
                $window['weekend'][] = $range;
            }
        }

        return $window;
    }
}
