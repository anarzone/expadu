<?php

namespace App\Models;

use Database\Factories\PlaceFactCorrectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['spot_id', 'field', 'value', 'evidence', 'evidence_url', 'actor', 'reviewed_at', 'supersedes_id', 'revoked_at'])]
class PlaceFactCorrection extends Model
{
    /** @use HasFactory<PlaceFactCorrectionFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'reviewed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Spot, $this> */
    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }

    /** @return BelongsTo<PlaceFactCorrection, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }
}
