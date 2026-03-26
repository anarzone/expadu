<?php

namespace App\Models\Gtfs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GtfsRoute extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'route_id';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'route_type' => 'integer',
        ];
    }

    /** @return HasMany<GtfsTrip, $this> */
    public function trips(): HasMany
    {
        return $this->hasMany(GtfsTrip::class, 'route_id', 'route_id');
    }
}
