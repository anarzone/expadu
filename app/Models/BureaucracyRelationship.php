<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

class BureaucracyRelationship extends Model
{
    use UsesUtcTimestamps;

    protected $fillable = ['workspace_id', 'person_id', 'related_person_id', 'type', 'effective_from', 'effective_until', 'confirmed_by', 'source', 'confirmed_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['effective_from' => 'immutable_date', 'effective_until' => 'immutable_date', 'confirmed_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
