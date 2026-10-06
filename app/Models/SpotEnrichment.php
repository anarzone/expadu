<?php

namespace App\Models;

use Database\Factories\SpotEnrichmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['spot_id', 'locale', 'status', 'needs_review', 'summary', 'tip', 'source_snapshot', 'source_hash', 'prompt_version', 'producer', 'model', 'generated_at', 'reviewed_at', 'stale_at', 'attempt_count', 'last_attempt_at', 'last_error'])]
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
