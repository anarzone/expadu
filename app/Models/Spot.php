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

#[Fillable(['name', 'category', 'description', 'address', 'lat', 'lng', 'wifi_speed', 'noise_level', 'time_limit_mins', 'opening_hours', 'rating'])]
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
            'opening_hours' => 'array',
            'rating' => 'float',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    /** @return HasMany<SpotCheckin, $this> */
    public function checkins(): HasMany
    {
        return $this->hasMany(SpotCheckin::class);
    }

    /** @return HasMany<SpotCheckin, $this> */
    public function activeCheckins(): HasMany
    {
        return $this->hasMany(SpotCheckin::class)->whereNull('checked_out_at');
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
     * Order by proximity to given coordinates.
     *
     * @param  Builder<Spot>  $query
     * @return Builder<Spot>
     */
    public function scopeNearby(Builder $query, float $lat, float $lng): Builder
    {
        // Lat-corrected Euclidean distance-squared for proper sorting at ~51°N.
        // 1° lat ≈ 111 km → factor 12321; 1° lng ≈ 70 km → factor 4900.
        // Ordering by dist_sq is equivalent to ordering by true distance.
        return $query->selectRaw(
            '*, POWER(lat - ?, 2) * 12321 + POWER(lng - ?, 2) * 4900 AS dist_sq',
            [$lat, $lng]
        )->orderBy('dist_sq');
    }
}
