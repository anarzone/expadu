<?php

namespace App\Jobs;

use App\Media\MediaAssetValidator;
use App\Models\MediaAsset;
use Carbon\CarbonImmutable;
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

    public string $expectedInputFingerprint;

    /** @var array<string, mixed> */
    public array $expectedInputSnapshot;

    public CarbonImmutable $retryDeadline;

    public function __construct(public MediaAsset $asset, ?string $expectedInputFingerprint = null)
    {
        $this->expectedInputSnapshot = MediaAssetValidator::inputSnapshot($asset);
        $this->expectedInputFingerprint = $expectedInputFingerprint
            ?? MediaAssetValidator::inputFingerprint($asset);
        $this->retryDeadline = CarbonImmutable::now()->addDay();
    }

    public function uniqueId(): string
    {
        return $this->asset->getKey().':'.$this->expectedInputFingerprint;
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryDeadline;
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
        $validator->validate(
            $this->asset,
            $this->expectedInputFingerprint,
            $this->expectedInputSnapshot,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $asset = MediaAsset::query()->find($this->asset->getKey());
        if ($asset !== null) {
            app(MediaAssetValidator::class)->recordInfrastructureFailure(
                $asset,
                $exception?->getMessage() ?? 'validation_job_failed',
                $this->expectedInputFingerprint,
                $this->expectedInputSnapshot,
            );
        }

        Log::warning('Media asset validation job failed', [
            'media_asset_id' => $this->asset->getKey(),
            'provider' => $this->asset->provider,
            'error' => $exception?->getMessage(),
        ]);
    }
}
