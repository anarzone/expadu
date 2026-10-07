<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

class BureaucracyAccessGrant extends Model
{
    use UsesUtcTimestamps;

    protected $fillable = ['person_id', 'grantee_user_id', 'grantor_user_id', 'guardian_authority_id', 'invitation_id', 'authority_basis', 'scopes', 'notice_version', 'version', 'accepted_at', 'expires_at', 'revoked_at'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'version' => 'integer', 'accepted_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
