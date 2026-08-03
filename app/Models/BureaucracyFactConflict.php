<?php

namespace App\Models;

use Database\Factories\BureaucracyFactConflictFactory;
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
}
