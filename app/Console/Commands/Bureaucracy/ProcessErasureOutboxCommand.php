<?php

namespace App\Console\Commands\Bureaucracy;

use App\Bureaucracy\Privacy\ProcessErasureOutbox;
use App\Models\BureaucracyOutboxEvent;
use Illuminate\Console\Command;

class ProcessErasureOutboxCommand extends Command
{
    protected $signature = 'bureaucracy:process-erasures';

    protected $description = 'Retry removal of derived Bureaucracy copies without retaining their contents';

    public function handle(ProcessErasureOutbox $worker): int
    {
        $pending = BureaucracyOutboxEvent::query()->where('event_type', 'person.erased')->whereNull('delivered_at')
            ->where('available_at', '<=', now()->utc())
            ->where(fn ($query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<=', now()->utc()))
            ->orderBy('id')->limit(50)->get();
        $complete = $pending->filter(fn ($event) => $worker->process($event))->count();
        BureaucracyOutboxEvent::query()->where('event_type', 'person.erased')->where('delivered_at', '<', now()->utc()->subDays(30))->delete();
        $this->info("Completed {$complete} of {$pending->count()} available cleanup requests.");

        return $complete === $pending->count() ? self::SUCCESS : self::FAILURE;
    }
}
