<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

/** Something only a person can resolve. Holds no personal data. */
class BureaucracyEscalation extends Model
{
    use UsesUtcTimestamps;

    protected $fillable = ['kind', 'subject', 'severity', 'summary', 'details', 'occurrences', 'first_seen_at', 'last_seen_at', 'alerted_at', 'resolved_at'];

    protected function casts(): array
    {
        return ['details' => 'array', 'first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime',
            'alerted_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }
}
