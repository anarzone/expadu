<?php

namespace App\Models;

use App\Enums\NoiseLevel;
use App\Enums\SpotCategory;
use Database\Factories\SpotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
}
