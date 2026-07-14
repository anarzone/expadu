<?php

namespace App\Media;

use App\Jobs\ValidateMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CaptureMediaCandidate
{
    public function execute(Model $mediable, MediaCandidate $candidate): ?MediaAttachment
    {
        if (! $mediable->exists || ! $this->isValidCandidate($candidate)) {
            return null;
        }

        $sourceIdentity = $candidate->providerAssetId ?: $candidate->remoteUrl;
        $sourceKey = hash('sha256', $candidate->provider.'|'.$sourceIdentity);
        $shouldValidate = false;

        $attachment = DB::transaction(function () use (
            $candidate,
            $mediable,
            $sourceKey,
            &$shouldValidate,
        ): MediaAttachment {
            $mediable->newQuery()->whereKey($mediable->getKey())->lockForUpdate()->first();

            $asset = MediaAsset::query()->firstOrCreate(
                ['source_key' => $sourceKey],
                [
                    'type' => $candidate->type,
                    'provider' => $candidate->provider,
                    'provider_asset_id' => $candidate->providerAssetId,
                    'remote_url' => $candidate->remoteUrl,
                    'last_seen_at' => now(),
                ],
            );
            $isNew = $asset->wasRecentlyCreated;
            $preserveVerifiedEvidence = $asset->exists
                && $asset->rights_status === 'approved'
                && $asset->health_status === 'active'
                && $candidate->rightsStatus === 'pending'
                && $candidate->healthStatus === 'pending'
                && ! $candidate->authoritativeEvidence;
            $remoteUrl = $preserveVerifiedEvidence ? $asset->remote_url : $candidate->remoteUrl;
            $remoteUrlChanged = $asset->exists && $asset->remote_url !== $remoteUrl;
            $sourcePageUrl = $candidate->authoritativeEvidence
                ? $candidate->sourcePageUrl
                : ($candidate->sourcePageUrl ?? $asset->source_page_url);
            $author = $candidate->authoritativeEvidence ? $candidate->author : ($candidate->author ?? $asset->author);
            $attribution = $candidate->authoritativeEvidence
                ? $candidate->attribution
                : ($candidate->attribution ?? $asset->attribution);
            $licenseCode = $candidate->authoritativeEvidence
                ? $candidate->licenseCode
                : ($candidate->licenseCode ?? $asset->license_code);
            $licenseUrl = $candidate->authoritativeEvidence
                ? $candidate->licenseUrl
                : ($candidate->licenseUrl ?? $asset->license_url);

            $asset->fill([
                'type' => $candidate->type,
                'provider' => $candidate->provider,
                'provider_asset_id' => $candidate->providerAssetId,
                'remote_url' => $remoteUrl,
                'source_page_url' => $sourcePageUrl,
                'author' => $author,
                'attribution' => $attribution,
                'license_code' => $licenseCode,
                'license_url' => $licenseUrl,
                'mime_type' => $candidate->mimeType ?? $asset->mime_type,
                'width' => $candidate->width ?? $asset->width,
                'height' => $candidate->height ?? $asset->height,
                'checksum' => $candidate->checksum ?? $asset->checksum,
                'rights_status' => $this->rightsStatus($asset, $candidate),
                'health_status' => $this->healthStatus($asset, $candidate, $remoteUrlChanged),
                'last_seen_at' => now(),
                'metadata' => array_replace($asset->metadata ?? [], $candidate->metadata ?? []),
            ]);

            if ($candidate->healthStatus === 'active') {
                $asset->fill([
                    'failure_count' => 0,
                    'last_error' => null,
                    'last_verified_at' => now(),
                ]);
            }

            $asset->save();

            $attachment = MediaAttachment::query()->firstOrCreate(
                [
                    'media_asset_id' => $asset->id,
                    'mediable_type' => $mediable->getMorphClass(),
                    'mediable_id' => $mediable->getKey(),
                    'role' => $candidate->role,
                ],
                [
                    'priority' => $candidate->priority,
                    'is_primary' => false,
                ],
            );
            $attachmentWasCreated = $attachment->wasRecentlyCreated;

            if ($attachmentWasCreated || ! $attachment->is_manually_locked) {
                $isPrimary = $candidate->isPrimary && ! MediaAttachment::query()
                    ->where('mediable_type', $mediable->getMorphClass())
                    ->where('mediable_id', $mediable->getKey())
                    ->where('role', $candidate->role)
                    ->where('is_primary', true)
                    ->where('is_manually_locked', true)
                    ->whereKeyNot($attachment->getKey())
                    ->exists();

                if ($isPrimary) {
                    MediaAttachment::query()
                        ->where('mediable_type', $mediable->getMorphClass())
                        ->where('mediable_id', $mediable->getKey())
                        ->where('role', $candidate->role)
                        ->where('is_manually_locked', false)
                        ->whereKeyNot($attachment->getKey())
                        ->update(['is_primary' => false]);
                }

                $attachment->fill([
                    'priority' => $candidate->priority,
                    'is_primary' => $isPrimary,
                ]);
            }

            $attachment->save();

            $shouldValidate = $isNew || $remoteUrlChanged || $asset->last_verified_at === null
                || $asset->last_verified_at->lt(now()->subDays(7));

            $attachment->setRelation('mediaAsset', $asset);

            return $attachment;
        });

        $providerHosts = config("media.providers.{$attachment->mediaAsset->provider}.hosts");
        if ($candidate->shouldValidate && $shouldValidate && is_array($providerHosts) && $providerHosts !== []) {
            ValidateMediaAssetJob::dispatch($attachment->mediaAsset)->afterCommit();
        }

        return $attachment;
    }

    private function isValidCandidate(MediaCandidate $candidate): bool
    {
        return $candidate->provider !== ''
            && $candidate->type === 'image'
            && mb_strlen($candidate->remoteUrl) <= 2048
            && filter_var($candidate->remoteUrl, FILTER_VALIDATE_URL) !== false
            && parse_url($candidate->remoteUrl, PHP_URL_SCHEME) === 'https';
    }

    private function rightsStatus(MediaAsset $asset, MediaCandidate $candidate): string
    {
        if ($asset->exists && $candidate->rightsStatus === 'pending' && ! $candidate->authoritativeEvidence) {
            return $asset->rights_status;
        }

        return $candidate->rightsStatus;
    }

    private function healthStatus(MediaAsset $asset, MediaCandidate $candidate, bool $remoteUrlChanged): string
    {
        if ($remoteUrlChanged) {
            return $candidate->healthStatus;
        }

        if ($asset->exists && $candidate->healthStatus === 'pending' && ! $candidate->authoritativeEvidence) {
            return $asset->health_status;
        }

        return $candidate->healthStatus;
    }
}
