<?php

namespace App\Composer;

/**
 * Leisure appeal by category — the antidote to rating bias. Ratings exist
 * mostly for commercial venues (cafés, restaurants), so ranking by rating
 * buries the actual things-to-do (playgrounds, parks, museums, courts) that
 * have no reviews. This tier list leads with activities and demotes
 * sit-down/work venues, so "what to do in Cologne" stops meaning "coffee".
 * Personal taste still wins via IntentWeights layered on top.
 */
final class CategoryAppeal
{
    /** Things to actively do. */
    public const ACTIVITY = [
        'playground', 'park', 'lake', 'pitch', 'basketball', 'swimming',
        'skatepark', 'tennis', 'table_tennis', 'dog_park', 'bbq', 'boules',
        'viewpoint',
    ];

    /** Places to see / culture. */
    public const CULTURE = ['museum', 'gallery', 'attraction', 'zoo', 'library'];

    /** Sit-down / work venues — fine, but not the lead of a discovery feed. */
    public const LOW = ['cafe', 'restaurant', 'bar', 'coworking'];

    /**
     * 0–1 base appeal for a category in a discovery/leisure context.
     */
    public static function score(string $category): float
    {
        return match (true) {
            in_array($category, self::ACTIVITY, true) => 1.0,
            in_array($category, self::CULTURE, true) => 0.85,
            in_array($category, self::LOW, true) => 0.3,
            default => 0.6,
        };
    }

    public static function isLowAppeal(string $category): bool
    {
        return in_array($category, self::LOW, true);
    }
}
