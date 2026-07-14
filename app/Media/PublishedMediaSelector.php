<?php

namespace App\Media;

use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use Illuminate\Database\Eloquent\Model;

class PublishedMediaSelector
{
    public function hasManagedMedia(Model $mediable): bool
    {
        if ($mediable->relationLoaded('mediaAttachments')) {
            return $mediable->mediaAttachments->isNotEmpty();
        }

        return $mediable->mediaAttachments()->exists();
    }

    public function select(Model $mediable, ?string $role = null): ?MediaAsset
    {
        if ($mediable->relationLoaded('mediaAttachments')) {
            /** @var MediaAttachment|null $attachment */
            $attachment = $mediable->mediaAttachments
                ->filter(fn (MediaAttachment $attachment): bool => $role === null || $attachment->role === $role)
                ->filter(function (MediaAttachment $attachment): bool {
                    $asset = $attachment->relationLoaded('mediaAsset')
                        ? $attachment->mediaAsset
                        : $attachment->mediaAsset()->first();

                    return $asset?->rights_status === 'approved' && $asset->health_status === 'active';
                })
                ->sortBy(fn (MediaAttachment $attachment): string => sprintf(
                    '%d-%d-%010d-%010d',
                    $attachment->is_manually_locked ? 0 : 1,
                    $attachment->is_primary ? 0 : 1,
                    $attachment->priority,
                    $attachment->id,
                ))
                ->first();

            return $attachment?->mediaAsset;
        }

        /** @var MediaAttachment|null $attachment */
        $attachment = $mediable->mediaAttachments()
            ->when($role !== null, fn ($query) => $query->where('role', $role))
            ->whereHas('mediaAsset', fn ($query) => $query->published())
            ->with('mediaAsset')
            ->orderByDesc('is_manually_locked')
            ->orderByDesc('is_primary')
            ->orderBy('priority')
            ->orderBy('id')
            ->first();

        return $attachment?->mediaAsset;
    }
}
