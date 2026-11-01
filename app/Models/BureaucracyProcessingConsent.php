<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BureaucracyProcessingConsent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'actor_id', 'case_id', 'question_id', 'fact_version', 'request_key',
        'purpose', 'provider_version', 'notice_version', 'input_digest', 'state',
        'result', 'granted_at', 'expires_at', 'withdrawn_at', 'attempted_at',
        'completed_at', 'delete_after', 'dispatched_at',
    ];

    protected $hidden = ['result', 'input_digest'];

    public function freshTimestamp()
    {
        return parent::freshTimestamp()->utc();
    }

    protected function casts(): array
    {
        return [
            'result' => 'encrypted:array',
            'fact_version' => 'integer',
            'granted_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
            'attempted_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'delete_after' => 'immutable_datetime',
        ];
    }
}
