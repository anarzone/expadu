<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

class BureaucracyRequirementUse extends Model
{
    use UsesUtcTimestamps;

    public $timestamps = false;

    protected $fillable = ['process_id', 'requirement_id', 'requirement_hash', 'status', 'evidence_id', 'evidence_version', 'share_id',
        'actor_id', 'request_id', 'request_fingerprint', 'process_version', 'confirmed_at', 'superseded_at'];

    protected $hidden = ['request_fingerprint'];

    protected function casts(): array
    {
        return ['evidence_version' => 'integer', 'process_version' => 'integer', 'confirmed_at' => 'immutable_datetime', 'superseded_at' => 'immutable_datetime'];
    }
}
