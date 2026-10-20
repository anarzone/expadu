<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

class BureaucracyEvidenceShare extends Model
{
    use UsesUtcTimestamps;

    public $timestamps = false;

    protected $fillable = ['evidence_id', 'evidence_version', 'process_id', 'requirement_id', 'requirement_hash', 'notice_version', 'grantor_id', 'confirmed_at', 'expires_at', 'revoked_at', 'request_id', 'request_fingerprint'];

    protected $hidden = ['request_fingerprint'];

    protected function casts(): array
    {
        return ['evidence_version' => 'integer', 'confirmed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
