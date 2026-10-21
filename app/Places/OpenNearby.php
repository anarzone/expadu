<?php

namespace App\Places;

use App\Enums\SpotCategory;
use App\Enums\SpotFeedbackState;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Services\NearbyPlaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Places near a point and what their recorded hours say at a time: Today's
 * "Open near ‹plan› at 14:30" (around(): only the open ones), and Around
 * here's list (listed(): every one, open until, opens at, closed or not
 * listed). Unknown or conflicting hours are never guessed open.
 *
 * Given a person, it leaves out the places they marked "not interested" or
 * "been" (SpotFeedbackState::hiddenFromDiscovery), matched on the canonical
 * place.
 *
 * The point, the time and the person are inputs only: nothing here stores,
 * caches or logs them (they can say where someone will be), so the answer is
 * computed fresh.
 */
final class OpenNearby
{
    /** Kinds of place someone pops into between plans. */
    public const EVERYDAY = ['bakery', 'cafe'];

    public function __construct(
        private NearbyPlaces $nearby,
        private PlaceFacts $facts,
        private DestinationGrouping $grouping,
    ) {}

    /**
     * @param  list<string>  $kinds
     * @return Collection<int, array{id: int, name: string, kind: string, km: float, open_until: string, hours_source: ?string, osm: bool}>
     */
    public function around(float $lat, float $lng, CarbonImmutable $at, array $kinds = self::EVERYDAY, float $radiusKm = 1.0, int $limit = 6, ?User $for = null): Collection
    {
        // A few more than asked, since closed places drop out below.
        [$spots, $facts] = $this->nearest($lat, $lng, $kinds, $radiusKm, $limit * 4, $for);
        $local = $at->setTimezone('Europe/Berlin');

        return $spots
            ->map(function (Spot $spot) use ($facts, $local, $lat, $lng): ?array {
                $fact = $facts->get($spot->id) ?? [];
                $hours = $fact['hours'] ?? null;
                $until = is_array($hours) ? self::openUntil($hours, $local) : null;
                if ($until === null) {
                    return null;
                }
                $source = is_string($hours['source_url'] ?? null) ? $hours['source_url'] : null;

                return [
                    'id' => (int) $spot->id,
                    'name' => (string) ($fact['name']['value'] ?? $spot->name),
                    'kind' => $spot->category instanceof SpotCategory ? $spot->category->value : (string) $spot->category,
                    'km' => round(NearbyPlaces::km($lat, $lng, (float) $spot->lat, (float) $spot->lng), 3),
                    'open_until' => $until,
                    'hours_source' => $source,
                    'osm' => $source !== null && parse_url($source, PHP_URL_HOST) === 'www.openstreetmap.org',
                ];
            })
            ->filter()
            ->take($limit)
            ->values();
    }

    /**
     * Every place of these kinds near a point, nearest first, with what its
     * recorded hours say at `$at` (Around here). `hours` is open (until),
     * closed (opens later today, or not again today) or unknown: hours not
     * recorded, or not known for sure, are never read as open or closed. The
     * facts a row shows come with it; a fact not recorded is null.
     *
     * @param  list<string>  $kinds
     * @return Collection<int, array{id: int, name: string, kind: string, veedel: ?string, lat: float, lng: float, km: float, area: bool, hours: array{state: string, until?: string, opens?: string, today?: bool}, wheelchair: ?string, outdoor_seating: ?string, cuisine: ?string, diets: array<string, string>, internet_access: ?string}>
     */
    public function listed(float $lat, float $lng, CarbonImmutable $at, array $kinds, float $radiusKm = 15.0, int $limit = 12, ?User $for = null): Collection
    {
        [$spots, $facts] = $this->nearest($lat, $lng, $kinds, $radiusKm, $limit, $for);
        $local = $at->setTimezone('Europe/Berlin');

        return $spots->map(function (Spot $spot) use ($facts, $local, $lat, $lng): array {
            $fact = $facts->get($spot->id) ?? [];
            $practical = fn (string $key): ?string => ($fact['practical'][$key]['status'] ?? null) === 'known' && is_scalar($fact['practical'][$key]['value'] ?? null)
                ? (string) $fact['practical'][$key]['value']
                : null;
            $point = $fact['location']['map_point'] ?? [];

            return [
                'id' => (int) $spot->id,
                'name' => (string) ($fact['name']['value'] ?? $spot->name),
                'kind' => $spot->category instanceof SpotCategory ? $spot->category->value : (string) $spot->category,
                'veedel' => $spot->veedel,
                'lat' => (float) ($point['lat'] ?? $spot->lat),
                'lng' => (float) ($point['lng'] ?? $spot->lng),
                'km' => round(NearbyPlaces::km($lat, $lng, (float) $spot->lat, (float) $spot->lng), 3),
                // A park or a wood is measured to its middle: "about".
                'area' => ($point['kind'] ?? null) === 'area',
                'hours' => self::hoursAt(is_array($fact['hours'] ?? null) ? $fact['hours'] : [], $local),
                'wheelchair' => $practical('wheelchair'),
                'outdoor_seating' => $practical('outdoor_seating'),
                'cuisine' => $practical('cuisine'),
                'diets' => array_filter([
                    'vegetarian' => $practical('diet:vegetarian'),
                    'vegan' => $practical('diet:vegan'),
                ]),
                'internet_access' => $practical('internet_access'),
            ];
        })->values();
    }

    /**
     * What recorded hours say at `$at` (local time): open until when, closed
     * and when it opens next today, closed for the rest of today, or not
     * known. Only hours known for sure are read.
     *
     * @param  array<string, mixed>  $hours  A place's resolved hours fact (PlaceFacts)
     * @return array{state: string, until?: string, opens?: string, today?: bool}
     */
    public static function hoursAt(array $hours, CarbonImmutable $at): array
    {
        $week = $hours['parsed'] ?? null;
        if (($hours['status'] ?? 'unknown') !== 'known' || ! is_array($week)) {
            return ['state' => 'unknown'];
        }

        $until = self::openUntil($hours, $at);
        if ($until !== null) {
            return ['state' => 'open', 'until' => $until];
        }

        $minute = ((int) $at->format('G') * 60) + (int) $at->format('i');
        $today = is_array($week[strtolower($at->format('D'))] ?? null) ? $week[strtolower($at->format('D'))] : [];
        $later = collect($today)
            ->map(fn (mixed $interval): ?array => self::minutes($interval) === null ? null : [self::minutes($interval)[0], (string) $interval[0]])
            ->filter(fn (?array $start): bool => $start !== null && $start[0] > $minute)
            ->sortBy(fn (array $start): int => $start[0])
            ->first();

        return $later !== null
            ? ['state' => 'closed', 'opens' => $later[1]]
            : ['state' => 'closed', 'today' => $today !== []];
    }

    /**
     * The nearest places of these kinds that may be recommended (and that
     * the person hasn't set aside), and their resolved facts: around() and
     * listed() read the same places.
     *
     * @param  list<string>  $kinds
     * @return array{0: Collection<int, Spot>, 1: Collection<int, array<string, mixed>>}
     */
    private function nearest(float $lat, float $lng, array $kinds, float $radiusKm, int $take, ?User $for): array
    {
        $hidden = $for === null ? [] : SpotFeedback::effectiveForUser($for->id)
            ->filter(fn (SpotFeedback $row): bool => in_array($row->state, SpotFeedbackState::hiddenFromDiscovery(), true))
            ->keys()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $query = $this->facts->publiclyRecommendable($this->grouping->general(Spot::query()))
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereIn('category', $kinds)
            ->when($hidden !== [], fn ($q) => $q->whereNotIn(DB::raw('coalesce(spots.canonical_spot_id, spots.id)'), $hidden));
        $spots = $this->nearby->orderByDistance($this->nearby->withinKm($query, $lat, $lng, $radiusKm), $lat, $lng)
            ->limit($take)
            ->get();

        return [$spots, $this->facts->resolveMany($spots)];
    }

    /**
     * When the place closes, if its recorded hours say it is open at `$at`
     * (local time): the end of the interval holding that minute, including
     * yesterday's that runs past midnight. Null when closed or not known.
     *
     * @param  array<string, mixed>  $hours  A place's resolved hours fact (PlaceFacts)
     */
    public static function openUntil(array $hours, CarbonImmutable $at): ?string
    {
        $week = $hours['parsed'] ?? null;
        if (($hours['status'] ?? 'unknown') !== 'known' || ! is_array($week)) {
            return null;
        }

        $minute = ((int) $at->format('G') * 60) + (int) $at->format('i');
        $today = strtolower($at->format('D'));
        $yesterday = strtolower($at->subDay()->format('D'));
        if (! array_key_exists($today, $week)) {
            return null;
        }

        foreach (is_array($week[$today]) ? $week[$today] : [] as $interval) {
            $bounds = self::minutes($interval);
            if ($bounds === null) {
                continue;
            }
            [$start, $end] = $bounds;
            if (($end > $start && $minute >= $start && $minute < $end) || ($end <= $start && $minute >= $start)) {
                return (string) $interval[1];
            }
        }

        foreach (is_array($week[$yesterday] ?? null) ? $week[$yesterday] : [] as $interval) {
            $bounds = self::minutes($interval);
            if ($bounds !== null && $bounds[1] <= $bounds[0] && $minute < $bounds[1]) {
                return (string) $interval[1];
            }
        }

        return null;
    }

    /** @return array{0: int, 1: int}|null */
    private static function minutes(mixed $interval): ?array
    {
        if (! is_array($interval) || count($interval) < 2) {
            return null;
        }

        $minutes = static function (mixed $time): int {
            [$hour, $minute] = array_pad(array_map('intval', explode(':', (string) $time)), 2, 0);

            return ($hour * 60) + $minute;
        };

        return [$minutes($interval[0]), $minutes($interval[1])];
    }
}
