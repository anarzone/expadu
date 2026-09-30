<?php

namespace App\Media;

use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Spot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class PublishedMediaSelector
{
    public function hasManagedMedia(Model $mediable): bool
    {
        if ($mediable instanceof Spot) {
            return $this->familyAttachments($mediable)->isNotEmpty();
        }
        if ($mediable->relationLoaded('mediaAttachments')) {
            return $mediable->mediaAttachments->isNotEmpty();
        }

        return $mediable->mediaAttachments()->exists();
    }

    public function select(Model $mediable, ?string $role = null): ?MediaAsset
    {
        if ($mediable instanceof Spot) {
            $attachment = $this->familyAttachments($mediable)
                ->filter(fn (MediaAttachment $attachment): bool => ($role === null || $attachment->role === $role)
                    && $attachment->isPublishable()
                    && ! app(MediaSourcePolicy::class)->excludesAsset($attachment->mediaAsset))
                ->sortBy(fn (MediaAttachment $attachment): string => sprintf('%d-%d-%d-%010d-%010d',
                    $attachment->is_manually_locked ? 0 : 1,
                    $attachment->mediable_id === $mediable->id ? 0 : 1,
                    $attachment->is_primary ? 0 : 1,
                    $attachment->priority,
                    $attachment->id,
                ))->first();

            return $attachment?->mediaAsset;
        }

        $requiresMatchReview = ! ($mediable instanceof Event);

        if ($mediable->relationLoaded('mediaAttachments')) {
            /** @var MediaAttachment|null $attachment */
            $attachment = $mediable->mediaAttachments
                ->filter(fn (MediaAttachment $attachment): bool => $role === null || $attachment->role === $role)
                ->filter(function (MediaAttachment $attachment) use ($requiresMatchReview): bool {
                    $asset = $attachment->relationLoaded('mediaAsset')
                        ? $attachment->mediaAsset
                        : $attachment->mediaAsset()->first();

                    return $requiresMatchReview
                        ? $attachment->isPublishable($asset)
                        : $asset?->isPublished() === true;
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
            ->when(
                $requiresMatchReview,
                fn ($query) => $query->publishable(),
                fn ($query) => $query->whereHas('mediaAsset', fn ($assetQuery) => $assetQuery->published()),
            )
            ->with('mediaAsset')
            ->orderByDesc('is_manually_locked')
            ->orderByDesc('is_primary')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->first(fn (MediaAttachment $candidate): bool => $requiresMatchReview
                ? $candidate->isPublishable()
                : $candidate->mediaAsset?->isPublished() === true);

        return $attachment?->mediaAsset;
    }

    /** @return Collection<int, MediaAttachment> */
    private function familyAttachments(Spot $spot): Collection
    {
        $spot->loadMissing(['mediaAttachments.mediaAsset', 'identityAliases.mediaAttachments.mediaAsset']);

        return $spot->mediaAttachments->concat($spot->identityAliases->flatMap(fn (Spot $alias) => $alias->mediaAttachments));
    }
}
