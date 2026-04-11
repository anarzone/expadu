<?php

namespace App\Models;

use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'preferences'];

    protected function casts(): array
    {
        return [
            'preferences' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Default preferences for new users */
    public static function defaults(): array
    {
        return [
            'transit' => true,
            'burgeramt' => true,
            'language' => true,
            'events' => true,
            'weather' => true,
            'checklist' => true,
            'digest' => false,
            'rhine' => true,
        ];
    }
}
