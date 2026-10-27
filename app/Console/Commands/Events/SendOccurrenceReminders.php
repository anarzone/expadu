<?php

namespace App\Console\Commands\Events;

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\Models\Event;
use App\Models\EventReminder;
use App\Notifications\EventOccurrenceReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Delivers due "Remind me" reminders through the existing notification
 * channel (in-app centre + web push). Runs every five minutes; sending
 * is idempotent via sent_at.
 */
class SendOccurrenceReminders extends Command
{
    protected $signature = 'events:send-occurrence-reminders';

    protected $description = 'Send due per-occurrence event reminders';

    public function handle(): int
    {
        $due = EventReminder::query()
            ->whereNull('sent_at')
            ->where('remind_at', '<=', now())
            ->where('occurrence_start', '>=', now()) // never remind about a started event
            ->with(['user', 'event'])
            ->limit(200)
            ->get();

        foreach ($due as $reminder) {
            if (! $reminder->user || ! $reminder->event) {
                $reminder->update(['sent_at' => now()]);

                continue;
            }

            $occurrenceStart = CarbonImmutable::instance($reminder->occurrence_start);
            $occurrence = Event::occurringBetween($occurrenceStart->startOfDay(), $occurrenceStart->endOfDay(), [$reminder->event_id])
                ->first(fn (array $item): bool => $item['starts_at']->equalTo($occurrenceStart));
            if ($occurrence === null) {
                // Retain the user's history and retire stale delivery just as
                // we do when notification preferences suppress a reminder.
                $reminder->update(['sent_at' => now()]);

                continue;
            }

            // Through the one push gate, as they asked for it: their events preference, quiet hours
            // (it waits until morning unless the event is within two hours), counted in the budget.
            try {
                $decision = app(ActionBus::class)->deliver($reminder->user,
                    ScoredAction::claimed('event_reminder', "event_reminder:{$reminder->id}", 'moderate', $occurrenceStart, ['reminder' => $reminder->id]),
                    new EventOccurrenceReminder($reminder), $occurrenceStart);
            } catch (Throwable $e) {
                // Not sent, so not marked sent: the next run tries again.
                report($e);

                continue;
            }
            if (! $decision->later) {
                $reminder->update(['sent_at' => now()]);
            }
        }

        $this->info("Sent {$due->count()} reminder(s).");

        return self::SUCCESS;
    }
}
