<?php

namespace App\Models;

use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'provider', 'provider_asset_id', 'source_key', 'remote_url', 'source_page_url', 'author', 'attribution', 'license_code', 'license_url', 'mime_type', 'width', 'height', 'checksum', 'rights_status', 'health_status', 'failure_count', 'last_error', 'last_seen_at', 'last_verified_at', 'metadata', 'next_validation_at', 'validation_queued_at', 'validation_queued_fingerprint', 'last_validation_outcome', 'last_validation_error_code'])]
class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'image',
        'rights_status' => 'pending',
        'health_status' => 'pending',
        'failure_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'failure_count' => 'integer',
            'last_seen_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'next_validation_at' => 'datetime',
            'validation_queued_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @param Builder<MediaAsset> $query */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('rights_status', 'approved')
            ->where('health_status', 'active')
            ->whereNotNull('attribution')
            ->whereRaw('btrim(media_assets.attribution) <> ?', [''])
            ->whereNotNull('source_page_url')
            ->whereRaw('btrim(media_assets.source_page_url) <> ?', ['']);
    }

    public function isPublished(): bool
    {
        return $this->rights_status === 'approved'
            && $this->health_status === 'active'
            && is_string($this->attribution)
            && trim($this->attribution) !== ''
            && is_string($this->source_page_url)
            && trim($this->source_page_url) !== '';
    }

    /** @return HasMany<MediaAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(MediaAttachment::class);
    }

    /** @return HasMany<MediaValidationAttempt, $this> */
    public function validationAttempts(): HasMany
    {
        return $this->hasMany(MediaValidationAttempt::class);
    }
}
