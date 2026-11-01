<?php

namespace App\Jobs;

use App\Models\CityNews;
use App\Models\NoticeBrief;
use App\Transit\NoticeBriefWriter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the English brief (or translation) for one transit notice text, on the llm queue with
 * the other model calls. A notice briefed already, or one whose brief was
 * thrown away today, isn't paid for again.
 */
class WriteNoticeBriefJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2; // malformed output → one retry, then the German notice shows

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct(public CityNews $notice)
    {
        $this->onQueue('llm');
    }

    public function uniqueId(): string
    {
        return NoticeBrief::hashFor((string) $this->notice->title, $this->notice->summary);
    }

    public function handle(NoticeBriefWriter $writer): void
    {
        $notice = $this->notice->fresh();
        if ($notice === null || ! $writer->isConfigured()) {
            return;
        }

        $hash = NoticeBrief::hashFor((string) $notice->title, $notice->summary);
        if (NoticeBrief::query()->where('input_hash', $hash)->where('locale', 'en')->exists()
            || Cache::has("notice-brief:rejected:{$hash}")) {
            return;
        }

        // English first (owner, 21 Oct 2026): the brief, else a checked literal
        // translation; only when both fail does the notice's own text show.
        if ($writer->write($notice) === null && $writer->translate($notice) === null) {
            // Try again tomorrow, or as soon as the text changes (a new hash).
            Cache::put("notice-brief:rejected:{$hash}", true, now()->addDay());
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('notice brief failed', ['city_news_id' => $this->notice->id, 'error' => $exception?->getMessage()]);
    }
}
