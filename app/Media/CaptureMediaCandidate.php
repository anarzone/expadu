<?php

namespace App\Media;

use App\Jobs\ValidateMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Spot;
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
            $preserveValidatedHealth = ! $remoteUrlChanged && $candidate->healthStatus === 'pending'
                && $asset->health_status === 'active' && $this->hasValidatedBytes($asset);
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
                'mime_type' => $preserveValidatedHealth ? $asset->mime_type : ($candidate->mimeType ?? $asset->mime_type),
                'width' => $preserveValidatedHealth ? $asset->width : ($candidate->width ?? $asset->width),
                'height' => $preserveValidatedHealth ? $asset->height : ($candidate->height ?? $asset->height),
                'checksum' => $preserveValidatedHealth ? $asset->checksum : ($candidate->checksum ?? ($remoteUrlChanged || $candidate->authoritativeEvidence ? null : $asset->checksum)),
                'rights_status' => $this->rightsStatus($asset, $candidate),
                'health_status' => $preserveValidatedHealth ? 'active' : $this->healthStatus($asset, $candidate, $remoteUrlChanged),
                'last_seen_at' => now(),
                'metadata' => array_replace($asset->metadata ?? [], $candidate->metadata ?? []),
            ]);

            if ($candidate->healthStatus === 'active') {
                $asset->fill([
                    'failure_count' => 0,
                    'last_error' => null,
                    'last_verified_at' => now(),
                    'next_validation_at' => now()->utc()->addSeconds(max(
                        1,
                        (int) config('media.validation.active_interval_seconds', 604800),
                    )),
                    'last_validation_outcome' => 'active',
                    'last_validation_error_code' => null,
                ]);
            } elseif ($remoteUrlChanged) {
                $asset->fill([
                    'last_verified_at' => null,
                    'next_validation_at' => now()->utc(),
                    'validation_queued_at' => null,
                    'validation_queued_fingerprint' => null,
                    'last_validation_outcome' => 'pending',
                    'last_validation_error_code' => null,
                ]);
            }

            if ($mediable instanceof Spot && app(MediaSourcePolicy::class)->excludesAsset($asset)) {
                $asset->rights_status = 'pending';
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
                    'match_status' => $candidate->matchStatus,
                    'match_method' => $candidate->matchMethod,
                    'match_evidence' => $candidate->matchEvidence,
                    'match_reviewed_at' => $candidate->matchStatus === 'pending' ? null : now()->utc(),
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

            if ($attachmentWasCreated || $attachment->match_status === 'pending') {
                $attachment->fill([
                    'match_status' => $candidate->matchStatus,
                    'match_method' => $candidate->matchMethod,
                    'match_evidence' => $candidate->matchEvidence,
                    'match_reviewed_at' => $candidate->matchStatus === 'pending' ? null : now()->utc(),
                ]);
            }

            $attachment->save();

            $activeInterval = max(1, (int) config('media.validation.active_interval_seconds', 604800));
            $shouldValidate = $isNew || $remoteUrlChanged || $asset->health_status !== 'active' || ! $this->hasValidatedBytes($asset)
                || $asset->next_validation_at?->lte(now())
                || $asset->last_verified_at->lt(now()->subSeconds($activeInterval));

            $attachment->setRelation('mediaAsset', $asset);

            return $attachment;
        });

        // A caller may select again using the same hydrated owner after refresh.
        $mediable->unsetRelation('mediaAttachments');
        if ($mediable instanceof Spot) {
            $mediable->unsetRelation('identityAliases');
        }

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
            && in_array($candidate->matchStatus, ['pending', 'accepted', 'rejected'], true)
            && ($candidate->matchStatus === 'pending'
                || (is_string($candidate->matchMethod)
                    && trim($candidate->matchMethod) !== ''
                    && is_array($candidate->matchEvidence)
                    && $candidate->matchEvidence !== []))
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

    private function hasValidatedBytes(MediaAsset $asset): bool
    {
        return $asset->last_verified_at !== null && in_array($asset->last_validation_outcome, ['active', 'job_failed'], true)
            && is_string($asset->checksum) && preg_match('/^[a-f0-9]{64}$/D', $asset->checksum) === 1;
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
