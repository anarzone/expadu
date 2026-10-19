<?php

namespace App\Media;

use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\MediaMatchReview;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReviewMediaMatch
{
    private const DECISIONS = ['accepted', 'rejected'];

    /** @return array<string, mixed> */
    public function preview(int $attachmentId, string $decision, string $method = 'manual_review'): array
    {
        $decision = $this->decision($decision);
        $method = $this->method($method);
        $attachment = MediaAttachment::query()
            ->with(['mediaAsset', 'mediable'])
            ->findOrFail($attachmentId);
        $snapshot = $this->snapshot($attachment, $decision, $method);

        return [
            'attachment_id' => $attachment->id,
            'decision' => $decision,
            'match_method' => $method,
            'fingerprint' => hash('sha256', json_encode(
                $snapshot,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )),
            'snapshot' => $snapshot,
        ];
    }

    public function apply(
        int $attachmentId,
        string $decision,
        string $method,
        string $fingerprint,
        string $evidence,
        string $actor,
    ): void {
        $decision = $this->decision($decision);
        $method = $this->method($method);
        $evidence = trim($evidence);
        $actor = trim($actor);
        if (mb_strlen($evidence) < 20) {
            throw new DomainException('Document the subject-match evidence before applying a media decision.');
        }
        if ($actor === '' || mb_strlen($actor) > 191) {
            throw new DomainException('A review actor is required and limited to 191 characters.');
        }
        if (! preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
            throw new DomainException('Use the exact fingerprint from a reviewed media-match preview.');
        }

        $initial = $this->preview($attachmentId, $decision, $method);

        DB::transaction(function () use ($attachmentId, $decision, $method, $fingerprint, $evidence, $actor, $initial): void {
            $attachment = MediaAttachment::query()->whereKey($attachmentId)->lockForUpdate()->firstOrFail();
            MediaAsset::query()->whereKey($attachment->media_asset_id)->lockForUpdate()->firstOrFail();
            $owner = $attachment->mediable()->lockForUpdate()->firstOrFail();

            $current = $this->preview($attachmentId, $decision, $method);
            if ($initial['attachment_id'] !== $current['attachment_id'] || ! hash_equals($fingerprint, $current['fingerprint'])) {
                throw new DomainException('The media match changed after preview; review a fresh preview.');
            }

            $previousStatus = $attachment->match_status;
            $reviewedAt = now()->utc();
            $matchEvidence = [
                'evidence' => $evidence,
                'reviewer' => $actor,
                'fingerprint' => $fingerprint,
                'owner_type' => $owner::class,
                'owner_id' => $owner->getKey(),
                'source_page_url' => $current['snapshot']['asset']['source_page_url'],
            ];

            $attachment->update([
                'match_status' => $decision,
                'match_method' => $method,
                'match_evidence' => $matchEvidence,
                'match_reviewed_at' => $reviewedAt,
            ]);

            MediaMatchReview::query()->create([
                'media_attachment_id' => $attachment->id,
                'previous_status' => $previousStatus,
                'new_status' => $decision,
                'match_method' => $method,
                'evidence' => $evidence,
                'reviewer' => $actor,
                'fingerprint' => $fingerprint,
                'snapshot' => $current['snapshot'],
                'created_at' => $reviewedAt,
            ]);
        });
    }

    private function decision(string $decision): string
    {
        $decision = trim(mb_strtolower($decision));
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new DomainException('Media match decisions must be accepted or rejected.');
        }

        return $decision;
    }

    private function method(string $method): string
    {
        $method = trim(mb_strtolower($method));
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{1,79}$/D', $method)) {
            throw new DomainException('Media match methods must be 2 to 80 lowercase letters, numbers, underscores or hyphens.');
        }

        return $method;
    }

    /** @return array<string, mixed> */
    private function snapshot(MediaAttachment $attachment, string $decision, string $method): array
    {
        $asset = $attachment->mediaAsset;
        $owner = $attachment->mediable;
        if (! $asset instanceof MediaAsset || ! $owner instanceof Model) {
            throw new DomainException('Media match review requires an existing asset and owner.');
        }

        $rawOwner = $owner->getAttributes();
        $ownerIdentity = [
            'type' => $owner::class,
            'id' => $owner->getKey(),
        ];
        foreach (['name', 'title', 'canonical_spot_id', 'source', 'source_id', 'source_uid', 'venue_id', 'location_name', 'address', 'lat', 'lng'] as $field) {
            if (array_key_exists($field, $rawOwner)) {
                $ownerIdentity[$field] = $rawOwner[$field];
            }
        }

        return [
            'attachment' => [
                'id' => $attachment->id,
                'media_asset_id' => $attachment->media_asset_id,
                'mediable_type' => $attachment->mediable_type,
                'mediable_id' => $attachment->mediable_id,
                'role' => $attachment->role,
                'current_match_status' => $attachment->match_status,
                'current_match_method' => $attachment->match_method,
                'current_match_evidence' => $attachment->match_evidence,
                'current_match_reviewed_at' => $attachment->match_reviewed_at?->toAtomString(),
            ],
            'asset' => [
                'id' => $asset->id,
                'provider' => $asset->provider,
                'provider_asset_id' => $asset->provider_asset_id,
                'source_key' => $asset->source_key,
                'remote_url' => $asset->remote_url,
                'source_page_url' => $asset->source_page_url,
                'author' => $asset->author,
                'attribution' => $asset->attribution,
                'license_code' => $asset->license_code,
                'license_url' => $asset->license_url,
                'rights_status' => $asset->rights_status,
                'health_status' => $asset->health_status,
                'checksum' => $asset->checksum,
            ],
            'owner' => $ownerIdentity,
            'decision' => $decision,
            'match_method' => $method,
        ];
    }
}
