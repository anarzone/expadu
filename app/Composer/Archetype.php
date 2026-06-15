<?php

namespace App\Composer;

/**
 * The SHAPE of a day. Instead of greedily filling N best slots, the composer
 * fills an archetype's ordered roles — giving the plan an arc (a main thing, a
 * place to land, a wind-down) rather than a flat list. Deterministic; the user
 * can switch the archetype on the result page. Balanced is the permissive
 * default (any category, fill the window) and reproduces the old greedy fill.
 */
enum Archetype: string
{
    case Balanced = 'balanced';
    case OneMainPlusSupports = 'one_main';
    case ExploreVeedel = 'explore_veedel';
    case ChillNearby = 'chill';
    case MakeADayOfIt = 'make_a_day';
    case Anchored = 'anchored';

    private const HERO = ['museum', 'gallery', 'attraction', 'zoo', 'park', 'viewpoint', 'lake'];

    private const SUPPORT = ['cafe', 'library', 'park', 'bakery'];

    private const CHILL = ['park', 'cafe', 'library', 'playground', 'viewpoint'];

    private const MEAL = ['cafe', 'restaurant', 'bakery', 'fast_food'];

    private const WIND_DOWN = ['viewpoint', 'park', 'lake'];

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        return match ($this) {
            self::Balanced => [new Role([], 6)],
            self::OneMainPlusSupports => [new Role(self::HERO, 1, hero: true), new Role(self::SUPPORT, 1)],
            self::ExploreVeedel => [new Role([], 3)],
            self::ChillNearby => [new Role(self::CHILL, 2)],
            self::MakeADayOfIt => [new Role(self::HERO, 1, hero: true), new Role(self::MEAL, 1), new Role(self::WIND_DOWN, 1)],
            self::Anchored => [new Role(self::SUPPORT, 2)],
        };
    }

    /** Explore-a-Veedel clusters every pick into one neighbourhood. */
    public function singleVeedel(): bool
    {
        return $this === self::ExploreVeedel;
    }

    /** When no archetype is chosen, the vibe toggle implies one. */
    public static function forVibe(?string $vibe): ?self
    {
        return match ($vibe) {
            'chill' => self::ChillNearby,
            'active' => self::MakeADayOfIt,
            default => null,
        };
    }
}
