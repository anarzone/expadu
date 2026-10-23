<?php

namespace App\Places;

/**
 * Practical things people ask a place to have ("café with wifi", "vegan
 * restaurant", "outdoor seating", "Italian"). Each is met only by a listed
 * fact; an unknown never passes, and never reads as "no".
 */
class PlaceAmenities
{
    public const DIETS = ['vegan', 'vegetarian', 'halal', 'kosher', 'gluten_free'];

    /** Features a search may require: wifi, outdoor_seating, wheelchair, diet:<diet>, cuisine:<word>. */
    public static function valid(string $feature): bool
    {
        return in_array($feature, ['wifi', 'outdoor_seating', 'wheelchair'], true)
            || in_array(substr($feature, 5), self::DIETS, true) && str_starts_with($feature, 'diet:')
            || preg_match('/^cuisine:[a-z_]{2,40}$/', $feature) === 1;
    }

    /** @param  array<string, array{value: mixed, status: string}>  $practical  PlaceFacts' practical facts */
    public static function has(array $practical, string $feature): bool
    {
        $value = fn (string $key): ?string => ($practical[$key]['status'] ?? null) === 'known' ? mb_strtolower(trim((string) $practical[$key]['value'])) : null;

        return match (true) {
            $feature === 'wifi' => in_array($value('internet_access'), ['wlan', 'yes', 'wifi'], true),
            $feature === 'outdoor_seating' => ! in_array($value('outdoor_seating'), [null, 'no'], true),
            // Same rule as the step-free need: a verified step-free entrance only.
            $feature === 'wheelchair' => in_array($value('wheelchair'), ['yes', 'designated'], true),
            str_starts_with($feature, 'diet:') => in_array($value($feature), ['yes', 'only'], true),
            str_starts_with($feature, 'cuisine:') => in_array(substr($feature, 8), array_map('trim', explode(';', (string) $value('cuisine'))), true),
            default => false,
        };
    }

    /**
     * What a place lists, as the assistant may say it: "Wi-Fi, outdoor seating,
     * vegan options, Italian". Only listed facts; absent ones are left unsaid.
     *
     * @param  array<string, array{value: mixed, status: string}>  $practical
     * @return list<string>
     */
    public static function listed(array $practical): array
    {
        $diet = fn (string $diet): ?string => match (mb_strtolower((string) ($practical["diet:{$diet}"]['value'] ?? ''))) {
            'only' => str_replace('_', '-', $diet).' only',
            'yes' => str_replace('_', '-', $diet).' options',
            default => null,
        };
        $cuisine = ($practical['cuisine']['status'] ?? null) === 'known'
            ? collect(explode(';', (string) $practical['cuisine']['value']))->map(fn (string $c): string => ucfirst(str_replace('_', ' ', trim($c))))->filter()->take(2)->implode(', ')
            : '';

        return array_values(array_filter([
            $cuisine !== '' ? $cuisine : null,
            self::has($practical, 'wifi') ? 'Wi-Fi' : null,
            self::has($practical, 'outdoor_seating') ? 'outdoor seating' : null,
            ...array_map($diet, self::DIETS),
        ]));
    }
}
