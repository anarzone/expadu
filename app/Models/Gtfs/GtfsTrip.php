<?php

namespace App\Models\Gtfs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GtfsTrip extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'trip_id';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction_id' => 'integer',
        ];
    }

    /** @return BelongsTo<GtfsRoute, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(GtfsRoute::class, 'route_id', 'route_id');
    }

    /** @return HasMany<GtfsStopTime, $this> */
    public function stopTimes(): HasMany
    {
        return $this->hasMany(GtfsStopTime::class, 'trip_id', 'trip_id');
    }
}
