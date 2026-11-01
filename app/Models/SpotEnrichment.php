<?php

namespace App\Models;

use App\Enums\VibeTag;
use Database\Factories\SpotEnrichmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

#[Fillable(['spot_id', 'locale', 'status', 'needs_review', 'summary', 'tip', 'source_snapshot', 'source_hash', 'prompt_version', 'producer', 'model', 'generated_at', 'reviewed_at', 'stale_at', 'attempt_count', 'last_attempt_at', 'last_error', 'vibe_tags', 'summary_status'])]
class SpotEnrichment extends Model
{
    /** @use HasFactory<SpotEnrichmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'needs_review' => 'boolean',
            'source_snapshot' => 'array',
            'vibe_tags' => 'array',
            'generated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'stale_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Spot, $this> */
    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }

    /** @param Builder<SpotEnrichment> $query */
    public function scopeVisibleEnglish(Builder $query): Builder
    {
        return $query->where('locale', 'en')->whereIn('status', ['ready', 'stale']);
    }

    /** @param Builder<SpotEnrichment> $query */
    public function scopeWithVibeTag(Builder $query, VibeTag $tag): Builder
    {
        return $query->whereRaw('vibe_tags @> ?::jsonb', [json_encode([['tag' => $tag->value]])]);
    }

    /**
     * The visible English enrichments (ready, or stale while a refresh is
     * pending) for these places, keyed by spot id. A place without one is
     * missing from the result: its description and tags are unknown.
     *
     * @param  iterable<int>  $spotIds
     * @return Collection<int, SpotEnrichment>
     */
    public static function forSpots(iterable $spotIds): Collection
    {
        $ids = collect($spotIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values();

        return $ids->isEmpty()
            ? new Collection
            : static::query()->visibleEnglish()->whereIn('spot_id', $ids)->get()->keyBy('spot_id')->toBase();
    }

    /** @return list<string> the tag names, each backed by stored evidence */
    public function vibeTagNames(): array
    {
        return array_values(array_map(fn (array $tag): string => (string) $tag['tag'], (array) $this->vibe_tags));
    }

    public function hasVibeTag(VibeTag $tag): bool
    {
        return in_array($tag->value, $this->vibeTagNames(), true);
    }

    /**
     * @param  list<int>  $spotIds
     */
    public static function markReadyAsStaleForSpots(array $spotIds): void
    {
        $spotIds = array_values(array_unique(array_filter(
            $spotIds,
            static fn (mixed $spotId): bool => is_int($spotId) && $spotId > 0,
        )));

        if ($spotIds === []) {
            return;
        }

        static::query()
            ->whereIn('spot_id', $spotIds)
            ->where('status', 'ready')
            ->update([
                'status' => 'stale',
                'stale_at' => now(),
            ]);
    }
}
