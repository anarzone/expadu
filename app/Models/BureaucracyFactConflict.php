<?php

namespace App\Models;

use Database\Factories\BureaucracyFactConflictFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BureaucracyFactConflict extends Model
{
    /** @use HasFactory<BureaucracyFactConflictFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'case_id',
        'fact_key',
        'existing_fact_id',
        'candidate_fact_id',
        'status',
        'resolved_fact_id',
        'resolved_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'unresolved',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BureaucracyCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(BureaucracyCase::class, 'case_id');
    }

    /** @return BelongsTo<BureaucracyCaseFact, $this> */
    public function existingFact(): BelongsTo
    {
        return $this->belongsTo(BureaucracyCaseFact::class, 'existing_fact_id');
    }

    /** @return BelongsTo<BureaucracyCaseFact, $this> */
    public function candidateFact(): BelongsTo
    {
        return $this->belongsTo(BureaucracyCaseFact::class, 'candidate_fact_id');
    }

    /** @return BelongsTo<BureaucracyCaseFact, $this> */
    public function resolvedFact(): BelongsTo
    {
        return $this->belongsTo(BureaucracyCaseFact::class, 'resolved_fact_id');
    }

    /**
     * Conflicts still worth putting in front of someone.
     *
     * A conflict points at two facts. If the confirmed side has since been
     * superseded — a QA persona retiring its own seed, a later answer replacing
     * it — the question becomes "which of these is true?" about an answer the
     * system already retired. Asking that reads as the app forgetting what it
     * was told; a stale row must not outlive the fact it argues about.
     *
     * @param  Builder<covariant BureaucracyFactConflict>  $query
     */
    public function scopeActionable(Builder $query): void
    {
        $query->where('status', 'unresolved')
            ->whereExists(function ($existing): void {
                $existing->selectRaw('1')
                    ->from('bureaucracy_case_facts')
                    ->whereColumn('bureaucracy_case_facts.id', 'bureaucracy_fact_conflicts.existing_fact_id')
                    ->where('bureaucracy_case_facts.state', 'confirmed');
            });
    }
}
