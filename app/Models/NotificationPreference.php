<?php

namespace App\Models;

use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    /** Quiet hours unless they set their own. */
    public const QUIET_FROM = '22:00';

    public const QUIET_TO = '06:00';

    /** Toggles nothing sends any more (the weekly digest), dropped when read or saved. */
    public const RETIRED = ['digest'];

    protected $fillable = ['user_id', 'preferences', 'quiet_from', 'quiet_to', 'brief_at'];

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

    /**
     * Their settings as Settings › Notifications shows them: the toggles
     * over the defaults, quiet hours, and the brief's time (null: automatic,
     * 45 minutes before the first plan). Times are HH:MM.
     *
     * @return array{preferences: array<string, bool>, quiet_from: string, quiet_to: string, brief_at: string|null}
     */
    public static function stateFor(User $user): array
    {
        $pref = $user->notificationPreference()->first();
        $clock = fn (?string $time): ?string => $time === null ? null : substr($time, 0, 5);

        return [
            'preferences' => [...self::defaults(), ...Arr::except($pref?->preferences ?? [], self::RETIRED)],
            'quiet_from' => $clock($pref?->quiet_from) ?? self::QUIET_FROM,
            'quiet_to' => $clock($pref?->quiet_to) ?? self::QUIET_TO,
            'brief_at' => $clock($pref?->brief_at),
        ];
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
            'rhine' => true,
        ];
    }
}
