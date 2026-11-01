<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BureaucracyGuardianAuthority extends Model
{
    use UsesUtcTimestamps;

    protected $fillable = ['person_id', 'guardian_user_id', 'status', 'policy_version', 'evidence_reference', 'reviewed_by', 'reviewed_at', 'expires_at', 'revoked_at'];

    protected $hidden = ['evidence_reference'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['evidence_reference' => 'encrypted', 'reviewed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(BureaucracyPerson::class, 'person_id');
    }
}
