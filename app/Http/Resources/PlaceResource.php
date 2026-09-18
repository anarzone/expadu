<?php

namespace App\Http\Resources;

use App\Enums\SpotCategory;
use App\Media\PublishedMediaSelector;
use App\Models\Spot;
use App\Places\PlaceFacts;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Places page contract. User-visible facts are resolved from source
 * observations and reviewed corrections; unknown values stay explicit.
 *
 * @mixin Spot
 */
class PlaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fine = $this->categoryEnum();
        $coarse = $fine?->coarse() ?? 'park';
        $mediaSelector = app(PublishedMediaSelector::class);
        $media = $mediaSelector->select($this->resource, 'hero');
        $placeFacts = app(PlaceFacts::class)->resolve($this->resource);
        $mapPoint = $placeFacts['location']['map_point'];
        $entrancePoint = $placeFacts['location']['entrance_point'];
        $routingPoint = $entrancePoint['status'] === 'verified' ? $entrancePoint : $mapPoint;

        return [
            'id' => $this->id,
            'name' => $placeFacts['name']['value'] ?? $this->name,
            'category' => $coarse,
            'fine_label' => $fine?->label(),
            'emoji' => $fine?->emoji(),
            'veedel' => $this->veedel,
            'park' => $this->park_name,
            'lat' => $mapPoint['lat'] !== null ? (float) $mapPoint['lat'] : null,
            'lng' => $mapPoint['lng'] !== null ? (float) $mapPoint['lng'] : null,
            'routing_lat' => $routingPoint['lat'] !== null ? (float) $routingPoint['lat'] : null,
            'routing_lng' => $routingPoint['lng'] !== null ? (float) $routingPoint['lng'] : null,
            'photo_url' => $media?->remote_url,
            'photo_attribution' => $media?->attribution,
            'photo_source_url' => $media?->source_page_url,
            'photo_license_url' => $media?->license_url,
            'distance_min' => $this->travel_min !== null ? (int) $this->travel_min : null,
            'distance_mode' => $this->travel_mode ?? $request->user()?->transport_mode?->value,
            'distance_km' => $this->distance_km !== null ? round((float) $this->distance_km, 1) : null,
            'open_now' => $this->resolveOpenNow($placeFacts['hours']),
            'opening_hours_text' => $placeFacts['hours']['raw'],
            'price_text' => match ($placeFacts['fee']['value']) {
                'free' => 'free',
                'paid' => $this->paidPriceText($placeFacts['fee']),
                default => null,
            },
            'feature_chips' => $this->resolveFeatureChips(),
            'tip' => null,
            'tip_is_generic' => false,
            'cluster_size' => (int) ($this->cluster_size ?? 1),
            'activities' => $this->activities ?? [],
            'transit_hint' => $this->transit_hint ?? null,
            'feedback_state' => $this->feedback_state ?? null,
            'feedback_rating' => $this->feedback_rating ?? null,
            'facts' => $this->resolveFacts(),
            'place_facts' => [
                'name_kind' => $placeFacts['name_kind'],
                'aliases' => $placeFacts['aliases'],
                'location' => $placeFacts['location'],
                'access' => $placeFacts['access'],
                'fee' => $placeFacts['fee'],
                'hours' => $placeFacts['hours'],
                'contact' => $placeFacts['contact'],
                'description' => $placeFacts['description'],
                'negative_facts' => $placeFacts['negative_facts'],
                'conflicts' => $placeFacts['conflicts'],
                'revision' => $placeFacts['revision'],
            ],
        ];
    }

    private function categoryEnum(): ?SpotCategory
    {
        if ($this->category instanceof SpotCategory) {
            return $this->category;
        }

        return SpotCategory::tryFrom((string) $this->category);
    }

    /** @param array<string, mixed> $hours */
    private function resolveOpenNow(array $hours): ?bool
    {
        $week = $hours['parsed'] ?? null;
        if (($hours['status'] ?? 'unknown') !== 'known' || ! is_array($week)) {
            return null;
        }

        $now = CarbonImmutable::now('Europe/Berlin');
        $minute = ((int) $now->format('G') * 60) + (int) $now->format('i');
        $today = strtolower($now->format('D'));
        $yesterday = strtolower($now->subDay()->format('D'));
        if (! array_key_exists($today, $week)) {
            return null;
        }

        foreach (is_array($week[$today]) ? $week[$today] : [] as $interval) {
            $bounds = $this->intervalMinutes($interval);
            if ($bounds === null) {
                continue;
            }
            [$start, $end] = $bounds;
            if (($end > $start && $minute >= $start && $minute < $end)
                || ($end <= $start && $minute >= $start)) {
                return true;
            }
        }

        foreach (is_array($week[$yesterday] ?? null) ? $week[$yesterday] : [] as $interval) {
            $bounds = $this->intervalMinutes($interval);
            if ($bounds === null) {
                continue;
            }
            [$start, $end] = $bounds;
            if ($end <= $start && $minute < $end) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: int, 1: int}|null */
    private function intervalMinutes(mixed $interval): ?array
    {
        if (! is_array($interval) || count($interval) < 2) {
            return null;
        }

        $minutes = static function (mixed $time): int {
            [$hour, $minute] = array_pad(array_map('intval', explode(':', (string) $time)), 2, 0);

            return ($hour * 60) + $minute;
        };

        return [$minutes($interval[0]), $minutes($interval[1])];
    }

    /** @param array<string, mixed> $fee */
    private function paidPriceText(array $fee): string
    {
        if (! is_numeric($fee['amount'] ?? null) || ! is_string($fee['currency'] ?? null)) {
            return 'paid';
        }

        $amount = rtrim(rtrim(number_format((float) $fee['amount'], 2, '.', ''), '0'), '.');

        return mb_strtoupper($fee['currency']) === 'EUR'
            ? '€'.$amount
            : $amount.' '.mb_strtoupper($fee['currency']);
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
