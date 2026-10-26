<?php

namespace App\Models;

use App\ContextEngine\DayImportance;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Remind me" on an event occurrence — dispatched through the existing
 * notification channel when remind_at passes.
 */
#[Fillable(['user_id', 'event_id', 'occurrence_start', 'offset_minutes', 'remind_at', 'sent_at'])]
class EventReminder extends Model
{
    /** A reminder set or taken off changes how important the event's day is (DayImportance). */
    protected static function booted(): void
    {
        static::saved(fn (self $reminder) => DayImportance::forget($reminder->user_id, $reminder->occurrence_start));
        static::deleted(fn (self $reminder) => DayImportance::forget($reminder->user_id, $reminder->occurrence_start));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrence_start' => 'datetime',
            'remind_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
