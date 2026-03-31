<?php

namespace App\Services;

use App\Models\Spot;
use App\Models\User;
use App\Models\UserEvent;
use Illuminate\Support\Facades\Cache;

/**
 * Analyzes user tracking data to identify frequent destinations.
 * Used by RecommendationService to suggest personalized routes.
 */
class FrequentDestinationService
{
    /**
     * Get the user's most frequent destinations from tracking data.
     *
     * @return array<int, array{name: string, visits: int, lat: float|null, lng: float|null, type: string}>
     */
    public function getFrequentDestinations(User $user, int $days = 30, int $limit = 5): array
    {
        return Cache::remember(
            "frequent_destinations_{$user->id}_{$days}",
            3600,
            fn () => $this->compute($user, $days, $limit)
        );
    }

    /**
     * Get the single most visited destination (excluding Home/Work).
     *
     * @return array{name: string, visits: int, lat: float|null, lng: float|null, type: string}|null
     */
    public function getTopDestination(User $user): ?array
    {
        $destinations = $this->getFrequentDestinations($user);

        // Exclude Home/Work + places not active today
        $homeName = $user->places()->where('category', 'home')->value('name');
        $workName = $user->places()->where('category', 'work')->value('name');

        // Build list of place names that are inactive today
        $inactiveNames = $user->places()
            ->get()
            ->filter(fn ($p) => ! $p->isActiveToday())
            ->pluck('name')
            ->map(fn ($n) => mb_strtolower($n))
            ->all();

        foreach ($destinations as $dest) {
            $name = mb_strtolower($dest['name']);
            if ($homeName && str_contains($name, mb_strtolower($homeName))) {
                continue;
            }
            if ($workName && str_contains($name, mb_strtolower($workName))) {
                continue;
            }
            // Skip places not active today
            foreach ($inactiveNames as $inactive) {
                if (str_contains($name, $inactive) || str_contains($inactive, $name)) {
                    continue 2;
                }
            }

            return $dest;
        }

        return null;
    }

    /**
     * Check if user has enough tracking history for personalized recommendations.
     */
    public function hasHistory(User $user): bool
    {
        return UserEvent::where('user_id', $user->id)
            ->whereIn('event_type', ['journey_planned', 'spot_checkin', 'departure_viewed'])
            ->where('created_at', '>=', now()->subDays(30))
            ->exists();
    }

    /**
     * @return array<int, array{name: string, visits: int, lat: float|null, lng: float|null, type: string}>
     */
    private function compute(User $user, int $days, int $limit): array
    {
        $since = now()->subDays($days);
        $destinations = [];

        // Build exclusion list — Home/Work addresses and names
        $excludeNames = [];
        foreach ($user->places()->whereIn('category', ['home', 'work'])->get() as $place) {
            $excludeNames[] = mb_strtolower($place->name);
            if ($place->address) {
                $excludeNames[] = mb_strtolower($place->address);
                // Also exclude partial address matches
                foreach (explode(',', $place->address) as $part) {
                    $part = trim($part);
                    if (mb_strlen($part) > 3) {
                        $excludeNames[] = mb_strtolower($part);
                    }
                }
            }
        }

        // 1. Journey planned destinations — most valuable signal
        $journeys = UserEvent::where('user_id', $user->id)
            ->where('event_type', 'journey_planned')
            ->where('created_at', '>=', $since)
            ->get(['payload']);

        foreach ($journeys as $event) {
            $to = $event->payload['to'] ?? null;
            if (! $to) {
                continue;
            }
            $key = mb_strtolower(trim($to));
            if ($this->isExcluded($key, $excludeNames)) {
                continue;
            }
            $destinations[$key] ??= ['name' => trim($to), 'visits' => 0, 'lat' => null, 'lng' => null, 'type' => 'journey'];
            $destinations[$key]['visits'] += 2;
        }

        // 2. Spot check-ins — physical presence
        $checkins = UserEvent::where('user_id', $user->id)
            ->where('event_type', 'spot_checkin')
            ->where('created_at', '>=', $since)
            ->get(['payload']);

        foreach ($checkins as $event) {
            $spotId = $event->payload['spot_id'] ?? null;
            if (! $spotId) {
                continue;
            }
            $spot = Spot::find($spotId);
            if (! $spot) {
                continue;
            }
            $key = mb_strtolower($spot->name);
            if ($this->isExcluded($key, $excludeNames)) {
                continue;
            }
            $destinations[$key] ??= ['name' => $spot->name, 'visits' => 0, 'lat' => $spot->lat, 'lng' => $spot->lng, 'type' => 'spot'];
            $destinations[$key]['visits'] += 3;
        }

        // NOTE: departure_viewed and page_viewed are excluded — they indicate browsing, not physical visits

        // Classify each destination as 'routine' or 'favourite' based on temporal patterns
        $this->classifyDestinations($user, $destinations, $since);

        usort($destinations, fn ($a, $b) => $b['visits'] <=> $a['visits']);

        return array_slice(array_values($destinations), 0, $limit);
    }

    /**
     * Analyze temporal clustering to classify destinations.
     * Routine: visits cluster on ≤3 DOW + ≤2 hours with 3+ occurrences per slot.
     * Favourite: scattered across days/hours.
     *
     * @param  array<string, array>  $destinations
     */
    private function classifyDestinations(User $user, array &$destinations, \DateTimeInterface $since): void
    {
        // Get all visit events with timestamps for temporal analysis
        $events = UserEvent::where('user_id', $user->id)
            ->whereIn('event_type', ['journey_planned', 'spot_checkin', 'spot_proximity'])
            ->where('created_at', '>=', $since)
            ->get(['event_type', 'payload', 'created_at']);

        // Build temporal profile per destination: which DOW + hour combinations
        $profiles = [];
        foreach ($events as $event) {
            $name = null;
            if ($event->event_type === 'journey_planned') {
                $name = $event->payload['to'] ?? null;
            } elseif (in_array($event->event_type, ['spot_checkin', 'spot_proximity'], true)) {
                $spotId = $event->payload['spot_id'] ?? null;
                if ($spotId) {
                    $spot = Spot::find($spotId);
                    $name = $spot?->name;
                }
            }

            if (! $name) {
                continue;
            }

            $key = mb_strtolower(trim($name));
            if (! isset($destinations[$key])) {
                continue;
            }

            $dow = (int) $event->created_at->dayOfWeek; // 0=Sun, 6=Sat
            $hour = $event->created_at->hour;
            $slot = "{$dow}_{$hour}";

            $profiles[$key] ??= ['dow' => [], 'hours' => [], 'slots' => []];
            $profiles[$key]['dow'][$dow] = ($profiles[$key]['dow'][$dow] ?? 0) + 1;
            $profiles[$key]['hours'][$hour] = ($profiles[$key]['hours'][$hour] ?? 0) + 1;
            $profiles[$key]['slots'][$slot] = ($profiles[$key]['slots'][$slot] ?? 0) + 1;
        }

        // Classify each destination
        foreach ($destinations as $key => &$dest) {
            $profile = $profiles[$key] ?? null;

            if (! $profile || empty($profile['slots'])) {
                $dest['classification'] = 'favourite';
                $dest['routine_days'] = null;
                $dest['routine_hour'] = null;

                continue;
            }

            $uniqueDow = count($profile['dow']);
            $uniqueHours = count($profile['hours']);
            $maxSlotFreq = max($profile['slots']);

            // Routine: few specific days, few specific hours, high frequency per slot
            if ($uniqueDow <= 3 && $uniqueHours <= 2 && $maxSlotFreq >= 3) {
                $dest['classification'] = 'routine';
                // Store the dominant DOW numbers and hour
                arsort($profile['dow']);
                arsort($profile['hours']);
                $dest['routine_days'] = array_slice(array_keys($profile['dow']), 0, 3);
                $dest['routine_hour'] = (int) array_key_first($profile['hours']);
            } else {
                $dest['classification'] = 'favourite';
                $dest['routine_days'] = null;
                $dest['routine_hour'] = null;
            }
        }
    }

    /**
     * Check if a destination name matches any excluded address/name.
     *
     * @param  string[]  $excludeNames
     */
    private function isExcluded(string $key, array $excludeNames): bool
    {
        foreach ($excludeNames as $excluded) {
            if (str_contains($key, $excluded) || str_contains($excluded, $key)) {
                return true;
            }
        }

        return false;
    }
}
