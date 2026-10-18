<?php

namespace App\Places;

use App\Enums\EventCategory;
use App\Enums\SpotCategory;

/**
 * One interest vocabulary across places and events, so a taste learned on
 * one side ("likes culture") can inform the other. Internal to ranking and
 * features — not shown to people. Places map through their coarse bucket
 * (SpotCategory::coarse()); errands (shops, services, health, stays) carry no
 * interest.
 */
class InterestTaxonomy
{
    public const INTERESTS = ['outdoors', 'sport', 'family', 'culture', 'food_drink', 'nightlife', 'social', 'shopping'];

    private const COARSE = [
        'park' => ['outdoors'],
        'swimming' => ['outdoors', 'sport'],
        'dog_park' => ['outdoors'],
        'pitch' => ['sport'],
        'court' => ['sport'],
        'fitness' => ['sport'],
        'playground' => ['family', 'outdoors'],
        'culture' => ['culture'],
        'food_drink' => ['food_drink'],
        'community' => ['social'],
        'shopping' => ['shopping'],
    ];

    /** Fine categories that add an interest on top of their bucket. */
    private const FINE = [
        'bar' => ['nightlife'],
        'zoo' => ['family'],
    ];

    private const EVENTS = [
        'language_exchange' => ['social'],
        'stammtisch' => ['social', 'food_drink'],
        'intl_meetup' => ['social'],
        'sports' => ['sport'],
        'culture' => ['culture'],
        'party' => ['nightlife'],
    ];

    /** @return list<string> */
    public function forSpotCategory(string $category): array
    {
        $coarse = SpotCategory::tryFrom($category)?->coarse();

        return array_values(array_unique([...self::COARSE[$coarse] ?? [], ...self::FINE[$category] ?? []]));
    }

    /** @return list<string> */
    public function forEventCategory(?string $category): array
    {
        return self::EVENTS[EventCategory::fromLegacy($category)->value] ?? [];
    }
}
