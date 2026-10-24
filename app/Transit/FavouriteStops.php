<?php

namespace App\Transit;

use App\Models\FavouriteStop;
use App\Services\KvbApiService;
use Illuminate\Validation\ValidationException;

/**
 * Travel's ★ favourite stops: only a real KVB station, at most LIMIT of them, each the
 * account's own. Travel's star and Composer's save_stop both star through here.
 */
final class FavouriteStops
{
    public function __construct(private KvbApiService $kvb) {}

    /**
     * Stars a station; true when it wasn't starred before.
     *
     * @throws ValidationException
     */
    public function add(int $userId, string $name): bool
    {
        if ($this->kvb->stationPoints($name) === []) {
            throw ValidationException::withMessages(['name' => 'That stop isn’t in KVB’s list.']);
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
     * The KVB station a name means: as KVB writes it, else the closest spelling
     * ("neumarkt" is Neumarkt, "Ebertpl" is Ebertplatz).
     */
    public function station(string $said): ?string
    {
        $key = fn (string $name): string => preg_replace('/[^a-z0-9]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name));
        $wanted = $key($said);
        $names = collect($this->kvb->getStops())->pluck('name')->unique();

        return $names->first(fn (string $name): bool => $key($name) === $wanted)
            ?? $names->filter(fn (string $name): bool => $wanted !== '' && str_starts_with($key($name), $wanted))->sortBy(fn (string $name): int => strlen($name))->first()
            ?? $names->filter(fn (string $name): bool => $wanted !== '' && str_contains($key($name), $wanted))->sortBy(fn (string $name): int => strlen($name))->first();
    }
}
