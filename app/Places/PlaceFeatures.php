<?php

namespace App\Places;

use App\Enums\SpotCategory;
use App\Media\PublishedMediaSelector;
use App\Models\Spot;
use App\Profile\CategoryAffinity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds place_features: one row of model-ready features per canonical,
 * active leisure place, derived from the effective PlaceFacts (never from raw
 * source columns), so rankers and the AI layer read one table instead of
 * resolving facts per request.
 *
 * Every value is either a resolved fact or a stated derivation from the
 * category; unknown stays null. Completeness and quality are deterministic.
 */
class PlaceFeatures
{
    /** Facts whose known/unknown status counts towards completeness. */
    private const COMPLETENESS_FACTS = ['hours', 'fee', 'access', 'description', 'wheelchair'];

    public function __construct(
        private readonly PlaceFacts $facts,
        private readonly InterestTaxonomy $interests,
        private readonly PublishedMediaSelector $media,
    ) {}

    /** @return int rows written */
    public function rebuild(int $chunk = 200): int
    {
        $revision = $this->facts->revision();
        $startedAt = now();
        $written = 0;

        Spot::query()
            ->canonical()
            ->where('is_active', true)
            ->whereIn('category', SpotCategory::placesFines())
            ->with(['mediaAttachments.mediaAsset', 'identityAliases.mediaAttachments.mediaAsset'])
            ->chunkById($chunk, function (Collection $spots) use ($revision, $startedAt, &$written): void {
                $facts = $this->facts->resolveMany($spots);
                $rows = $spots->map(fn (Spot $spot): array => $this->row($spot, $facts->get($spot->id), $revision, $startedAt))->all();

                DB::table('place_features')->upsert(
                    $rows,
                    ['spot_id'],
                    array_keys($rows[0]),
                );
                $written += count($rows);
            });

        // Places that left the catalogue (inactive, merged, recategorised) lose their row.
        DB::table('place_features')->where('computed_at', '<', $startedAt)->delete();

        return $written;
    }

    /**
     * @param  array<string, mixed>|null  $facts
     * @return array<string, mixed>
     */
    private function row(Spot $spot, ?array $facts, int $revision, \DateTimeInterface $computedAt): array
    {
        $category = $spot->category instanceof SpotCategory ? $spot->category : SpotCategory::tryFrom((string) $spot->category);
        $practical = $facts['practical'] ?? [];
        $known = fn (?array $fact): bool => in_array($fact['status'] ?? 'unknown', ['known', 'conditional'], true);

        $indoor = $this->knownBool($practical['indoor'] ?? null);
        $covered = $this->knownBool($practical['covered'] ?? null);
        $setting = match (true) {
            $indoor === true, $covered === true => 'indoor',
            $indoor === false => 'outdoor',
            $category?->isOutdoor() === true => 'outdoor',
            default => null,
        };

        $completeness = collect(self::COMPLETENESS_FACTS)
            ->map(fn (string $key): bool => $known($key === 'wheelchair' ? ($practical['wheelchair'] ?? null) : ($facts[$key] ?? null)))
            ->avg();
        $hasPhoto = $this->media->select($spot, 'hero') !== null;

        return [
            'spot_id' => $spot->id,
            'interests' => json_encode($category !== null ? $this->interests->forSpotCategory($category->value) : []),
            'activities' => json_encode(array_values((array) ($facts['activities'] ?? []))),
            'setting' => $setting,
            'kid_friendly' => $category !== null && in_array($category->value, CategoryAffinity::KID_FRIENDLY, true),
            'price' => ($facts['fee']['status'] ?? null) === 'known' ? (string) $facts['fee']['value'] : ($spot->price_range ?: null),
            'hours_known' => ($facts['hours']['status'] ?? null) === 'known',
            'hours' => isset($facts['hours']['parsed']) ? json_encode($facts['hours']['parsed']) : null,
            'wheelchair' => $known($practical['wheelchair'] ?? null) ? (string) $practical['wheelchair']['value'] : null,
            'completeness' => round((float) $completeness, 3),
            // Deterministic: how much is known, plus whether a rights-cleared photo exists.
            'quality' => round(0.7 * (float) $completeness + ($hasPhoto ? 0.3 : 0.0), 3),
            'ai_tags' => null,
            'fact_revision' => $revision,
            'computed_at' => $computedAt,
        ];
    }

    private function knownBool(?array $fact): ?bool
    {
        if (($fact['status'] ?? 'unknown') !== 'known') {
            return null;
        }

        return match (strtolower((string) $fact['value'])) {
            'yes', 'true', '1' => true,
            'no', 'false', '0' => false,
            default => null,
        };
    }
}
