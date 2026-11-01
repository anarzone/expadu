<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BureaucracyQuestionSession extends Model
{
    use UsesUtcTimestamps;

    protected $fillable = ['case_id', 'actor_id', 'request_id', 'jurisdiction', 'status', 'consecutive_offers', 'offered_count', 'answered_count', 'deferred_count', 'known_fact_revision', 'deferred', 'expires_at'];

    protected function casts(): array
    {
        return ['consecutive_offers' => 'integer', 'offered_count' => 'integer', 'answered_count' => 'integer', 'deferred_count' => 'integer',
            'known_fact_revision' => 'integer', 'deferred' => 'array', 'expires_at' => 'immutable_datetime'];
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(BureaucracyCase::class, 'case_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(BureaucracyCaseQuestion::class, 'session_id');
    }
}
