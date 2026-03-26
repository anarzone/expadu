<?php

namespace App\Models\Gtfs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GtfsStopTime extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stop_sequence' => 'integer',
        ];
    }

    /** @return BelongsTo<GtfsTrip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(GtfsTrip::class, 'trip_id', 'trip_id');
    }

    /** @return BelongsTo<GtfsStop, $this> */
    public function stop(): BelongsTo
    {
        return $this->belongsTo(GtfsStop::class, 'stop_id', 'stop_id');
    }
}
