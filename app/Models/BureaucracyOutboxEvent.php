<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

class BureaucracyOutboxEvent extends Model
{
    use UsesUtcTimestamps;

    protected $fillable = ['event_type', 'aggregate_type', 'aggregate_id', 'aggregate_version', 'dedupe_key', 'payload', 'attempts', 'available_at', 'claim_token', 'claimed_until', 'delivered_at', 'last_error_type'];

    protected $attributes = ['attempts' => 0];

    protected function casts(): array
    {
        return ['payload' => 'array', 'aggregate_version' => 'integer', 'attempts' => 'integer', 'available_at' => 'immutable_datetime', 'claimed_until' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime'];
    }
}
