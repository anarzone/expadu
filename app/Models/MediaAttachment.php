<?php

namespace App\Models;

use Database\Factories\MediaAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['media_asset_id', 'mediable_type', 'mediable_id', 'role', 'priority', 'is_primary', 'is_manually_locked', 'match_status', 'match_method', 'match_evidence', 'match_reviewed_at'])]
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
        'match_status' => 'pending',
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
            'match_evidence' => 'array',
            'match_reviewed_at' => 'datetime',
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

    /** @return HasMany<MediaMatchReview, $this> */
    public function matchReviews(): HasMany
    {
        return $this->hasMany(MediaMatchReview::class);
    }

    /** @param Builder<MediaAttachment> $query */
    public function scopePublishable(Builder $query, ?string $provider = null): Builder
    {
        return $query
            ->where('media_attachments.match_status', 'accepted')
            ->whereNotNull('media_attachments.match_method')
            ->whereRaw('btrim(media_attachments.match_method) <> ?', [''])
            ->whereNotNull('media_attachments.match_evidence')
            ->whereRaw("jsonb_typeof(media_attachments.match_evidence) in ('object', 'array')")
            ->whereRaw("media_attachments.match_evidence <> '{}'::jsonb")
            ->whereRaw("media_attachments.match_evidence <> '[]'::jsonb")
            ->whereNotNull('media_attachments.match_reviewed_at')
            ->whereHas('mediaAsset', fn (Builder $asset) => $asset
                ->when($provider !== null, fn (Builder $providerQuery) => $providerQuery->where('provider', $provider))
                ->published());
    }

    public function isPublishable(?MediaAsset $asset = null): bool
    {
        $asset ??= $this->mediaAsset;

        return $this->match_status === 'accepted'
            && is_string($this->match_method)
            && trim($this->match_method) !== ''
            && is_array($this->match_evidence)
            && $this->match_evidence !== []
            && $this->match_reviewed_at !== null
            && $asset?->isPublished() === true;
    }
}
