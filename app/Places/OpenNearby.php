<?php

namespace App\Places;

use App\Enums\SpotCategory;
use App\Models\Spot;
use App\Services\NearbyPlaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everyday places near a point that their recorded hours say are open at a
 * time: Today's "Open near ‹plan› at 14:30", and Around here's (AH1) list.
 * Unknown or conflicting hours are left out, never guessed open.
 *
 * The point and the time are inputs only: nothing here stores, caches or logs
 * them (they can say where someone will be), so the answer is computed fresh.
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
    public function around(float $lat, float $lng, CarbonImmutable $at, array $kinds = self::EVERYDAY, float $radiusKm = 1.0, int $limit = 6): Collection
    {
        $query = $this->facts->publiclyRecommendable($this->grouping->general(Spot::query()))
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereIn('category', $kinds);

        // A few more than asked, since closed places drop out below.
        $spots = $this->nearby->orderByDistance($this->nearby->withinKm($query, $lat, $lng, $radiusKm), $lat, $lng)
            ->limit($limit * 4)
            ->get();
        $facts = $this->facts->resolveMany($spots);
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
