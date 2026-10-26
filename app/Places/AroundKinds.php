<?php

namespace App\Places;

use App\Enums\SpotCategory;

/**
 * The kinds of place Around here offers (prototype/dev/design/around-here/),
 * in the mock's order: what each is called, one of it, and the places it
 * covers. Today's discovery groups the same everyday ones; parks, lakes and
 * woods share "Parks & nature". Kinds combine (Parks & nature with Sports).
 */
final class AroundKinds
{
    /** @var array<string, array{0: string, 1: string, 2: list<SpotCategory>}> */
    private const KINDS = [
        'pharmacy' => ['Pharmacies', 'Pharmacy', [SpotCategory::Pharmacy]],
        'cafe' => ['Cafés', 'Café', [SpotCategory::Cafe]],
        'bakery' => ['Bakeries', 'Bakery', [SpotCategory::Bakery]],
        'grocery' => ['Groceries', 'Groceries', [SpotCategory::Supermarket, SpotCategory::Convenience, SpotCategory::Drugstore, SpotCategory::Greengrocer]],
        'playground' => ['Playgrounds', 'Playground', [SpotCategory::Playground]],
        'green' => ['Parks & nature', 'Green space', [SpotCategory::Park, SpotCategory::Lake, SpotCategory::Nature]],
        // Places to go for sport (Around here direction A, 23 Oct 2026): a
        // park's pitches and courts are listed with their park instead.
        'sports' => ['Sports', 'Sports centre', [SpotCategory::SportsCentre, SpotCategory::FitnessCentre, SpotCategory::Climbing, SpotCategory::DanceStudio]],
        'library' => ['Libraries', 'Library', [SpotCategory::Library]],
        'restaurant' => ['Restaurants', 'Restaurant', [SpotCategory::Restaurant]],
        'fastfood' => ['Fast food', 'Fast food place', [SpotCategory::FastFood]],
        'culture' => ['Culture', 'Culture', [SpotCategory::Museum, SpotCategory::Gallery]],
    ];

    /** @return list<array{id: string, label: string}> */
    public static function chips(): array
    {
        return collect(self::KINDS)->map(fn (array $k, string $id): array => ['id' => $id, 'label' => $k[0]])->values()->all();
    }

    public static function has(string $kind): bool
    {
        return array_key_exists($kind, self::KINDS);
    }

    /**
     * The categories of these kinds (all of them when none).
     *
     * @param  list<string>  $kinds
     * @return list<string>
     */
    public static function categories(array $kinds = []): array
    {
        return collect($kinds === [] ? array_keys(self::KINDS) : $kinds)
            ->flatMap(fn (string $id): array => self::KINDS[$id][2] ?? [])
            ->map(fn (SpotCategory $c): string => $c->value)
            ->values()->all();
    }

    /** The kind a category belongs to, '' when none. */
    public static function of(string $category): string
    {
        $fine = SpotCategory::tryFrom($category);

        return (string) collect(self::KINDS)->search(fn (array $k): bool => in_array($fine, $k[2], true));
    }

    /**
     * Places outside these kinds that Composer still shows: the icon of
     * their family (sport, the open air, culture). Any other keeps the pin.
     *
     * @var array<string, list<SpotCategory>>
     */
    private const FAMILY_ICONS = [
        'sports' => [SpotCategory::Pitch, SpotCategory::Basketball, SpotCategory::Tennis, SpotCategory::Skatepark, SpotCategory::Swimming, SpotCategory::TableTennis, SpotCategory::Boules],
        'park' => [SpotCategory::DogPark, SpotCategory::Bbq, SpotCategory::Picnic, SpotCategory::Viewpoint],
        'culture' => [SpotCategory::Attraction, SpotCategory::Zoo],
    ];

    /** A place's icon: its kind's, a wood keeping its own beside parks; else its family's ('' for the pin). */
    public static function icon(string $category): string
    {
        $kind = self::of($category);

        if ($kind === '') {
            $fine = SpotCategory::tryFrom($category);

            return (string) collect(self::FAMILY_ICONS)->search(fn (array $family): bool => in_array($fine, $family, true));
        }

        return $kind === 'green' ? ($category === SpotCategory::Nature->value ? 'nature' : 'park') : $kind;
    }

    /** Names said the same for one or many ("Fast food", "Swimming"). */
    private const UNCOUNTED = ['Fast food', 'Woods & nature', 'Swimming', 'Table tennis', 'Boules', 'Coworking'];

    /**
     * What the category is called as a group: its kind's ("Cafés", "Parks &
     * nature"), else its own name made plural ("Bars", "Galleries", "Picnic spots").
     */
    public static function group(string $category): string
    {
        $kind = self::KINDS[self::of($category)][0] ?? null;

        if ($kind !== null) {
            return $kind;
        }

        $one = self::label($category);

        return match (true) {
            in_array($one, self::UNCOUNTED, true) => $one,
            (bool) preg_match('/[^aeiou]y$/', $one) => substr($one, 0, -1).'ies',
            (bool) preg_match('/(s|x|ch|sh)$/', $one) => $one.'es',
            default => $one.'s',
        };
    }

    /** What one place of the category is called. */
    public static function label(string $category): string
    {
        return SpotCategory::tryFrom($category)?->label() ?? (self::KINDS[self::of($category)][1] ?? $category);
    }
}
