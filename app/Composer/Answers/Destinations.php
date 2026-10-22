<?php

namespace App\Composer\Answers;

use App\Enums\SpotCategory;
use App\Models\Spot;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Place;
use Illuminate\Support\Str;
use Throwable;

/**
 * Where "show me departures to Cafe Nova in City Center" means (owner's staging test,
 * 22 Oct 2026): the place by its name, as Expadu's places list it, else as Travel's own
 * search finds it (RouteService::geocode, the To field's search). An area named with it
 * ("in City Center", "in Ehrenfeld") picks between places of one name; otherwise the
 * nearest comes first. Public places only: nothing here reads the person's own places.
 */
final class Destinations
{
    /** Places offered when the name alone doesn't say which. */
    private const CHOICES = 3;

    /** Words around a place's name that don't tell places apart: the city, and stop and car-park words. */
    private const AROUND = ['koln', 'cologne', 'park', 'ride', 'p', 'r', 'parkplatz', 'parkhaus', 'bf', 'bahnhof', 'haltestelle', 'station'];

    /** Cologne's centre (lat, lng), for keeping Travel's search to the city. */
    private const COLOGNE = [50.9375, 6.9603];

    /** What "the centre" means in Cologne: the old town and the Neustadt, this side of the Rhine. */
    private const CENTRE = ['Altstadt-Nord', 'Altstadt-Süd', 'Neustadt-Nord', 'Neustadt-Süd'];

    private const CENTRE_WORDS = '/^(the )?(city cent(er|re)|cent(er|re)|innenstadt|downtown|zentrum|old town|altstadt|town)$/u';

    public function __construct(private RouteService $routes) {}

    /**
     * The places the name means, best first: one when it's clear, a few to choose from when
     * it isn't, none when nothing by that name is found.
     *
     * @return list<array{name: string, area: ?string, kind: ?string, point: GeoPoint, spot: ?int, source: ?string}>
     */
    public function find(string $named, ?string $area, ?GeoPoint $near): array
    {
        $key = self::key($named);
        if (mb_strlen($key) < 3) {
            return [];
        }
        $veedels = $this->veedels($area);

        // Any of its words narrows the search, accents aside: "cafe nova" finds "Café Nova" too
        // (owner's test, 22 Oct 2026: the Deutz Café Nova was missed, so Composer never asked which).
        $words = array_values(array_filter(explode(' ', $key), fn (string $word): bool => strlen($word) >= 3)) ?: [$key];
        $spots = Spot::query()->canonical()->where('is_active', true)->whereNotNull('lat')->whereNotNull('lng')
            ->where(function ($query) use ($words): void {
                foreach ($words as $word) {
                    $query->orWhereRaw("translate(lower(name), 'áàâäãéèêëíìîïóòôöõúùûüçñß', 'aaaaaeeeeiiiiooooouuuucns') like ?", ['%'.$word.'%']);
                }
            })
            ->limit(300)->get(['id', 'name', 'category', 'veedel', 'lat', 'lng', 'source'])
            ->map(fn (Spot $spot): array => ['name' => $spot->name, 'area' => $spot->veedel,
                'kind' => $spot->category instanceof SpotCategory ? $spot->category->label() : null, 'point' => new GeoPoint($spot->lat, $spot->lng), 'spot' => $spot->id, 'source' => $spot->source]);
        $same = fn (array $place): bool => self::core($place['name']) === self::core($key);

        // The name itself in Expadu's places, else in Travel's search (in Cologne); only then
        // names holding it ("Zoo" in "Zoo Restaurant"), Expadu's first.
        $found = $spots->filter($same);
        if ($found->isEmpty()) {
            $searched = collect($this->searched($named, $near))
                ->filter(fn (array $place): bool => abs($place['point']->lat - self::COLOGNE[0]) < 0.2 && abs($place['point']->lng - self::COLOGNE[1]) < 0.3);
            $found = $searched->filter($same);
            $found = $found->isNotEmpty() ? $found : $spots->filter(fn (array $place): bool => preg_match('/\b'.preg_quote($key, '/').'\b/u', self::key($place['name'])) === 1);
            $found = $found->isNotEmpty() ? $found : $searched->filter(fn (array $place): bool => str_contains(self::tight($place['name']), self::tight($key)));
        }
        if ($veedels !== [] && ($inArea = $found->filter(fn (array $place): bool => in_array($place['area'], $veedels, true)))->isNotEmpty()) {
            $found = $inArea;
        }
        $found = $found->sortBy(fn (array $place): float => $near === null ? 0
            : ($place['point']->lat - $near->lat) ** 2 + (($place['point']->lng - $near->lng) * 0.63) ** 2)->values();

        // Rows of one name within a short walk are one place (an import can hold a café three
        // times; "Köln Weiden West" and "Park&Ride Weiden West" sit side by side), and the stop
        // stands for them, since this is where someone travels to; places further apart are
        // different, and the person says which.
        $distinct = [];
        foreach ($found as $place) {
            foreach ($distinct as $i => $kept) {
                if (self::samePlace($kept, $place)) {
                    if ($place['kind'] === 'Stop' && $kept['kind'] !== 'Stop') {
                        $distinct[$i] = $place;
                    }

                    continue 2;
                }
            }
            $distinct[] = $place;
        }

        return array_slice($distinct, 0, self::CHOICES);
    }

    /** One place twice: the same core name, or one holding the other, within about 300 m. */
    private static function samePlace(array $one, array $two): bool
    {
        $a = self::core($one['name']);
        $b = self::core($two['name']);
        $metres = 111_320 * sqrt(($one['point']->lat - $two['point']->lat) ** 2
            + (($one['point']->lng - $two['point']->lng) * cos(deg2rad($one['point']->lat))) ** 2);

        return $a !== '' && $b !== '' && ($a === $b || str_starts_with($a, $b) || str_starts_with($b, $a)) && $metres <= 300;
    }

    /** A name's core, spaces aside: no city, and none of the stop or car-park words around it ("Köln Weiden West", "P+R Weiden West": "weidenwest"). */
    private static function core(string $name): string
    {
        $words = array_filter(explode(' ', self::key($name)), fn (string $word): bool => ! in_array($word, self::AROUND, true));

        return implode('', $words) ?: self::tight($name);
    }

    /** A name as compared: lower case, plain letters, no "the". */
    public static function key(string $name): string
    {
        return trim((string) preg_replace(['/^the\s+/u', '/[^a-z0-9]+/u'], ['', ' '], mb_strtolower(Str::ascii($name))));
    }

    /** A name as compared, spaces aside ("RheinEnergieStadion" is "Rheinenergie Stadion"). */
    private static function tight(string $name): string
    {
        return str_replace(' ', '', self::key($name));
    }

    /**
     * Travel's own search for a name Expadu's places don't hold.
     *
     * @return list<array{name: string, area: ?string, kind: ?string, point: GeoPoint, spot: null, source: string}>
     */
    private function searched(string $named, ?GeoPoint $near): array
    {
        try {
            $places = $this->routes->withTimeBudget(4.0)->geocode($named, $near);
        } catch (Throwable) {
            return [];
        }

        return array_map(fn (Place $place): array => ['name' => $place->name, 'area' => $place->area !== null ? Str::before($place->area, ' · ') : null,
            'kind' => $place->kind === 'stop' ? 'Stop' : null, 'point' => $place->point, 'spot' => null,
            // Travel's search: stops from the timetables, places and addresses from OpenStreetMap.
            'source' => $place->kind === 'stop' ? 'transit' : 'osm'], $places);
    }

    /** @return list<string> the Veedel an area named with the place means */
    private function veedels(?string $area): array
    {
        if ($area === null || trim($area) === '') {
            return [];
        }
        $area = mb_strtolower(trim($area));
        if (preg_match(self::CENTRE_WORDS, $area)) {
            return self::CENTRE;
        }

        return collect(config('veedels', []))->flatten()->filter(fn (mixed $veedel): bool => is_string($veedel)
            && (self::key($veedel) === self::key($area) || str_starts_with(self::key($veedel), self::key($area).' ')))->values()->all();
    }
}
