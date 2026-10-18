<?php

namespace App\Models;

use Database\Factories\MediaValidationAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['media_asset_id', 'input_fingerprint', 'remote_url', 'checksum', 'asset_updated_at', 'outcome', 'error_code', 'started_at', 'finished_at', 'metadata'])]
class MediaValidationAttempt extends Model
{
    /** @use HasFactory<MediaValidationAttemptFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'asset_updated_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
