<?php

namespace App\Places;

use App\Console\Commands\ImportOsmSpots;
use App\Models\Spot;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PlaceIdentityAudit
{
    /** @return array<string, mixed> */
    public function report(float $radiusMeters = 10): array
    {
        if (! is_finite($radiusMeters) || $radiusMeters <= 0 || $radiusMeters > 100) {
            throw new InvalidArgumentException('Radius must be greater than zero and at most 100 metres.');
        }

        $pairs = DB::table('spots as legacy')
            ->join('spots as current', function ($join) use ($radiusMeters) {
                $join->on('legacy.name', '=', 'current.name')
                    ->on('legacy.category', '=', 'current.category')
                    ->whereRaw('ST_DWithin(legacy.location, current.location, ?)', [$radiusMeters]);
            })
            ->whereNull('legacy.source')
            ->whereNull('legacy.source_id')
            ->whereNull('legacy.canonical_spot_id')
            ->whereNull('current.canonical_spot_id')
            ->where('current.source', 'osm')
            ->whereNotNull('current.source_id')
            ->where('current.is_active', true)
            ->orderBy('legacy.id')
            ->orderBy('current.id')
            ->select(['legacy.id as legacy_id', 'current.id as current_id'])
            ->selectRaw('ST_Distance(legacy.location, current.location) as distance_m')
            ->get();

        $ids = $pairs->pluck('legacy_id')->merge($pairs->pluck('current_id'))->unique()->values()->all();
        $spots = Spot::query()->whereIn('id', $ids)
            ->with('mediaAttachments.mediaAsset')->get()->keyBy('id');
        $references = [];
        foreach (['reviews' => ['reviews', 'spot_id'], 'feedback' => ['spot_feedback', 'spot_id'], 'children' => ['spots', 'parent_spot_id'], 'areas' => ['park_areas', 'parent_spot_id'], 'venues' => ['venues', 'place_id']] as $kind => [$table, $column]) {
            $references[$kind] = DB::table($table)->whereIn($column, $ids)
                ->select($column)->selectRaw('count(*) as total')->groupBy($column)->pluck('total', $column);
        }

        $records = $spots->map(function (Spot $spot) use ($references): array {
            $media = $spot->mediaAttachments->sortBy('id')->map(fn ($attachment): array => [
                'asset_id' => $attachment->media_asset_id,
                'provider' => $attachment->mediaAsset->provider,
                'rights_status' => $attachment->mediaAsset->rights_status,
                'health_status' => $attachment->mediaAsset->health_status,
                'license_code' => $attachment->mediaAsset->license_code,
                'role' => $attachment->role,
                'is_primary' => $attachment->is_primary,
                'is_manually_locked' => $attachment->is_manually_locked,
            ])->values()->all();

            return [
                ...$spot->only(['id', 'name', 'category', 'lat', 'lng', 'source', 'source_id', 'veedel', 'parent_spot_id', 'park_name', 'is_active', 'is_recommendable', 'updated_at']),
                'references' => array_map(fn ($counts): int => (int) ($counts[$spot->id] ?? 0), $references),
                'media' => $media,
                'published_media_count' => count(array_filter($media, fn (array $asset): bool => $asset['rights_status'] === 'approved' && $asset['health_status'] === 'active')),
                'legacy_photo_present' => filled($spot->photo_url),
            ];
        });

        $legacyCounts = $pairs->countBy('legacy_id');
        $currentCounts = $pairs->countBy('current_id');
        $labels = implode('|', array_map(fn (string $label): string => preg_quote($label, '/'), ImportOsmSpots::FALLBACK_LABELS));
        $candidates = $pairs->map(function ($pair) use ($records, $legacyCounts, $currentCounts, $labels): array {
            $legacy = $records[$pair->legacy_id];
            $current = $records[$pair->current_id];
            $flags = [];
            if ($legacyCounts[$pair->legacy_id] > 1 || $currentCounts[$pair->current_id] > 1) {
                $flags[] = 'ambiguous_match';
            }
            if (preg_match('/^('.$labels.')(?:\s*·.*)?$/iu', trim($legacy['name'])) === 1) {
                $flags[] = 'generic_name';
            }
            if (($legacy['is_active'] && $legacy['is_recommendable']) !== ($current['is_active'] && $current['is_recommendable'])) {
                $flags[] = 'eligibility_disagreement';
            }
            if ($legacy['parent_spot_id'] !== $current['parent_spot_id'] || $legacy['park_name'] !== $current['park_name']) {
                $flags[] = 'parent_disagreement';
            }

            return [
                'legacy' => $legacy,
                'current' => $current,
                'distance_m' => round((float) $pair->distance_m, 3),
                'flags' => $flags,
                'review_required' => true,
            ];
        })->all();

        return [
            'schema_version' => 1,
            'generated_at' => now()->toIso8601String(),
            'radius_m' => $radiusMeters,
            'auto_merge_allowed' => false,
            'scope' => 'Source-null legacy records and active OSM records with exactly matching names and categories. Proximity is not proof of identity.',
            'summary' => [
                'candidate_pairs' => count($candidates),
                'matched_legacy_records' => $legacyCounts->count(),
                'matched_current_records' => $currentCounts->count(),
                'ambiguous_pairs' => count(array_filter($candidates, fn (array $pair): bool => in_array('ambiguous_match', $pair['flags'], true))),
            ],
            'candidates' => $candidates,
        ];
    }
}
