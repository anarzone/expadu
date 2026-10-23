<?php

namespace App\Places;

/**
 * Most OSM pitches and playgrounds are unnamed, so the importer calls them
 * "Bolzplatz" or "Spielplatz". Two of those in one answer are
 * indistinguishable; add where it is (its park, its street or its Veedel).
 * The source name stays in the name fact.
 */
class PlaceDisplayName
{
    /** Bare labels the importer and OSM mappers give unnamed facilities. */
    public const GENERIC_LABELS = [
        'Spielplatz', 'Bolzplatz', 'Basketballplatz', 'Tennisplatz', 'Tischtennisplatte',
        'Boulebahn', 'Skatepark', 'Hundewiese', 'Hundefreilauffläche', 'Grillplatz',
        'Picknickplatz', 'Sportplatz', 'Fußballplatz', 'Spielwiese',
    ];

    public static function isGeneric(?string $name): bool
    {
        $name = trim((string) $name);

        return $name === '' || in_array(mb_strtolower($name), array_map('mb_strtolower', self::GENERIC_LABELS), true);
    }

    public static function for(?string $name, ?string $parkName, ?string $address, ?string $veedel): string
    {
        $name = trim((string) $name);
        if (! self::isGeneric($name)) {
            return $name;
        }

        $where = collect([trim((string) $parkName), self::street($address), trim((string) $veedel)])
            ->first(fn (string $part): bool => $part !== '' && mb_strtolower($part) !== mb_strtolower($name));

        return $where === null ? $name : "{$name} · {$where}";
    }

    /** "Venloer Straße 12, 50823 Köln" → "Venloer Straße". */
    private static function street(?string $address): string
    {
        $first = trim(explode(',', (string) $address)[0]);

        return trim((string) preg_replace('/\s+\d+\s*[a-z]?(?:\s*[-–]\s*\d+\s*[a-z]?)?$/iu', '', $first));
    }
}
