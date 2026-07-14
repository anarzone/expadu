<?php

namespace App\Jobs;

use App\Media\MediaAssetValidator;
use App\Models\MediaAsset;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

class ValidateMediaAssetJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 86400;

    public function __construct(public MediaAsset $asset) {}

    public function uniqueId(): string
    {
        return (string) $this->asset->getKey();
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new RateLimited('media-validation'))->releaseAfter(60)];
    }

    /**
     * Execute the job.
     */
    public function handle(MediaAssetValidator $validator): void
    {
        $validator->validate($this->asset);
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->asset->exists) {
            $this->asset->refresh();

            if ($this->asset->failure_count === 0) {
                $this->asset->update([
                    'health_status' => 'pending',
                    'failure_count' => 1,
                    'last_error' => mb_substr($exception?->getMessage() ?? 'validation_job_failed', 0, 500),
                    'last_verified_at' => now(),
                ]);
            }
        }

        Log::warning('Media asset validation job failed', [
            'media_asset_id' => $this->asset->getKey(),
            'provider' => $this->asset->provider,
            'error' => $exception?->getMessage(),
        ]);
    }
}
