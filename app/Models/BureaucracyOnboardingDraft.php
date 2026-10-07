<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

class BureaucracyOnboardingDraft extends Model
{
    use UsesUtcTimestamps;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'actor_id', 'person_id', 'schema_version', 'version', 'status', 'payload', 'expires_at',
        'completion_request_id', 'completed_fact_revision', 'completed_at'];

    protected $hidden = ['payload', 'completion_request_id'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'version' => 'integer', 'completed_fact_revision' => 'integer',
            'expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
