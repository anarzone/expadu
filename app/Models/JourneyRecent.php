<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A recently used journey endpoint. Upserted on every planned journey and
 * offered back as a default suggestion (before the user types) in the
 * Departures journey search — the Google-Maps "recents" pattern.
 */
class JourneyRecent extends Model
{
    protected $fillable = [
        'user_id', 'role', 'name', 'area', 'lat', 'lng', 'times_used', 'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'last_used_at' => 'datetime',
        ];
    }

    /** Upsert one use of an endpoint, bumping recency + frequency. */
    public static function record(int $userId, string $role, string $name, float $lat, float $lng, ?string $area = null): void
    {
        $name = trim($name);

        if ($name === '' || strtolower($name) === 'your location') {
            return;
        }

        $existing = static::query()
            ->where('user_id', $userId)
            ->where('role', $role)
            ->where('name', $name)
            ->first();

        if ($existing) {
            $existing->update([
                'lat' => $lat,
                'lng' => $lng,
                'area' => $area ?? $existing->area,
                'times_used' => $existing->times_used + 1,
                'last_used_at' => now(),
            ]);

            return;
        }

        static::create([
            'user_id' => $userId,
            'role' => $role,
            'name' => $name,
            'area' => $area,
            'lat' => $lat,
            'lng' => $lng,
            'last_used_at' => now(),
        ]);

        // Keep the tail short — recents are a convenience, not history.
        static::query()
            ->where('user_id', $userId)
            ->where('role', $role)
            ->orderByDesc('last_used_at')
            ->skip(15)
            ->take(50)
            ->pluck('id')
            ->whenNotEmpty(fn ($ids) => static::whereIn('id', $ids)->delete());
    }
}
