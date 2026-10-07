<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class BureaucracyEvidenceEvent extends Model
{
    use UsesUtcTimestamps;

    public $timestamps = false;

    protected $fillable = ['evidence_id', 'actor_id', 'request_id', 'request_fingerprint', 'evidence_version', 'type', 'payload', 'recorded_at'];

    protected $hidden = ['payload', 'request_fingerprint'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'evidence_version' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Evidence history is append-only.'));
        static::deleting(fn () => throw new LogicException('Use authorised subject erasure.'));
    }
}
