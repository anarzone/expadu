<?php

namespace App\Models;

use App\Services\GermanHolidayService;
use Database\Factories\UserPlaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'emoji', 'name', 'address', 'lat', 'lng', 'sort_order', 'arrive_by', 'category', 'day_mode', 'active_days'])]
class UserPlace extends Model
{
    /** @use HasFactory<UserPlaceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'active_days' => 'array',
        ];
    }

    /**
     * Check if this place is active/relevant today.
     */
    public function isActiveToday(): bool
    {
        $dayName = strtolower(now()->format('D')); // mon, tue, wed...

        $isHoliday = app(GermanHolidayService::class)->isHoliday();

        $dayActive = match ($this->day_mode ?? 'all') {
            'weekdays' => now()->isWeekday() && ! $isHoliday,
            'weekends' => now()->isWeekend() || $isHoliday,
            'custom' => in_array($dayName, $this->active_days ?? [], true),
            default => true, // 'all'
        };

        if (! $dayActive) {
            return false;
        }

        // If arrive_by is set (e.g. course at 09:00), don't suggest 2+ hours after
        if ($this->arrive_by && $this->category === 'custom') {
            $arriveHour = (int) explode(':', $this->arrive_by)[0];
            $cutoff = $arriveHour + 2; // 2 hours after arrival = likely done
            if (now()->hour >= $cutoff) {
                return false;
            }
        }

        return true;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Home and Work places cannot be deleted.
     */
    public function isProtected(): bool
    {
        return in_array($this->category, ['home', 'work']);
    }

    /**
     * Required places (Home + Work).
     *
     * @param  Builder<UserPlace>  $query
     * @return Builder<UserPlace>
     */
    public function scopeRequired(Builder $query): Builder
    {
        return $query->whereIn('category', ['home', 'work']);
    }
}
