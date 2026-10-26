<?php

namespace App\Models;

use App\ContextEngine\DayImportance;
use App\Services\GermanHolidayService;
use Carbon\CarbonInterface;
use Database\Factories\UserPlaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'emoji', 'name', 'address', 'lat', 'lng', 'located_from_address', 'sort_order', 'arrive_by', 'leave_at', 'category', 'day_mode', 'active_days'])]
class UserPlace extends Model
{
    /** @use HasFactory<UserPlaceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'located_from_address' => 'boolean',
            'active_days' => 'array',
        ];
    }

    /** Home's or Work's arrive-by or days changed: today's and tomorrow's importance is worked out again (DayImportance). */
    protected static function booted(): void
    {
        static::saved(fn (self $place) => DayImportance::forget($place->user_id));
        static::deleted(fn (self $place) => DayImportance::forget($place->user_id));
    }

    /**
     * Check if this place is active/relevant today.
     */
    public function isActiveToday(): bool
    {
        if (! $this->isActiveOn(now())) {
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

    /** Whether its days include this one: every day, weekdays (not a holiday), weekends and holidays, or the days they picked. */
    public function isActiveOn(CarbonInterface $day): bool
    {
        $isHoliday = app(GermanHolidayService::class)->isHoliday($day);

        return match ($this->day_mode ?? 'all') {
            'weekdays' => $day->isWeekday() && ! $isHoliday,
            'weekends' => $day->isWeekend() || $isHoliday,
            'custom' => in_array(strtolower($day->format('D')), $this->active_days ?? [], true),
            default => true, // 'all'
        };
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
     * Places with a point: one without isn't set, so nothing works it out
     * from a placeholder.
     *
     * @param  Builder<UserPlace>  $query
     * @return Builder<UserPlace>
     */
    public function scopeLocated(Builder $query): Builder
    {
        return $query->whereNotNull('lat')->whereNotNull('lng');
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
