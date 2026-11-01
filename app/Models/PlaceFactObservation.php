<?php

namespace App\Models;

use App\Places\PlaceFacts;
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

    /**
     * A place's stored public-access flag is derived from its observations
     * and corrections; recompute it whenever one is written or removed.
     */
    protected static function booted(): void
    {
        $refresh = fn (PlaceFactObservation $observation) => app(PlaceFacts::class)->refreshPublicAccess([
            $observation->spot_id,
            $observation->getOriginal('spot_id'),
        ]);

        static::saved($refresh);
        static::deleted($refresh);
    }

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
