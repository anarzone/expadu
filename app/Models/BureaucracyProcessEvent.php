<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class BureaucracyProcessEvent extends Model
{
    use UsesUtcTimestamps;

    public $timestamps = false;

    protected $fillable = ['process_id', 'actor_id', 'request_id', 'request_fingerprint', 'type', 'payload',
        'corrects_event_id', 'process_version', 'recorded_at'];

    protected $hidden = ['payload', 'request_fingerprint'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'process_version' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Append a correction instead of editing an event.'));
        static::deleting(fn () => throw new LogicException('Events are removed only through authorised subject erasure.'));
    }
}
