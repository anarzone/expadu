<?php

namespace App\Http\Resources;

use App\Enums\SpotCategory;
use App\Models\Spot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Places page contract. Most fields are derived best-effort from
 * OSM-seeded data; missing pieces resolve to friendly category-level
 * defaults so the card never renders an empty/broken row.
 *
 * @mixin Spot
 */
class PlaceResource extends JsonResource
{
    /** Free, publicly accessible coarse categories. */
    private const FREE_OUTDOOR = ['park', 'pitch', 'court', 'playground', 'dog_park'];

    /** Category-level fallback tips — generic guidance, never a factual claim. */
    private const CATEGORY_TIPS = [
        'park' => 'Bring a blanket — the lawns fill up fast on the first sunny afternoon.',
        'pitch' => 'Evenings and weekends get busy — mornings are your best shot at a free pitch.',
        'court' => 'Quietest before 17:00; bring your own ball, nets are often missing.',
        'swimming' => 'Check the day ticket price and lane times before you go — they vary by season.',
        'playground' => 'Most lively late afternoon; shade is limited, so pack a hat in summer.',
        'dog_park' => 'Busiest at dawn and after work — calmer in the early afternoon.',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fine = $this->categoryEnum();
        $coarse = $fine?->coarse() ?? 'park';

        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $coarse,
            'fine_label' => $fine?->label(),
            'emoji' => $fine?->emoji(),
            'veedel' => $this->veedel,
            'park' => $this->park_name,
            'lat' => (float) $this->lat,
            'lng' => (float) $this->lng,
            'photo_url' => $this->photo_url,
            'distance_min' => $this->resolveDistanceMin(),
            'open_now' => $this->resolveOpenNow($coarse),
            'opening_hours_text' => $this->resolveHoursText($coarse),
            'price_text' => $this->resolvePriceText($coarse),
            'feature_chips' => $this->resolveFeatureChips(),
            'tip' => $this->tip ?: (self::CATEGORY_TIPS[$coarse] ?? null),
            'tip_is_generic' => ! $this->tip,
            'cluster_size' => (int) ($this->cluster_size ?? 1),
            'transit_hint' => $this->transit_hint ?? null,
            'facts' => $this->resolveFacts(),
        ];
    }

    private function categoryEnum(): ?SpotCategory
    {
        if ($this->category instanceof SpotCategory) {
            return $this->category;
        }

        return SpotCategory::tryFrom((string) $this->category);
    }

    private function resolveDistanceMin(): ?int
    {
        // distance_km is added as a select alias by the controller query.
        $km = $this->distance_km ?? null;
        if ($km === null) {
            return null;
        }

        // Walking-equivalent minutes at ~4.5 km/h; good enough for "X min away".
        return max(1, (int) round(((float) $km / 4.5) * 60));
    }

    private function resolveOpenNow(string $coarse): ?bool
    {
        // Free public outdoor spots are open-access; pools/indoor vary → unknown.
        return in_array($coarse, self::FREE_OUTDOOR, true) ? true : null;
    }

    private function resolveHoursText(string $coarse): ?string
    {
        $tags = is_array($this->tags) ? $this->tags : [];

        // A real OSM opening_hours string beats the category default
        // (matters for pools and fenced facilities).
        if (! empty($tags['opening_hours']) && $tags['opening_hours'] !== '24/7') {
            return (string) $tags['opening_hours'];
        }

        if (in_array($coarse, self::FREE_OUTDOOR, true)) {
            return 'Open access';
        }

        return null;
    }

    private function resolvePriceText(string $coarse): ?string
    {
        if (in_array($coarse, self::FREE_OUTDOOR, true)) {
            return 'free';
        }

        return match ($this->price_range) {
            '€' => '€',
            '€€' => '€€',
            '€€€' => '€€€',
            default => null,
        };
    }

    /**
     * Feature chips from OSM tags where present.
     *
     * @return list<string>
     */
    private function resolveFeatureChips(): array
    {
        $tags = is_array($this->tags) ? $this->tags : [];
        $chips = [];

        if (($tags['lit'] ?? null) === 'yes') {
            $chips[] = 'floodlit';
        }
        if (($tags['covered'] ?? null) === 'yes' || ($tags['indoor'] ?? null) === 'yes') {
            $chips[] = 'indoor';
        }
        if (($tags['barrier'] ?? null) === 'fence') {
            $chips[] = 'fenced';
        }
        if (($tags['drinking_water'] ?? null) === 'yes') {
            $chips[] = 'water nearby';
        }
        if (($tags['wheelchair'] ?? null) === 'yes') {
            $chips[] = 'wheelchair ok';
        }

        return array_slice($chips, 0, 2);
    }

    /**
     * Fact tiles from OSM tags where present — yes/no tags become
     * Yes/No values, counts and free text pass through.
     *
     * @return list<array{label: string, value: string}>
     */
    private function resolveFacts(): array
    {
        $tags = is_array($this->tags) ? $this->tags : [];
        $facts = [];

        $map = [
            'hoops' => 'hoops',
            'surface' => 'surface',
            'lit' => 'floodlit',
            'covered' => 'covered',
            'wheelchair' => 'wheelchair',
            'drinking_water' => 'water nearby',
        ];

        foreach ($map as $tag => $label) {
            $value = $tags[$tag] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $facts[] = [
                'label' => $label,
                'value' => match ($value) {
                    'yes' => 'Yes',
                    'no' => 'No',
                    default => ucfirst((string) $value),
                },
            ];
        }

        return array_slice($facts, 0, 3);
    }
}
