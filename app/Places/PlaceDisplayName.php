<?php

namespace App\Places;

use App\Enums\SpotCategory;
use Illuminate\Support\Facades\DB;

/**
 * Most OSM pitches and playgrounds are unnamed, so the importer calls them
 * "Bolzplatz" or "Spielplatz". People never see those labels as a name: a
 * facility gets its English kind and where it is ("Football pitch in
 * Blücherpark", "Tennis court, Venloer Straße", "Playground near Neumarkt").
 * The source name stays in the name fact.
 */
class PlaceDisplayName
{
    /** Bare labels the importer and OSM mappers give unnamed facilities. */
    public const GENERIC_LABELS = [
        'Spielplatz', 'Bolzplatz', 'Basketballplatz', 'Tennisplatz', 'Tischtennisplatte',
        'Boulebahn', 'Skatepark', 'Hundewiese', 'Hundefreilauffläche', 'Grillplatz',
        'Picknickplatz', 'Sportplatz', 'Fußballplatz', 'Spielwiese', 'Tischtennis',
        'Kinderspielplatz', 'Basketball', 'Streetball', 'Beachvolleyball',
    ];

    /** @var array<string, ?string> nearest stop names already looked up in this request */
    private static array $stops = [];

    public static function isGeneric(?string $name): bool
    {
        // The importer also writes "Bolzplatz · Blücherpark" for facilities in a park.
        $name = mb_strtolower(trim((string) preg_replace('/\s*·.*$/u', '', trim((string) $name))));

        return $name === '' || in_array($name, array_map('mb_strtolower', self::GENERIC_LABELS), true);
    }

    /** "Football pitch", "Tennis court", "Playground": what an unnamed facility is, in English. */
    public static function kind(string $category, ?string $sport = null): string
    {
        $sports = array_map('trim', explode(';', mb_strtolower((string) $sport)));

        return match (true) {
            $category === 'pitch' && in_array('soccer', $sports, true) => 'Football pitch',
            $category === 'pitch' && in_array('basketball', $sports, true) => 'Basketball court',
            $category === 'pitch' => 'Sports pitch',
            $category === 'tennis' => 'Tennis court',
            $category === 'table_tennis' => 'Table tennis table',
            $category === 'boules' => 'Boules court',
            default => SpotCategory::tryFrom($category)?->label() ?? ucfirst(str_replace('_', ' ', $category)),
        };
    }

    public static function for(
        ?string $name,
        ?string $parkName,
        ?string $address,
        ?string $veedel,
        string $category = '',
        ?string $sport = null,
        ?float $lat = null,
        ?float $lng = null,
    ): string {
        $name = self::clean((string) $name);
        // City-style names lead with the German kind ("Spielplatz Leyendeckerstraße"):
        // say the kind in English and keep where it is.
        $label = implode('|', array_map(fn (string $l): string => preg_quote($l, '/'), self::GENERIC_LABELS));
        if ($category !== '' && preg_match('/^(?:'.$label.')\s+(?!·)(.+)$/iu', $name, $m) === 1) {
            return self::kind($category, $sport).', '.mb_strtoupper(mb_substr(trim($m[1]), 0, 1)).mb_substr(trim($m[1]), 1);
        }
        if (! self::isGeneric($name)) {
            return $name;
        }

        $kind = $category !== '' ? self::kind($category, $sport) : ($name !== '' ? $name : 'Place');
        $street = self::street($address);
        $stop = $lat !== null && $lng !== null ? self::nearestStop($lat, $lng) : null;

        // "Tischtennisplatte · Hansaplatz": the importer already named where it is.
        $where = str_contains($name, '·') ? trim((string) preg_replace('/^.*?·\s*/u', '', $name)) : '';

        return match (true) {
            trim((string) $parkName) !== '' => "{$kind} in ".trim((string) $parkName),
            $where !== '' => "{$kind}, {$where}",
            $street !== '' => "{$kind}, {$street}",
            $stop !== null => "{$kind} near {$stop}",
            trim((string) $veedel) !== '' => "{$kind} in ".trim((string) $veedel),
            default => $kind,
        };
    }

    /** Import bracket artefacts read as text: "Headquarter[s]" → "Headquarters" (AI session, c7d5e304). */
    public static function clean(string $name): string
    {
        return trim((string) preg_replace('/\[(\w{1,3})\]/u', '$1', $name));
    }

    /** "Venloer Straße 12, 50823 Köln" → "Venloer Straße"; a postcode alone is no street. */
    private static function street(?string $address): string
    {
        $first = trim(explode(',', (string) $address)[0]);
        if ($first === '' || preg_match('/^\d{5}\b/u', $first) === 1) {
            return '';
        }

        return trim((string) preg_replace('/\s+\d+\s*[a-z]?(?:\s*[-–]\s*\d+\s*[a-z]?)?$/iu', '', $first));
    }

    /** The KVB/VRS stop nearest the point, within a short walk ("Neumarkt"). */
    private static function nearestStop(float $lat, float $lng): ?string
    {
        $key = round($lat, 4).','.round($lng, 4);
        if (array_key_exists($key, self::$stops)) {
            return self::$stops[$key];
        }
        // ~500 m box, then the closest inside it (equirectangular is exact enough here).
        $row = DB::table('gtfs_stops')
            ->whereBetween('stop_lat', [$lat - 0.0045, $lat + 0.0045])
            ->whereBetween('stop_lng', [$lng - 0.007, $lng + 0.007])
            ->orderByRaw('power(stop_lat - ?, 2) + power((stop_lng - ?) * 0.63, 2)', [$lat, $lng])
            ->value('stop_name');

        return self::$stops[$key] = $row === null ? null : trim((string) preg_replace('/^(Köln|Koeln)[\s,-]+/u', '', (string) $row));
    }
}
