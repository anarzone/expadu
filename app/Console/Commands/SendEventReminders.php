<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Notifications\EventReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendEventReminders extends Command
{
    protected $signature = 'events:send-reminders';

    protected $description = 'Send reminder notifications for events happening tomorrow';

    public function handle(): int
    {
        // Find events starting tomorrow
        $tomorrow = now()->addDay()->startOfDay();
        $tomorrowEnd = now()->addDay()->endOfDay();

        $events = Event::whereBetween('starts_at', [$tomorrow, $tomorrowEnd])
            ->withCount('attendees')
            ->get();

        if ($events->isEmpty()) {
            $this->info('No events tomorrow.');

            return self::SUCCESS;
        }

        $notifiedCount = 0;

        foreach ($events as $event) {
            // Get users attending this event who haven't been reminded yet
            $attendees = $event->attendees()
                ->whereNull('event_attendees.reminded_at')
                ->get();

            foreach ($attendees as $user) {
                if (! $user->wantsNotification('events')) {
                    continue;
                }

                $user->notify(new EventReminderNotification($event));

                // Mark as reminded so we don't send again
                $event->attendees()->updateExistingPivot($user->id, [
                    'reminded_at' => now(),
                ]);

                $notifiedCount++;
            }
        }

        $this->info("Sent {$notifiedCount} event reminder(s) for {$events->count()} event(s).");

        if ($notifiedCount > 0) {
            Log::info('Event reminders sent', [
                'events' => $events->count(),
                'reminders' => $notifiedCount,
            ]);
        }

        return self::SUCCESS;
    }
}
