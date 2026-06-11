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
     * Other places around this one — facilities sharing the same park
     * when there is one ("Also in Blücherpark"), otherwise anything
     * within ~300m. Excludes the place itself and its own same-name
     * cluster siblings (those are the "×N here" chip).
     *
     * @return list<array{id: int, name: string, category: string, emoji: string, walk_min: int, lat: float, lng: float}>
     */
    private function nearby(Spot $spot): array
    {
        $query = Spot::query()
            ->whereKeyNot($spot->id)
            ->where('name', '!=', $spot->name)
            ->whereIn('category', SpotCategory::placesFines())
            ->whereNotNull('lat')
            ->whereNotNull('lng');

        $query->selectRaw(
            '*, (6371 * acos(LEAST(1, cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat))))) as km',
            [$spot->lat, $spot->lng, $spot->lat],
        );

        if ($spot->getRawOriginal('category') === 'park') {
            // A park's "around here" is what's inside it.
            $query->where('park_name', $spot->name);
        } elseif ($spot->park_name) {
            // Same park = same venue, however large the park is.
            $query->where('park_name', $spot->park_name);
        } else {
            $query->whereRaw(
                '(6371 * acos(LEAST(1, cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat))))) <= ?',
                [$spot->lat, $spot->lng, $spot->lat, self::NEARBY_RADIUS_KM],
            );
        }

        $rows = $query
            ->orderBy('km')
            // A park venue gets more room — it's listing its own facilities
            ->limit($spot->getRawOriginal('category') === 'park' ? 8 : self::NEARBY_LIMIT)
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
