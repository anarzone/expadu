<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

class BureaucracyInvitation extends Model
{
    use UsesUtcTimestamps;

    protected $fillable = ['workspace_id', 'inviter_user_id', 'recipient_hash', 'token_hash', 'requested_scopes', 'notice_version', 'accepted_person_id', 'expires_at', 'accepted_at', 'revoked_at'];

    protected $hidden = ['recipient_hash', 'token_hash'];

    protected function casts(): array
    {
        return ['requested_scopes' => 'array', 'expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
