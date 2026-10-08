<?php

namespace App\Console\Commands\Bureaucracy;

use App\Bureaucracy\ReadModel\ProcessReassessmentOutbox;
use App\Models\BureaucracyOutboxEvent;
use Illuminate\Console\Command;

final class ProcessReassessmentOutboxCommand extends Command
{
    protected $signature = 'bureaucracy:process-reassessments';

    protected $description = 'Refresh affected personal plans and reminders from the shared decision process';

    public function handle(ProcessReassessmentOutbox $worker): int
    {
        $events = BureaucracyOutboxEvent::query()->whereIn('event_type', ProcessReassessmentOutbox::Types)->whereNull('delivered_at')
            ->where('available_at', '<=', now()->utc())
            ->where(fn ($query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<=', now()->utc()))->orderBy('id')->limit(50)->get();
        $done = $events->filter(fn ($event) => $worker->process($event))->count();
        BureaucracyOutboxEvent::query()->whereIn('event_type', ProcessReassessmentOutbox::Types)->where('delivered_at', '<', now()->utc()->subDays(30))->delete();
        $this->info("Refreshed {$done} of {$events->count()} available change records.");

        return $done === $events->count() ? self::SUCCESS : self::FAILURE;
    }
}
