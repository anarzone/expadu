<?php

namespace App\Profile;

/**
 * Explicit, user-picked interests (chosen at onboarding) — a strong cold-start
 * personalisation signal that doesn't need any learned behaviour. Each maps to
 * the spot categories it boosts; shared by the home feed and the composer via
 * CategoryAffinity, so picking "Museums & galleries" lifts museums in both.
 *
 * Users pick between MIN_SELECT and MAX_SELECT of these at onboarding.
 */
enum Interest: string
{
    case Parks = 'parks';
    case Swimming = 'swimming';
    case Sports = 'sports';
    case Museums = 'museums';
    case Sights = 'sights';
    case Family = 'family';
    case Cafes = 'cafes';
    case Nightlife = 'nightlife';
    case LiveMusic = 'live_music';
    case Coworking = 'coworking';
    case Libraries = 'libraries';
    case Dogs = 'dogs';

    /** Onboarding asks for at least this many, and no more than the max. */
    public const MIN_SELECT = 3;

    public const MAX_SELECT = 6;

    /**
     * @return list<string> spot categories this interest boosts
     */
    public function categories(): array
    {
        return match ($this) {
            self::Parks => ['park', 'viewpoint', 'bbq'],
            self::Swimming => ['swimming', 'lake'],
            self::Sports => ['pitch', 'basketball', 'tennis', 'skatepark', 'boules', 'table_tennis'],
            self::Museums => ['museum', 'gallery'],
            self::Sights => ['attraction', 'viewpoint'],
            self::Family => ['playground', 'zoo'],
            self::Cafes => ['cafe', 'bakery'],
            self::Nightlife => ['bar'],
            self::LiveMusic => ['attraction', 'bar'],
            self::Coworking => ['coworking'],
            self::Libraries => ['library'],
            self::Dogs => ['dog_park'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Parks => '🌳 Parks & green',
            self::Swimming => '🏊 Swimming & lakes',
            self::Sports => '⚽ Sports & courts',
            self::Museums => '🎨 Museums & galleries',
            self::Sights => '🏛️ Sights & landmarks',
            self::Family => '🛝 Family & kids',
            self::Cafes => '☕ Cafés & bakeries',
            self::Nightlife => '🍻 Bars & nightlife',
            self::LiveMusic => '🎶 Live music',
            self::Coworking => '💻 Coworking',
            self::Libraries => '📚 Libraries',
            self::Dogs => '🐕 Dog-friendly',
        };
    }

    /**
     * Boosted categories for a set of interest keys (unknown keys ignored).
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function categoriesFor(array $keys): array
    {
        $categories = [];
        foreach ($keys as $key) {
            $interest = self::tryFrom((string) $key);
            if ($interest !== null) {
                $categories = [...$categories, ...$interest->categories()];
            }
        }

        return array_values(array_unique($categories));
    }
}
