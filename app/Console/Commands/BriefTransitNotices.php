<?php

namespace App\Console\Commands;

use App\Jobs\WriteNoticeBriefJob;
use App\Models\NoticeBrief;
use App\Services\DisruptionService;
use App\Transit\NoticeBriefWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

#[Signature('transit:brief-notices')]
#[Description('Queue English briefs for open KVB notices that have none yet')]
class BriefTransitNotices extends Command
{
    public function handle(DisruptionService $disruptions, NoticeBriefWriter $writer): int
    {
        if (! $writer->isConfigured()) {
            $this->info('Transit briefs are off or there is no ANTHROPIC_API_KEY; nothing queued.');

            return self::SUCCESS;
        }

        $notices = $disruptions->openNotices()
            ->unique(fn ($notice) => NoticeBrief::hashFor((string) $notice->title, $notice->summary));
        $briefed = NoticeBrief::query()->where('locale', 'en')
            ->whereIn('input_hash', $notices->map(fn ($notice) => NoticeBrief::hashFor((string) $notice->title, $notice->summary)))
            ->pluck('input_hash')
            ->flip();

        // A day's cap on calls, whatever KVB publishes.
        $dayKey = 'notice-brief:queued:'.now()->toDateString();
        $queued = 0;
        foreach ($notices as $notice) {
            $hash = NoticeBrief::hashFor((string) $notice->title, $notice->summary);
            // One refused today isn't asked again, so it costs nothing: counted, a few of
            // them every five minutes used the day's cap up with no call made (3 Nov 2026).
            if ($briefed->has($hash) || Cache::has("notice-brief:rejected:{$hash}")) {
                continue;
            }
            Cache::add($dayKey, 0, now()->addDays(2));
            if (Cache::increment($dayKey) > (int) config('services.transit_briefs.daily_limit')) {
                $this->warn('Daily transit brief limit reached.');
                if (Cache::add("{$dayKey}:warned", true, now()->addDays(2))) {
                    Log::warning('Transit briefs: the daily limit is reached, so new notices stay in German');
                }

                break;
            }

            WriteNoticeBriefJob::dispatch($notice);
            $queued++;
        }

        $this->info("Queued {$queued} transit brief(s).");

        return self::SUCCESS;
    }
}
