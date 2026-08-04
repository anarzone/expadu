<?php

namespace App\Models;

use Database\Factories\BureaucracyCaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BureaucracyCase extends Model
{
    /** @use HasFactory<BureaucracyCaseFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'status',
        'fact_version',
        'ai_consent_at',
        'ai_consent_withdrawn_at',
        'last_assessed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'fact_version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fact_version' => 'integer',
            'ai_consent_at' => 'datetime',
            'ai_consent_withdrawn_at' => 'datetime',
            'last_assessed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<BureaucracyCaseFact, $this> */
    public function facts(): HasMany
    {
        return $this->hasMany(BureaucracyCaseFact::class, 'case_id');
    }

    /** @return HasMany<BureaucracyFactConflict, $this> */
    public function conflicts(): HasMany
    {
        return $this->hasMany(BureaucracyFactConflict::class, 'case_id');
    }

    /** @return HasMany<BureaucracyCaseQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(BureaucracyCaseQuestion::class, 'case_id');
    }

    /** @return HasMany<BureaucracyPlanSnapshot, $this> */
    public function planSnapshots(): HasMany
    {
        return $this->hasMany(BureaucracyPlanSnapshot::class, 'case_id');
    }

    /** @return HasMany<BureaucracyCaseMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(BureaucracyCaseMessage::class, 'case_id');
    }

    public function hasCurrentAiConsent(): bool
    {
        return $this->ai_consent_at !== null && $this->ai_consent_withdrawn_at === null;
    }
}
