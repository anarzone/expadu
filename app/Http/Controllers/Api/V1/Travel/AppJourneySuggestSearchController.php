<?php

namespace App\Http\Controllers\Api\V1\Travel;

use App\Composer\TravelEstimator;
use App\Http\Controllers\Api\JourneySuggestSearchController as Web;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Place;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Plan a trip's suggestions: saved places, recents and the geocoder, for the iPhone app's token. Its own class: one URL per Wayfinder action.
 *
 * On a phone the list is short and read at a glance, so it keeps to where the person can ride: same-named
 * places hundreds of kilometres away go when there are near ones, and one place found twice (the stop and
 * the square of one name) shows once.
 */
class AppJourneySuggestSearchController extends Web
{
    /** Farther than this from the person (or the city), a match shows only when nothing nearer does. */
    private const REACH_KM = 50;

    /** About the city around the person: a match this close counts as found. */
    private const CITY_KM = 20;

    /** Two matches of one name this close are one place. */
    private const SAME_PLACE_KM = 0.3;

    /** Endings that already make a German street name whole. */
    private const STREET_ENDINGS = ['str', 'str.', 'strasse', 'straße', 'weg', 'platz', 'allee', 'ring', 'gasse', 'ufer', 'wall', 'damm'];

    /**
     * German streets are one word ("Causemannstraße"), and the geocoder matches whole words, so a name typed
     * without its ending finds nothing in the city. A one-word query that finds nothing of its name in the
     * city is also looked up as a street, those first.
     */
    protected function matches(RouteService $routes, string $query, GeoPoint $bias): Collection
    {
        $found = parent::matches($routes, $query, $bias);
        $word = mb_strtolower($query);

        if (! preg_match('/^\p{L}{4,}$/u', $query) || Str::endsWith($word, self::STREET_ENDINGS)) {
            return $found;
        }

        $distance = app(TravelEstimator::class);
        $nearByName = $found->contains(fn (Place $place) => Str::contains(mb_strtolower($place->name), $word)
            && $distance->haversineKm($bias->lat, $bias->lng, $place->point->lat, $place->point->lng) <= self::CITY_KM);
        if ($nearByName) {
            return $found;
        }

        $streets = collect($routes->geocode($query.'straße', $bias))
            ->filter(fn (Place $place) => Str::startsWith(mb_strtolower($place->name), $word));

        return $streets->concat($found)->values();
    }

    protected function tidy(Collection $matches, GeoPoint $bias): Collection
    {
        $distance = app(TravelEstimator::class);
        $km = fn (GeoPoint $a, GeoPoint $b) => $distance->haversineKm($a->lat, $a->lng, $b->lat, $b->lng);

        $near = $matches->filter(fn (Place $place) => $km($bias, $place->point) <= self::REACH_KM);
        $kept = $near->isNotEmpty() ? $near : $matches->take(3);

        $shown = [];
        foreach ($kept as $place) {
            $name = self::plain($place->name);
            $twin = collect($shown)->contains(fn (Place $other) => self::plain($other->name) === $name && $km($other->point, $place->point) <= self::SAME_PLACE_KM);
            if (! $twin) {
                $shown[] = $place;
            }
        }

        return collect($shown);
    }

    /** "Köln Neumarkt" and "Neumarkt" are one name. */
    private static function plain(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return trim((string) preg_replace('/^(köln|koeln)\s+/u', '', $name));
    }
}
