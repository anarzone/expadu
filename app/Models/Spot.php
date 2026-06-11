<?php

namespace App\Models;

use App\Enums\NoiseLevel;
use App\Enums\SpotCategory;
use Database\Factories\SpotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'category', 'cuisine', 'price_range', 'tags', 'description', 'address', 'lat', 'lng', 'veedel', 'park_name', 'wifi_speed', 'noise_level', 'time_limit_mins', 'opening_hours', 'rating', 'source', 'source_id', 'is_verified', 'phone', 'website', 'tip', 'photo_url'])]
class Spot extends Model
{
    /** @use HasFactory<SpotFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => SpotCategory::class,
            'noise_level' => NoiseLevel::class,
            'tags' => 'array',
            'opening_hours' => 'array',
            'rating' => 'float',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** Recalculate average rating from reviews */
    public function updateRating(): void
    {
        $avg = $this->reviews()->avg('rating');
        $this->update(['rating' => $avg ? round($avg, 2) : null]);
    }

    /**
     * Order by proximity to given coordinates using the PostGIS GIST index.
     *
     * @param  Builder<Spot>  $query
     * @return Builder<Spot>
     */
    public function scopeNearby(Builder $query, float $lat, float $lng): Builder
    {
        return $query->whereNotNull('location')
            ->selectRaw(
                '*, ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS distance_meters',
                [$lng, $lat]
            )
            ->orderByRaw('location <-> ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography', [$lng, $lat]);
    }

    /**
     * Keep the PostGIS `location` geography column in sync with lat/lng on save.
     */
    protected static function booted(): void
    {
        static::saved(function (Spot $spot) {
            $latChanged = $spot->wasChanged('lat') || $spot->wasChanged('lng') || $spot->wasRecentlyCreated;
            if ($latChanged && $spot->lat !== null && $spot->lng !== null) {
                DB::statement(
                    'UPDATE spots SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                    [(float) $spot->lng, (float) $spot->lat, $spot->id]
                );
            }
        });
    }
}
