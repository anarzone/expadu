<?php

namespace App\Models;

use App\Casts\EncryptedCaseFactValue;
use Database\Factories\BureaucracyCaseFactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BureaucracyCaseFact extends Model
{
    /** @use HasFactory<BureaucracyCaseFactFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'case_id',
        'key',
        'value',
        'state',
        'source',
        'source_reference',
        'confirmed_at',
        'reconfirm_at',
        'superseded_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'value',
        'encrypted_value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => EncryptedCaseFactValue::class,
            'confirmed_at' => 'datetime',
            'reconfirm_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BureaucracyCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(BureaucracyCase::class, 'case_id');
    }

    /** @return HasMany<BureaucracyFactConflict, $this> */
    public function conflictsAsExisting(): HasMany
    {
        return $this->hasMany(BureaucracyFactConflict::class, 'existing_fact_id');
    }

    /** @return HasMany<BureaucracyFactConflict, $this> */
    public function conflictsAsCandidate(): HasMany
    {
        return $this->hasMany(BureaucracyFactConflict::class, 'candidate_fact_id');
    }

    /** @return HasMany<BureaucracyFactConflict, $this> */
    public function resolvedConflicts(): HasMany
    {
        return $this->hasMany(BureaucracyFactConflict::class, 'resolved_fact_id');
    }
}
