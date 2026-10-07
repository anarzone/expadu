<?php

namespace App\Media;

use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Spot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlaceMediaCoverageAudit
{
    private const DESTINATION_METRICS = [
        'total',
        'no_candidate',
        'rights_pending',
        'rights_rejected',
        'health_pending',
        'health_broken',
        'relevance_unknown',
        'relevance_rejected',
        'approved_active_selected',
        'manual_selection',
        'legacy_only_url',
        'missing_attribution_or_source',
    ];

    public function __construct(private readonly PublishedMediaSelector $selector) {}

    /** @return array<string, mixed> */
    public function report(int $candidateLimit = 100): array
    {
        $candidateLimit = max(0, min(5000, $candidateLimit));
        $spots = Spot::query()
            ->recommendationEligible()
            ->with(['mediaAttachments.mediaAsset', 'identityAliases.mediaAttachments.mediaAsset'])
            ->orderBy('spots.id')
            ->get();
        $bezirkByVeedel = DB::table('veedels')->pluck('bezirk', 'name');
        $destinations = $this->emptyDestinationMetrics();
        $byCategory = [];
        $byBezirk = [];
        $assets = collect();
        $candidateAudit = [];
        $legacyAudit = [];

        foreach ($spots as $spot) {
            $attachments = $this->familyAttachments($spot);
            $selected = $this->selector->select($spot, 'hero');
            $family = collect([$spot])->concat($spot->identityAliases);
            $legacyOwners = $family->filter(fn (Spot $owner): bool => filled($owner->photo_url));
            $metrics = $this->destinationMetrics($attachments, $selected, $legacyOwners->isNotEmpty());
            $this->addMetrics($destinations, $metrics);

            $category = $spot->category?->value ?? (string) $spot->category;
            $bezirk = (string) ($bezirkByVeedel[$spot->veedel] ?? 'Unknown');
            $byCategory[$category] ??= $this->emptyDestinationMetrics();
            $byBezirk[$bezirk] ??= $this->emptyDestinationMetrics();
            $this->addMetrics($byCategory[$category], $metrics);
            $this->addMetrics($byBezirk[$bezirk], $metrics);

            foreach ($attachments as $attachment) {
                $asset = $attachment->mediaAsset;
                if (! $asset instanceof MediaAsset) {
                    continue;
                }
                $assets->put($asset->id, $asset);
                if (count($candidateAudit) >= $candidateLimit) {
                    continue;
                }
                $candidateAudit[] = [
                    'spot_id' => $spot->id,
                    'attachment_id' => $attachment->id,
                    'owner_spot_id' => $attachment->mediable_id,
                    'asset_id' => $asset->id,
                    'provider' => $asset->provider,
                    'match_status' => $attachment->match_status,
                    'match_method' => $attachment->match_method,
                    'match_evidence' => $attachment->match_evidence,
                    'rights_status' => $asset->rights_status,
                    'health_status' => $asset->health_status,
                    'manual_lock' => $attachment->is_manually_locked,
                    'selected' => $selected?->id === $asset->id && $attachment->isPublishable($asset),
                ];
            }

            foreach ($legacyOwners as $owner) {
                $legacyAudit[] = [
                    'spot_id' => $spot->id,
                    'owner_spot_id' => $owner->id,
                    'host' => mb_strtolower((string) parse_url((string) $owner->photo_url, PHP_URL_HOST)),
                    'url_fingerprint' => hash('sha256', (string) $owner->photo_url),
                    'managed_attachment_count' => $attachments->count(),
                    'reason' => $attachments->isEmpty()
                        ? 'unmanaged_legacy_pointer'
                        : 'legacy_pointer_retained_as_unpublished_evidence',
                ];
            }
        }

        ksort($byCategory);
        ksort($byBezirk);

        return [
            'generated_at' => now()->utc()->toAtomString(),
            'scope' => 'canonical active recommendation destinations and their identity aliases',
            'destinations' => $destinations,
            'assets' => $this->assetMetrics($assets),
            'by_category' => $byCategory,
            'by_bezirk' => $byBezirk,
            'candidate_audit_limit' => $candidateLimit,
            'candidate_audit' => $candidateAudit,
            'legacy_audit' => $legacyAudit,
        ];
    }

    /** @return Collection<int, MediaAttachment> */
    private function familyAttachments(Spot $spot): Collection
    {
        return $spot->mediaAttachments
            ->concat($spot->identityAliases->flatMap(fn (Spot $alias) => $alias->mediaAttachments))
            ->unique('id')
            ->sortBy('id')
            ->values();
    }

    /** @return array<string, int> */
    private function destinationMetrics(Collection $attachments, ?MediaAsset $selected, bool $hasLegacy): array
    {
        $assets = $attachments
            ->map(fn (MediaAttachment $attachment) => $attachment->mediaAsset)
            ->filter(fn ($asset): bool => $asset instanceof MediaAsset);

        return [
            'total' => 1,
            'no_candidate' => (int) $attachments->isEmpty(),
            'rights_pending' => (int) $assets->contains('rights_status', 'pending'),
            'rights_rejected' => (int) $assets->contains('rights_status', 'rejected'),
            'health_pending' => (int) $assets->contains('health_status', 'pending'),
            'health_broken' => (int) $assets->contains('health_status', 'broken'),
            'relevance_unknown' => (int) $attachments->contains('match_status', 'pending'),
            'relevance_rejected' => (int) $attachments->contains('match_status', 'rejected'),
            'approved_active_selected' => (int) ($selected !== null),
            'manual_selection' => (int) $attachments->contains('is_manually_locked', true),
            'legacy_only_url' => (int) ($hasLegacy && $attachments->isEmpty()),
            'missing_attribution_or_source' => (int) $assets->contains(
                fn (MediaAsset $asset): bool => blank($asset->attribution) || blank($asset->source_page_url),
            ),
        ];
    }

    /** @return array<string, int> */
    private function emptyDestinationMetrics(): array
    {
        return array_fill_keys(self::DESTINATION_METRICS, 0);
    }

    /** @param array<string, int> $target @param array<string, int> $metrics */
    private function addMetrics(array &$target, array $metrics): void
    {
        foreach (self::DESTINATION_METRICS as $metric) {
            $target[$metric] += $metrics[$metric];
        }
    }

    /** @return array<string, int> */
    private function assetMetrics(Collection $assets): array
    {
        return [
            'total' => $assets->count(),
            'rights_approved' => $assets->where('rights_status', 'approved')->count(),
            'rights_pending' => $assets->where('rights_status', 'pending')->count(),
            'rights_rejected' => $assets->where('rights_status', 'rejected')->count(),
            'health_active' => $assets->where('health_status', 'active')->count(),
            'health_pending' => $assets->where('health_status', 'pending')->count(),
            'health_broken' => $assets->where('health_status', 'broken')->count(),
            'missing_attribution_or_source' => $assets->filter(
                fn (MediaAsset $asset): bool => blank($asset->attribution) || blank($asset->source_page_url),
            )->count(),
        ];
    }
}
