<?php

namespace App\Models;

use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'provider', 'provider_asset_id', 'source_key', 'remote_url', 'source_page_url', 'author', 'attribution', 'license_code', 'license_url', 'mime_type', 'width', 'height', 'checksum', 'rights_status', 'health_status', 'failure_count', 'last_error', 'last_seen_at', 'last_verified_at', 'metadata'])]
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
            'metadata' => 'array',
        ];
    }

    /** @param Builder<MediaAsset> $query */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('rights_status', 'approved')
            ->where('health_status', 'active');
    }

    /** @return HasMany<MediaAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(MediaAttachment::class);
    }
}
