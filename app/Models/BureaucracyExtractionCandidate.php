<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

class BureaucracyExtractionCandidate extends Model
{
    use UsesUtcTimestamps;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'consent_id', 'case_id', 'question_id', 'session_id', 'actor_id', 'state', 'value',
        'confirmation_token', 'dependency_token', 'authority_token', 'expires_at', 'confirmed_fact_id', 'confirmed_fact_revision',
        'confirmation_request_id', 'confirmation_fingerprint', 'confirmed_at'];

    protected $hidden = ['value', 'confirmation_token', 'dependency_token', 'authority_token', 'confirmation_fingerprint'];

    protected function casts(): array
    {
        return ['value' => 'encrypted:json', 'confirmation_token' => 'encrypted',
            'expires_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'confirmed_fact_revision' => 'integer'];
    }
}
