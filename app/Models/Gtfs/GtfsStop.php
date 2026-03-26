<?php

namespace App\Models\Gtfs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GtfsStop extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'stop_id';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stop_lat' => 'decimal:7',
            'stop_lng' => 'decimal:7',
            'location_type' => 'integer',
        ];
    }

    /** @return BelongsTo<self, $this> */
    public function parentStation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_station', 'stop_id');
    }

    /** @return HasMany<self, $this> */
    public function childStops(): HasMany
    {
        return $this->hasMany(self::class, 'parent_station', 'stop_id');
    }

    /** @return HasMany<GtfsStopTime, $this> */
    public function stopTimes(): HasMany
    {
        return $this->hasMany(GtfsStopTime::class, 'stop_id', 'stop_id');
    }
}
