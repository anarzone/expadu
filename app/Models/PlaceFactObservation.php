<?php

namespace App\Models;

use Database\Factories\PlaceFactObservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['spot_id', 'provider', 'provider_record_id', 'source_url', 'observed_at', 'ingestion_key', 'payload_hash', 'payload', 'record_kind', 'restores_observation_id', 'actor', 'reason'])]
class PlaceFactObservation extends Model
{
    /** @use HasFactory<PlaceFactObservationFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'observed_at' => 'immutable_datetime',
            'payload' => 'array',
            'restores_observation_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Spot, $this> */
    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }
}
