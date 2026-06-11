<?php

namespace App\Console\Commands\Events;

use App\Models\Event;
use Illuminate\Console\Command;

/**
 * Past events go invisible automatically — no manual cleanup. A one-off
 * expires once it has ended; a recurring series expires only when its
 * recurrence_until has passed (open-ended series never do).
 */
class ExpireEvents extends Command
{
    protected $signature = 'events:expire';

    protected $description = 'Mark events whose last occurrence has passed as expired';

    public function handle(): int
    {
        $now = now();

        $oneOff = Event::query()
            ->where('status', 'active')
            ->whereNull('recurrence')
            ->where(fn ($q) => $q
                ->where(fn ($ended) => $ended->whereNotNull('ends_at')->where('ends_at', '<', $now))
                ->orWhere(fn ($noEnd) => $noEnd->whereNull('ends_at')->where('starts_at', '<', $now->copy()->subHours(4))))
            ->update(['status' => 'expired']);

        $series = Event::query()
            ->where('status', 'active')
            ->whereNotNull('recurrence')
            ->whereNotNull('recurrence_until')
            ->where('recurrence_until', '<', $now)
            ->update(['status' => 'expired']);

        $this->info('Expired: '.($oneOff + $series).' event(s).');

        return self::SUCCESS;
    }
}
