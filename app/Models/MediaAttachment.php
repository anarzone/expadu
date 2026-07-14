<?php

namespace App\Models;

use Database\Factories\MediaAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['media_asset_id', 'mediable_type', 'mediable_id', 'role', 'priority', 'is_primary', 'is_manually_locked'])]
class MediaAttachment extends Model
{
    /** @use HasFactory<MediaAttachmentFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'role' => 'hero',
        'priority' => 100,
        'is_primary' => false,
        'is_manually_locked' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_primary' => 'boolean',
            'is_manually_locked' => 'boolean',
        ];
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    /** @return MorphTo<Model, $this> */
    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }
}
