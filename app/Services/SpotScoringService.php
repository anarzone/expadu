<?php

namespace App\Services;

use App\Models\Spot;
use App\Models\SpotCheckin;
use App\Models\User;
use App\Models\UserEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Scores spots for a user using weighted factors:
 * distance, category preference, familiarity, novelty, quality, weather fit.
 *
 * Returns spots sorted by score with contextual recommendation text.
 */
class SpotScoringService
{
    /**
     * Score and rank spots for a user.
     *
     * @param  Collection<int, Spot>  $spots
     * @return Collection<int, Spot> Spots with `score` and `reason` attributes
     */
    public function scoreSpots(User $user, Collection $spots, float $lat, float $lng, array $weather): Collection
    {
        if ($spots->isEmpty()) {
            return $spots;
        }

        $preferences = $this->getUserPreferences($user);
        $visitCounts = $this->getVisitCounts($user, $spots->pluck('id')->all());
        $topFamiliar = $this->getTopFamiliarSpotName($user);
        $isRaining = ($weather['precipitation'] ?? 0) > 0.5
            || in_array($weather['icon'] ?? '', ['rain', 'thunderstorm', 'sleet'], true);
        $temp = $weather['temperature'] ?? 15;

        return $spots->map(function (Spot $spot) use ($lat, $lng, $preferences, $visitCounts, $topFamiliar, $weather, $isRaining, $temp) {
            $dist = $this->haversineMeters($lat, $lng, (float) $spot->lat, (float) $spot->lng);
            $category = $spot->category?->value ?? 'cafe';
            $visits = $visitCounts[$spot->id] ?? 0;

            $distScore = max(0, 100 - ($dist / 20));
            $prefScore = ($preferences[$category] ?? 0) * 100;
            $familiarScore = $this->familiarityScore($visits);
            $noveltyScore = $this->noveltyScore($visits, $prefScore);
            $qualityScore = $this->qualityScore($spot);
            $weatherFit = $this->weatherFitScore($category, $isRaining, $temp);

            $score = (int) round(
                $distScore * 0.25
                + $prefScore * 0.25
                + $familiarScore * 0.20
                + $noveltyScore * 0.15
                + $qualityScore * 0.10
                + $weatherFit * 0.05
            );

            $reason = $this->generateReason($spot, $dist, $visits, $noveltyScore, $weather, $topFamiliar);

            $spot->setAttribute('score', $score);
            $spot->setAttribute('reason', $reason);
            $spot->setAttribute('visit_count', $visits);
            $spot->setAttribute('distance_m', (int) round($dist));

            return $spot;
        })->sortByDesc('score')->values();
    }

    /**
     * Build user category preferences from behavior data (last 30 days).
     *
     * @return array<string, float> Category → preference weight (0.0 to 1.0)
     */
    public function getUserPreferences(User $user): array
    {
        return Cache::remember("spot_preferences:{$user->id}", 3600, function () use ($user) {
            $since = now()->subDays(30);
            $weights = [];

            // Spot views (weight: 1)
            $viewed = UserEvent::where('user_id', $user->id)
                ->where('event_type', 'spot_viewed')
                ->where('created_at', '>=', $since)
                ->get()
                ->map(fn ($e) => $e->payload['spot_id'] ?? null)
                ->filter();

            foreach ($viewed as $spotId) {
                $cat = Spot::find($spotId)?->category?->value;
                if ($cat) {
                    $weights[$cat] = ($weights[$cat] ?? 0) + 1;
                }
            }

            // Proximity detections (weight: 0.5)
            $proximity = UserEvent::where('user_id', $user->id)
                ->where('event_type', 'spot_proximity')
                ->where('created_at', '>=', $since)
                ->get()
                ->map(fn ($e) => $e->payload['spot_id'] ?? null)
                ->filter();

            foreach ($proximity as $spotId) {
                $cat = Spot::find($spotId)?->category?->value;
                if ($cat) {
                    $weights[$cat] = ($weights[$cat] ?? 0) + 0.5;
                }
            }

            // Check-ins (weight: 3)
            $checkins = SpotCheckin::where('user_id', $user->id)
                ->where('created_at', '>=', $since)
                ->with('spot:id,category')
                ->get();

            foreach ($checkins as $checkin) {
                $cat = $checkin->spot?->category?->value;
                if ($cat) {
                    $weights[$cat] = ($weights[$cat] ?? 0) + 3;
                }
            }

            $total = array_sum($weights) ?: 1;

            // Normalize to 0-1, ensure every category has at least a base score
            $categories = ['cafe', 'library', 'park', 'coworking'];
            $result = [];
            foreach ($categories as $cat) {
                $result[$cat] = max(0.1, ($weights[$cat] ?? 0) / $total);
            }

            return $result;
        });
    }

    /**
     * Get visit counts per spot for a user.
     *
     * @param  int[]  $spotIds
     * @return array<int, int> spot_id → visit count
     */
    private function getVisitCounts(User $user, array $spotIds): array
    {
        $counts = [];

        // Check-ins
        $checkinCounts = SpotCheckin::where('user_id', $user->id)
            ->whereIn('spot_id', $spotIds)
            ->selectRaw('spot_id, count(*) as cnt')
            ->groupBy('spot_id')
            ->pluck('cnt', 'spot_id')
            ->all();

        foreach ($checkinCounts as $id => $cnt) {
            $counts[$id] = ($counts[$id] ?? 0) + $cnt;
        }

        // Spot views
        $viewedIds = UserEvent::where('user_id', $user->id)
            ->where('event_type', 'spot_viewed')
            ->where('created_at', '>=', now()->subDays(30))
            ->get()
            ->map(fn ($e) => $e->payload['spot_id'] ?? null)
            ->filter()
            ->countBy();

        foreach ($viewedIds as $id => $cnt) {
            if (in_array($id, $spotIds)) {
                $counts[$id] = ($counts[$id] ?? 0) + $cnt;
            }
        }

        return $counts;
    }

    private function getTopFamiliarSpotName(User $user): ?string
    {
        $topCheckin = SpotCheckin::where('user_id', $user->id)
            ->selectRaw('spot_id, count(*) as cnt')
            ->groupBy('spot_id')
            ->orderByDesc('cnt')
            ->first();

        return $topCheckin?->spot?->name;
    }

    private function familiarityScore(int $visits): float
    {
        return match (true) {
            $visits >= 5 => 100,
            $visits >= 3 => 70,
            $visits >= 2 => 50,
            $visits >= 1 => 30,
            default => 0,
        };
    }

    private function noveltyScore(int $visits, float $prefScore): float
    {
        if ($visits > 0) {
            return 0;
        }

        return $prefScore > 30 ? 80 : 40;
    }

    private function qualityScore(Spot $spot): float
    {
        $rating = $spot->rating;
        $base = match (true) {
            $rating === null => 50,
            $rating >= 4.5 => 90,
            $rating >= 4.0 => 75,
            $rating >= 3.5 => 60,
            $rating >= 3.0 => 40,
            default => 30,
        };

        if ($spot->wifi_speed === 'fast') {
            $base = min(100, $base + 10);
        }

        return $base;
    }

    private function weatherFitScore(string $category, bool $isRaining, int $temp): float
    {
        if ($category === 'park') {
            if ($isRaining) {
                return 10;
            }

            return $temp >= 10 ? 100 : 50;
        }

        // Indoor spots score higher when weather is bad
        if ($isRaining || $temp < 5) {
            return 90;
        }

        return 70;
    }

    private function generateReason(Spot $spot, float $distMeters, int $visits, float $noveltyScore, array $weather, ?string $topFamiliar): string
    {
        $distLabel = $distMeters < 1000
            ? round($distMeters / 10) * 10 .'m'
            : round($distMeters / 100) / 10 .'km';

        $category = $spot->category?->value ?? 'spot';
        $categoryLabel = ucfirst($category);
        $isRaining = ($weather['precipitation'] ?? 0) > 0.5
            || in_array($weather['icon'] ?? '', ['rain', 'thunderstorm'], true);

        // Familiar spots
        if ($visits >= 5) {
            return "Your regular · {$distLabel}";
        }
        if ($visits >= 2) {
            return "You've been here {$visits} times · {$distLabel}";
        }
        if ($visits === 1) {
            return "Visited before · {$distLabel}";
        }

        // New spots
        $parts = [];

        if ($spot->rating && $spot->rating >= 4.5) {
            $parts[] = "Highly rated {$spot->rating}★";
        }

        if ($topFamiliar && $category === 'cafe') {
            $parts[] = 'New café to try';
        } elseif ($category === 'library') {
            $parts[] = 'Quiet workspace';
        } elseif ($category === 'coworking') {
            $parts[] = 'Try coworking';
        } elseif ($category === 'park' && ! $isRaining) {
            $parts[] = 'Get some fresh air';
        } else {
            $parts[] = "Discover this {$categoryLabel}";
        }

        if ($isRaining && in_array($category, ['cafe', 'library', 'coworking'])) {
            $parts[] = 'Perfect for rainy days';
        }

        if ($spot->wifi_speed === 'fast') {
            $parts[] = 'Fast WiFi';
        }

        $parts[] = $distLabel;

        return implode(' · ', $parts);
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
