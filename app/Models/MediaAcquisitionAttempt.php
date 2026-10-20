<?php

namespace App\Models;

use Database\Factories\MediaAcquisitionAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['target_type', 'target_id', 'provider', 'strategy', 'input_fingerprint', 'input_snapshot', 'outcome', 'attempted_at', 'next_attempt_at', 'error_code', 'candidate_count', 'selected_asset_ids', 'metadata', 'active_key'])]
class MediaAcquisitionAttempt extends Model
{
    /** @use HasFactory<MediaAcquisitionAttemptFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'input_snapshot' => 'array',
            'attempted_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'candidate_count' => 'integer',
            'selected_asset_ids' => 'array',
            'metadata' => 'array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
