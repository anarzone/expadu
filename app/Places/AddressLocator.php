<?php

namespace App\Places;

use App\Services\GeocodingService;

/**
 * A point for an address someone typed without picking a suggestion. Only a
 * result that is what they typed counts: the same street (and house number,
 * when they gave one), or a place of exactly that name, in their postcode or
 * city, or else in the app's city. Anything less finds nothing; a point is
 * never guessed.
 */
final class AddressLocator
{
    /** Photon's kinds that name an area or a street, not a building. */
    private const AREAS = ['street', 'locality', 'district', 'city', 'county'];

    public function __construct(private readonly GeocodingService $geocoding) {}

    /** @return array{lat: float, lng: float}|null */
    public function locate(string $address): ?array
    {
        $typed = self::normalise($address);

        if (mb_strlen($typed) < 3) {
            return null;
        }

        preg_match('/\b(\d{5})\b/', $typed, $postcode);
        $postcode = $postcode[1] ?? null;
        $rest = trim(preg_replace('/\b\d{5}\b/', ' ', $typed));
        preg_match('/\b(\d{1,4}\s?[a-z]?)\b/', $rest, $house);
        $house = isset($house[1]) ? str_replace(' ', '', $house[1]) : null;
        $cities = array_map(self::normalise(...), (array) config('geocoding.search_default.aliases', []));

        foreach ($this->geocoding->search($address) as $result) {
            $city = self::normalise((string) ($result['city'] ?? ''));
            $inPlace = $postcode !== null
                ? ($result['postcode'] ?? null) === $postcode
                : ($city !== '' && (str_contains($typed, $city) || in_array($city, $cities, true)));

            if (! $inPlace) {
                continue;
            }

            $street = self::normalise((string) ($result['street_name'] ?? ''));
            $number = str_replace(' ', '', self::normalise((string) ($result['housenumber'] ?? '')));
            // A street without a number is the street, never a house on it.
            $sameStreet = $street !== '' && str_contains($typed, $street)
                && ($house === null ? $number === '' : $number === $house);
            // An area or street by its name ("Ehrenfeld"), never a shop of that name.
            $named = in_array($result['kind'] ?? null, self::AREAS, true)
                && trim(preg_replace('/\s+/', ' ', str_replace([$city, ...$cities], ' ', $rest))) === self::normalise((string) $result['name']);

            if ($sameStreet || $named) {
                return ['lat' => (float) $result['lat'], 'lng' => (float) $result['lng']];
            }
        }

        return null;
    }

    /** Lower case, German letters spelled out, "Straße" as "str", no punctuation. */
    public static function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $text = preg_replace('/\bstr(asse|\.)?(?=\s|$|,|\d)/u', 'str', $text);
        $text = preg_replace('/(?<=\w)strasse\b/u', 'str', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
