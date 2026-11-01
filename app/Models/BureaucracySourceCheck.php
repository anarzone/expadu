<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

/** The latest automated source check of one catalogue card. */
class BureaucracySourceCheck extends Model
{
    use UsesUtcTimestamps;

    protected $fillable = ['task_key', 'check_hash', 'outcome', 'failures', 'unreachable', 'checked_at', 'passed_hash', 'first_passed_on', 'valid_until'];

    protected function casts(): array
    {
        return ['failures' => 'array', 'unreachable' => 'array', 'checked_at' => 'immutable_datetime',
            'first_passed_on' => 'immutable_date', 'valid_until' => 'immutable_date'];
    }
}
