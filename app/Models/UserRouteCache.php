<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'from_place_id', 'to_place_id', 'mode', 'lines', 'polyline', 'bbox', 'typical_window', 'computed_at'])]
class UserRouteCache extends Model
{
    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'bbox' => 'array',
            'typical_window' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<UserPlace, $this> */
    public function fromPlace(): BelongsTo
    {
        return $this->belongsTo(UserPlace::class, 'from_place_id');
    }

    /** @return BelongsTo<UserPlace, $this> */
    public function toPlace(): BelongsTo
    {
        return $this->belongsTo(UserPlace::class, 'to_place_id');
    }

    /**
     * Whether the current time falls inside any typical window.
     * typical_window shape: {weekday: [[startHour, endHour], ...], weekend: [[...]]}
     */
    public function isInTypicalWindow(): bool
    {
        $key = now()->isWeekend() ? 'weekend' : 'weekday';
        $windows = $this->typical_window[$key] ?? [];
        $hour = now()->hour;

        foreach ($windows as $window) {
            [$start, $end] = $window;
            if ($hour >= $start && $hour < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the current time is within $minutes minutes before/after a typical window.
     */
    public function isNearTypicalWindow(int $minutes): bool
    {
        $key = now()->isWeekend() ? 'weekend' : 'weekday';
        $windows = $this->typical_window[$key] ?? [];
        $now = now();
        $minutesNow = $now->hour * 60 + $now->minute;

        foreach ($windows as $window) {
            [$start, $end] = $window;
            $startMin = $start * 60;
            $endMin = $end * 60;
            if ($minutesNow >= $startMin - $minutes && $minutesNow <= $endMin + $minutes) {
                return true;
            }
        }

        return false;
    }
}
