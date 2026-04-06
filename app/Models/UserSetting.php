<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'settings'])]
class UserSetting extends Model
{
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public static function defaults(): array
    {
        return [
            'share_location' => true,
            'personalised_content' => true,
            'share_anonymised' => true,
            'profile_visibility' => 'partners',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
