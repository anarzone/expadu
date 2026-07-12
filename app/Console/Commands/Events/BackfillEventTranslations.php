<?php

namespace App\Console\Commands\Events;

use App\Jobs\ProcessEventJob;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class BackfillEventTranslations extends Command
{
    protected $signature = 'events:backfill-translations
        {--limit=200 : Maximum events to queue in this run}
        {--per-minute=30 : Maximum classifier jobs released per minute}';

    protected $description = 'Queue LLM translation and classification for existing incomplete events';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $perMinute = max(1, (int) $this->option('per-minute'));
        $queued = 0;

        Event::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('title_en')
                    ->orWhereNull('summary_en')
                    ->orWhere(function ($description): void {
                        $description->whereNotNull('description')
                            ->where('description', '<>', '')
                            ->whereNull('description_en');
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($events) use (&$queued, $limit, $perMinute): bool {
                foreach ($events as $event) {
                    if ($queued >= $limit) {
                        return false;
                    }

                    $lockKey = "events:translation-backfill:{$event->id}";
                    if (! Cache::add($lockKey, true, now()->addHour())) {
                        continue;
                    }

                    ProcessEventJob::dispatch($event)
                        ->delay(now()->addSeconds(intdiv($queued, $perMinute) * 60));
                    $queued++;
                }

                return $queued < $limit;
            });

        $this->info("Queued {$queued} event translation job(s).");

        return self::SUCCESS;
    }
}
