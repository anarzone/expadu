<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Detects regular location patterns from user tracking data.
 * Pure SQL aggregation — no ML/LLM required.
 *
 * Patterns emerge from journey_planned + spot_checkin + location_ping events
 * grouped by day-of-week and hour. Minimum 2 occurrences = regular pattern.
 */
class LocationPatternService
{
    /**
     * Detect regular destinations grouped by day-of-week + hour.
     *
     * @return array<int, array{destination: string, dow: int, hour: int, frequency: int, lat: float|null, lng: float|null, confidence: string}>
     */
    public function getRegularPatterns(User $user, int $days = 60): array
    {
        return Cache::remember("location_patterns_{$user->id}_{$days}", 3600, function () use ($user, $days) {
            $since = now()->subDays($days)->toDateTimeString();

            // Aggregate journey destinations by day + hour
            // Journey destinations (where user travels TO)
            $journeyPatterns = DB::select("
                SELECT
                    payload->>'to' as destination,
                    EXTRACT(DOW FROM created_at)::int as dow,
                    EXTRACT(HOUR FROM created_at)::int as hour,
                    COUNT(*) as frequency,
                    AVG((payload->>'to_lat')::float) as avg_lat,
                    AVG((payload->>'to_lng')::float) as avg_lng,
                    'journey' as source
                FROM user_events
                WHERE user_id = ?
                    AND event_type = 'journey_planned'
                    AND created_at >= ?
                    AND payload->>'to' IS NOT NULL
                GROUP BY destination, dow, hour
                HAVING COUNT(*) >= 2
                ORDER BY frequency DESC
                LIMIT 20
            ", [$user->id, $since]);

            // Spot visits — checkins, views, and passive GPS proximity
            $checkinPatterns = DB::select("
                SELECT
                    payload->>'spot_name' as destination,
                    EXTRACT(DOW FROM created_at)::int as dow,
                    EXTRACT(HOUR FROM created_at)::int as hour,
                    COUNT(*) as frequency,
                    AVG((payload->>'lat')::float) as avg_lat,
                    AVG((payload->>'lng')::float) as avg_lng,
                    'visit' as source
                FROM user_events
                WHERE user_id = ?
                    AND event_type IN ('spot_checkin', 'spot_viewed', 'spot_proximity')
                    AND created_at >= ?
                    AND payload->>'spot_name' IS NOT NULL
                GROUP BY destination, dow, hour
                HAVING COUNT(*) >= 2
                ORDER BY frequency DESC
                LIMIT 15
            ", [$user->id, $since]);

            // Merge and deduplicate (journey > checkin for same destination+time)
            $all = collect($journeyPatterns)->merge($checkinPatterns)
                ->sortByDesc('frequency')
                ->unique(fn ($p) => "{$p->destination}_{$p->dow}_{$p->hour}")
                ->take(25)
                ->values();

            return $all->map(fn ($p) => [
                'destination' => $p->destination,
                'dow' => $p->dow,
                'hour' => $p->hour,
                'frequency' => $p->frequency,
                'lat' => $p->avg_lat ? round($p->avg_lat, 6) : null,
                'lng' => $p->avg_lng ? round($p->avg_lng, 6) : null,
                'source' => $p->source,
                'confidence' => match (true) {
                    $p->frequency >= 4 => 'high',
                    $p->frequency >= 2 => 'medium',
                    default => 'low',
                },
            ])->all();
        });
    }

    /**
     * Predict where the user is likely going RIGHT NOW based on current day + hour.
     *
     * @return array{destination: string, frequency: int, lat: float|null, lng: float|null, confidence: string}|null
     */
    public function predictCurrentDestination(User $user): ?array
    {
        $patterns = $this->getRegularPatterns($user);

        if (empty($patterns)) {
            return null;
        }

        $now = now();
        $currentDow = (int) $now->dayOfWeek; // 0=Sunday
        $currentHour = $now->hour;

        // Find patterns matching current day + hour (±1 hour tolerance)
        $matches = collect($patterns)->filter(function ($p) use ($currentDow, $currentHour) {
            return $p['dow'] === $currentDow
                && abs($p['hour'] - $currentHour) <= 1;
        })->sortByDesc('frequency');

        return $matches->first();
    }

    /**
     * Get the user's most recent GPS position from location_ping events (last 60 min).
     *
     * @return array{lat: float, lng: float}|null
     */
    public function getLastGps(User $user): ?array
    {
        $ping = UserEvent::where('user_id', $user->id)
            ->where('event_type', 'location_ping')
            ->where('created_at', '>=', now()->subMinutes(60))
            ->orderByDesc('created_at')
            ->first();

        if (! $ping || ! isset($ping->payload['lat'], $ping->payload['lng'])) {
            return null;
        }

        return [
            'lat' => (float) $ping->payload['lat'],
            'lng' => (float) $ping->payload['lng'],
        ];
    }

    /**
     * Detect the user's current location context from GPS pings.
     * Returns which saved place they're nearest to.
     *
     * @return array{place_name: string, place_category: string, distance_meters: float}|null
     */
    public function detectCurrentLocation(User $user): ?array
    {
        // Get most recent location_ping
        $ping = UserEvent::where('user_id', $user->id)
            ->where('event_type', 'location_ping')
            ->where('created_at', '>=', now()->subMinutes(60))
            ->orderByDesc('created_at')
            ->first();

        if (! $ping || ! isset($ping->payload['lat'], $ping->payload['lng'])) {
            return null;
        }

        $lat = (float) $ping->payload['lat'];
        $lng = (float) $ping->payload['lng'];

        // Find nearest saved place
        $places = $user->places()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get();

        $nearest = null;
        $minDist = PHP_FLOAT_MAX;

        foreach ($places as $place) {
            // Simple distance in meters (approximate)
            $dist = $this->haversineMeters($lat, $lng, (float) $place->lat, (float) $place->lng);
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = $place;
            }
        }

        if (! $nearest || $minDist > 2000) { // More than 2km = not at a saved place
            return null;
        }

        return [
            'place_name' => $nearest->name,
            'place_category' => $nearest->category,
            'distance_meters' => round($minDist),
        ];
    }

    /**
     * Detect routines the user hasn't saved yet.
     * A pattern of 3+ journeys to the same destination at the same day+hour.
     *
     * @return array<int, array{destination: string, dow: int, hour: int, frequency: int, suggestion: string}>
     */
    public function detectUnsavedRoutines(User $user): array
    {
        // Skip detection when user has insufficient tracking data
        $eventCount = UserEvent::where('user_id', $user->id)
            ->whereIn('event_type', ['journey_planned', 'spot_checkin', 'spot_proximity'])
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        if ($eventCount < 5) {
            return [];
        }

        $patterns = $this->getRegularPatterns($user);

        // Only suggest for high-frequency patterns
        $suggestions = [];
        $savedRoutines = $user->routines()->pluck('to_stop')->map(fn ($s) => mb_strtolower($s))->all();

        foreach ($patterns as $p) {
            if ($p['frequency'] < 3) {
                continue;
            }

            // Skip if already saved as a routine
            if (in_array(mb_strtolower($p['destination']), $savedRoutines, true)) {
                continue;
            }

            $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            $suggestions[] = [
                ...$p,
                'suggestion' => "You go to {$p['destination']} every {$dayNames[$p['dow']]} at {$p['hour']}:00 — save as routine?",
            ];
        }

        return $suggestions;
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000; // Earth radius in meters
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
