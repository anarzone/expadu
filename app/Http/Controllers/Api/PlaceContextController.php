<?php

namespace App\Http\Controllers\Api;

use App\Enums\SpotCategory;
use App\Http\Controllers\Controller;
use App\Models\Spot;
use App\Services\WeatherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/places/{spot}/context — the place detail's live blocks:
 * a weather-fit "right now" line (outdoor categories only) and the
 * "also around here" places within ~300m. Fetched lazily when the
 * detail opens so the list stays cheap.
 */
class PlaceContextController extends Controller
{
    private const NEARBY_RADIUS_KM = 0.3;

    private const NEARBY_LIMIT = 4;

    public function __invoke(Spot $spot, WeatherService $weather): JsonResponse
    {
        return response()->json([
            'now' => $this->nowLine($spot, $weather),
            'nearby' => $this->nearby($spot),
        ]);
    }

    /**
     * @return array{text: string, tone: 'good'|'bad'}|null
     */
    private function nowLine(Spot $spot, WeatherService $weather): ?array
    {
        $category = $spot->category instanceof SpotCategory
            ? $spot->category
            : SpotCategory::tryFrom((string) $spot->category);

        if (! $category?->isOutdoor()) {
            return null;
        }

        $current = Cache::remember(
            'place-context:weather',
            600,
            fn () => $weather->getCurrentWeather(),
        );

        $temp = $current['temperature'] ?? null;
        if ($temp === null) {
            return null;
        }

        $emoji = $current['emoji'] ?? '🌤️';
        $raining = ($current['precipitation'] ?? 0) > 0.2;

        if ($raining) {
            return ['text' => "{$emoji} {$temp}° and wet right now — maybe later today", 'tone' => 'bad'];
        }

        if ($temp <= 5) {
            return ['text' => "{$emoji} {$temp}° and dry — bundle up and it's all yours", 'tone' => 'good'];
        }

        return ['text' => "{$emoji} {$temp}° and dry — a good time to be outside", 'tone' => 'good'];
    }

    /**
     * Other places within ~300m — excludes the place itself and its own
     * same-name cluster siblings (those are the "×N here" chip).
     *
     * @return list<array{id: int, name: string, category: string, emoji: string, walk_min: int, lat: float, lng: float}>
     */
    private function nearby(Spot $spot): array
    {
        $rows = Spot::query()
            ->whereKeyNot($spot->id)
            ->where('name', '!=', $spot->name)
            ->whereIn('category', SpotCategory::placesFines())
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->selectRaw(
                '*, (6371 * acos(LEAST(1, cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat))))) as km',
                [$spot->lat, $spot->lng, $spot->lat],
            )
            ->whereRaw(
                '(6371 * acos(LEAST(1, cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat))))) <= ?',
                [$spot->lat, $spot->lng, $spot->lat, self::NEARBY_RADIUS_KM],
            )
            ->orderBy('km')
            ->limit(self::NEARBY_LIMIT)
            ->get();

        return $rows->map(function (Spot $near) {
            $category = $near->category instanceof SpotCategory
                ? $near->category
                : SpotCategory::tryFrom((string) $near->category);

            return [
                'id' => $near->id,
                'name' => $near->name,
                'category' => $category?->coarse() ?? 'park',
                'emoji' => $category?->emoji() ?? '📍',
                'walk_min' => max(1, (int) round(((float) $near->km) * 1000 / 80)),
                'lat' => (float) $near->lat,
                'lng' => (float) $near->lng,
            ];
        })->all();
    }
}
