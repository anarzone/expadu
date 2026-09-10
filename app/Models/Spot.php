<?php

namespace App\Models;

use App\Enums\NoiseLevel;
use App\Enums\SpotCategory;
use App\Places\PlaceIdentity;
use Database\Factories\SpotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'category', 'cuisine', 'price_range', 'tags', 'description', 'address', 'lat', 'lng', 'veedel', 'park_name', 'parent_spot_id', 'wifi_speed', 'noise_level', 'time_limit_mins', 'opening_hours', 'rating', 'source', 'source_id', 'source_group', 'last_seen_at', 'is_active', 'is_recommendable', 'is_verified', 'phone', 'website', 'tip', 'photo_url', 'photo_attribution'])]
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
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
            'is_recommendable' => 'boolean',
            'canonical_spot_id' => 'integer',
            'destination_spot_id' => 'integer',
            'destination_reviewed_parent_id' => 'integer',
            'destination_reviewed_at' => 'datetime',
        ];
    }

    /** @param Builder<Spot> $query */
    public function scopeRecommendationEligible(Builder $query): Builder
    {
        return $query->canonical()->where('spots.is_active', true)->where('spots.is_recommendable', true);
    }

    /** @param Builder<Spot> $query */
    public function scopeCanonical(Builder $query): Builder
    {
        return $query->whereNull('spots.canonical_spot_id');
    }

    public function delete()
    {
        return DB::transaction(function () {
            self::query()->whereKey($this->getKey())->lockForUpdate()->first();

            return parent::delete();
        });
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $spot = parent::resolveRouteBinding($value, $field);

        return $spot?->canonical_spot_id ? self::query()->find($spot->canonical_spot_id) : $spot;
    }

    /** @return HasMany<Spot, $this> */
    public function identityAliases(): HasMany
    {
        return $this->hasMany(self::class, 'canonical_spot_id');
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** @return Builder<Review> */
    public function effectiveReviews(): Builder
    {
        $ids = DB::table('reviews')
            ->whereIn('spot_id', app(PlaceIdentity::class)->familyIds($this->id))
            ->selectRaw('DISTINCT ON (user_id) id')
            ->orderBy('user_id')->orderByDesc('updated_at')
            ->orderByRaw('case when spot_id = ? then 0 else 1 end', [$this->canonical_spot_id ?? $this->id])
            ->orderByDesc('id');

        return Review::query()->whereIn('id', $ids);
    }

    /** @return BelongsTo<Spot, $this> */
    public function parentSpot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_spot_id');
    }

    /** @return HasMany<Spot, $this> */
    public function containedSpots(): HasMany
    {
        return $this->hasMany(self::class, 'parent_spot_id');
    }

    /** @return MorphMany<MediaAttachment, $this> */
    public function mediaAttachments(): MorphMany
    {
        return $this->morphMany(MediaAttachment::class, 'mediable');
    }

    /** Recalculate average rating from reviews */
    public function updateRating(): void
    {
        $avg = $this->effectiveReviews()->avg('rating');
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
        static::deleting(function (Spot $spot): void {
            if (app(PlaceIdentity::class)->hasProtectedRecords(DB::table('spots')->where('id', $spot->id))) {
                throw new \DomainException('Retained place identities cannot be deleted; deactivate the place instead.');
            }
            $spot->mediaAttachments()->delete();
        });

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
