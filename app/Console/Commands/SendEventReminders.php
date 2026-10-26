<?php

namespace App\Console\Commands;

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\ContextEngine\Scorer;
use App\Models\Event;
use Carbon\CarbonImmutable;
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

        // Only events still on: a cancelled or unlisted one sends nothing.
        $events = Event::where('status', 'active')
            ->whereBetween('starts_at', [$tomorrow, $tomorrowEnd])
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

                // Tier 2 (owner, 26 Oct 2026): on the Alerts page now, said in the day's brief (DayBriefs),
                // never pushed alone; through the bus like every other alert.
                app(ActionBus::class)->insert($user, new ScoredAction(
                    type: 'event_tomorrow', actionKey: "event_tomorrow:{$event->id}:user:{$user->id}", score: Scorer::SEVERITY_BASE['info'],
                    severity: 'info', validUntil: CarbonImmutable::instance($event->starts_at), deliverChannels: [ScoredAction::CHANNEL_ALERT_PAGE],
                    payload: ['event_id' => $event->id], createdAt: CarbonImmutable::now(),
                ));

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
