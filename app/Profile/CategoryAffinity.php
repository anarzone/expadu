<?php

namespace App\Profile;

use App\Enums\Situation;

/**
 * Deterministic situation→category affinity — the home feed's lead ranking
 * signal and the composer's kid-friendliness source. No ML: the user's
 * situation (plus a couple of life-event attributes and how recently they
 * arrived) decides which kinds of places matter, so a family, a student and a
 * freelancer see genuinely different feeds even before any behaviour is
 * learned. Shared by DiscoveryFeed (home) and PlanScorer (composer).
 */
class CategoryAffinity
{
    /** Days after arrival a user still counts as "just arrived". */
    public const NEW_ARRIVAL_DAYS = 30;

    public const KID_FRIENDLY = ['playground', 'park', 'swimming', 'lake', 'pitch', 'basketball', 'skatepark', 'dog_park', 'library', 'viewpoint', 'bbq', 'museum', 'gallery', 'zoo'];

    public const KID_UNFRIENDLY = ['bar', 'coworking', 'cafe'];

    /** Where a day WITH the kids actually leads — stronger than merely kid-compatible. */
    public const KID_STRONG = ['playground', 'zoo', 'park', 'swimming', 'lake'];

    /** Landmarks worth knowing when you've just arrived. */
    public const LANDMARK = ['attraction', 'viewpoint', 'museum', 'park', 'zoo'];

    private const ACTIVE = ['park', 'playground', 'pitch', 'basketball', 'tennis', 'skatepark', 'swimming', 'lake', 'dog_park', 'table_tennis', 'boules', 'bbq', 'viewpoint'];

    private const CULTURE = ['museum', 'gallery', 'attraction', 'zoo', 'library'];

    private const WORK = ['coworking', 'cafe', 'library'];

    private const SOCIAL = ['bar', 'cafe'];

    /** A category the user explicitly picked outranks one that merely fits their situation. */
    private const STRONG = 1.5;

    private const BOOST = 1.0;

    private const NEUTRAL = 0.5;

    private const DEMOTE = 0.15;

    public static function hasKids(Profile $profile): bool
    {
        return $profile->situation === Situation::FamilyReunification
            || ! empty($profile->attributes['child_born_at']);
    }

    public static function isNewArrival(Profile $profile): bool
    {
        return ($profile->daysSinceArrival() ?? PHP_INT_MAX) <= self::NEW_ARRIVAL_DAYS;
    }

    /**
     * Per-category affinity for this profile (category => weight). Categories
     * not present default to NEUTRAL via score().
     *
     * @return array<string, float>
     */
    public static function map(Profile $profile): array
    {
        $map = [];

        if (self::hasKids($profile)) {
            foreach (self::KID_FRIENDLY as $category) {
                $map[$category] = self::BOOST;
            }
            foreach (self::KID_UNFRIENDLY as $category) {
                $map[$category] = self::DEMOTE;
            }
        } else {
            $boosted = match ($profile->situation) {
                Situation::Student => [...self::ACTIVE, ...self::CULTURE, ...self::SOCIAL],
                Situation::Freelancer, Situation::DigitalNomad => [...self::WORK, ...self::CULTURE],
                Situation::NonEuEmployee, Situation::EuEmployee => [...self::ACTIVE, ...self::CULTURE],
                default => [],
            };
            foreach ($boosted as $category) {
                $map[$category] = self::BOOST;
            }
        }

        if (self::isNewArrival($profile)) {
            foreach (self::LANDMARK as $category) {
                $map[$category] = self::BOOST;
            }
        }

        // Explicit interests (user-picked) are the strongest cold-start signal:
        // they outrank a merely situation-fit category, so picking "Museums"
        // surfaces museums above the parks a settled employee also gets. Never
        // lifts a category we've demoted for kid-safety.
        foreach (Interest::categoriesFor($profile->interests) as $category) {
            if (($map[$category] ?? self::NEUTRAL) > self::DEMOTE) {
                $map[$category] = self::STRONG;
            }
        }

        return $map;
    }

    /**
     * An explicit "with the kids" day overrides whatever the situation implied:
     * playgrounds/zoos/parks lead, kid-compatible stays fine, kid-unfriendly is
     * floored. Applied on top of map() when the composer's companions=kids —
     * the PROFILE may have no children, but today's plan does.
     *
     * @param  array<string, float>  $map
     * @return array<string, float>
     */
    public static function withKids(array $map): array
    {
        foreach (self::KID_FRIENDLY as $category) {
            $map[$category] = max($map[$category] ?? self::NEUTRAL, self::BOOST);
        }
        foreach (self::KID_STRONG as $category) {
            $map[$category] = max($map[$category] ?? self::NEUTRAL, self::STRONG);
        }
        foreach (self::KID_UNFRIENDLY as $category) {
            $map[$category] = self::DEMOTE;
        }

        return $map;
    }

    /**
     * @param  array<string, float>  $map
     */
    public static function score(string $category, array $map): float
    {
        return $map[$category] ?? self::NEUTRAL;
    }
}
