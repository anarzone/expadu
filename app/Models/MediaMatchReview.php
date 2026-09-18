<?php

namespace App\Models;

use Database\Factories\MediaMatchReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['media_attachment_id', 'previous_status', 'new_status', 'match_method', 'evidence', 'reviewer', 'fingerprint', 'snapshot', 'created_at'])]
class MediaMatchReview extends Model
{
    /** @use HasFactory<MediaMatchReviewFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MediaAttachment, $this> */
    public function mediaAttachment(): BelongsTo
    {
        return $this->belongsTo(MediaAttachment::class);
    }
}
