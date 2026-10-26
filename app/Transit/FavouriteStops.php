<?php

namespace App\Transit;

use App\Models\FavouriteStop;
use App\Services\KvbApiService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Travel's ★ favourite stops: only a real KVB station, at most LIMIT of them, each the
 * account's own. Travel's star and Composer's save_stop both star through here.
 */
final class FavouriteStops
{
    public function __construct(private KvbApiService $kvb) {}

    /** When KVB's stop list can't be read, a stop can't be checked: said so, never "not in KVB's list". */
    public const UNREADABLE = 'KVB’s stop list can’t be read right now. Try again in a minute.';

    /** A part of a name shorter than this ("str") matches no stop: too many would fit. */
    public const MIN_PART = 4;

    /** How many stations a question offers. */
    private const OFFERED = 5;

    /**
     * Stars a station; true when it wasn't starred before.
     *
     * @throws ValidationException
     */
    public function add(int $userId, string $name): bool
    {
        if ($this->kvb->stationPoints($name) === []) {
            throw ValidationException::withMessages(['name' => $this->kvb->getStops() === [] ? self::UNREADABLE : 'That stop isn’t in KVB’s list.']);
        }
        if ($this->has($userId, $name)) {
            return false;
        }
        if (FavouriteStop::query()->where('user_id', $userId)->count() >= FavouriteStop::LIMIT) {
            throw ValidationException::withMessages(['name' => 'You have '.FavouriteStop::LIMIT.' favourite stops. Remove one to add another.']);
        }
        FavouriteStop::query()->firstOrCreate(['user_id' => $userId, 'name' => $name]);

        return true;
    }

    public function remove(int $userId, string $name): void
    {
        FavouriteStop::query()->where('user_id', $userId)->where('name', $name)->delete();
    }

    public function has(int $userId, string $name): bool
    {
        return FavouriteStop::query()->where('user_id', $userId)->where('name', $name)->exists();
    }

    /**
     * The KVB stations a name means: the one written that way ("neumarkt", "Muelheim Wiener
     * Platz"), else the only one starting or, failing that, holding it ("Ebertpl"). Several
     * that fit are all returned (the shortest first, a few), so the person is asked which,
     * never one guessed; a part shorter than MIN_PART matches nothing.
     *
     * @return list<string>
     */
    public function stations(string $said): array
    {
        $wanted = self::key($said);
        if ($wanted === '') {
            return [];
        }
        $names = collect($this->kvb->getStops())->pluck('name')->unique()->values();
        $exact = $names->filter(fn (string $name): bool => self::key($name) === $wanted)->values();
        if ($exact->isNotEmpty()) {
            return $exact->take(1)->all();
        }
        if (strlen($wanted) < self::MIN_PART) {
            return [];
        }
        foreach (['str_starts_with', 'str_contains'] as $fits) {
            $found = $names->filter(fn (string $name): bool => $fits(self::key($name), $wanted))
                ->sortBy(fn (string $name): int => strlen($name))->values();
            if ($found->isNotEmpty()) {
                return $found->take(self::OFFERED)->all();
            }
        }

        return [];
    }

    /**
     * Where a station is: the middle of its KVB points.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function point(string $station): ?array
    {
        $points = $this->kvb->stationPoints($station);

        return $points === [] ? null : ['lat' => array_sum(array_column($points, 'lat')) / count($points), 'lng' => array_sum(array_column($points, 'lng')) / count($points)];
    }

    /** Whether KVB's stop list can be read at all. */
    public function readable(): bool
    {
        return $this->kvb->getStops() !== [];
    }

    /** A name as compared: ASCII, lower case, letters and digits, "ue"/"oe"/"ae" as "u"/"o"/"a" ("Muelheim" is Mülheim). */
    private static function key(string $name): string
    {
        $key = (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(Str::ascii($name)));

        return str_replace(['ue', 'oe', 'ae'], ['u', 'o', 'a'], $key);
    }
}
