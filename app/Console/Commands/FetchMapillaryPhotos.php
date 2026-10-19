<?php

namespace App\Console\Commands;

use App\Media\CaptureMediaCandidate;
use App\Media\MapillaryPhotoResolver;
use App\Media\MediaAcquisitionScheduler;
use App\Media\MediaCandidate;
use App\Media\ScheduledMediaAcquisition;
use App\Models\Spot;
use App\Models\Venue;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Backfill street-level photos from Mapillary for places Wikimedia Commons
 * could never cover.
 *
 * Commons only has what somebody chose to photograph and name — landmarks,
 * museums, big parks. The long tail (playgrounds, pitches, community centres)
 * has no Commons presence at all, but almost all of it sits on a street that
 * has been driven with a camera. This command fills that gap, and only where
 * the camera was demonstrably pointing at the place.
 *
 * Runs last in the photo chain: anything already carrying a published photo is
 * skipped, so a real Commons photograph always outranks a street frame.
 */
class FetchMapillaryPhotos extends Command
{
    protected $signature = 'photos:fetch-mapillary
        {--limit=200 : Max places to process per run}
        {--venues : Fill venues instead of spots}
        {--force : Re-check places that already have a published photo}';

    protected $description = 'Fetch CC BY-SA street-level photos from Mapillary for places with no published image';

    public function __construct(private readonly MapillaryPhotoResolver $resolver)
    {
        parent::__construct();
    }

    public function handle(
        CaptureMediaCandidate $captureMediaCandidate,
        MediaAcquisitionScheduler $scheduler,
    ): int {
        if (! $this->resolver->configured()) {
            $this->warn('MAPILLARY_TOKEN is not set — nothing to do.');

            return self::SUCCESS;
        }

        $scheduledRecords = $this->targets($scheduler);
        $this->info("Checking {$scheduledRecords->count()} place(s) for street-level photos...");

        $saved = 0;
        $skipped = 0;

        foreach ($scheduledRecords as $scheduled) {
            $record = $scheduled->target;
            $error = null;
            $resolved = $this->resolver->resolve(
                (float) $record->lat,
                (float) $record->lng,
                function (string $message) use ($record, &$error): void {
                    $error = $message;
                    $this->warn("  mapillary failed for #{$record->id}: {$message}");
                },
            );

            if ($resolved === null) {
                $skipped++;
                $scheduler->record(
                    $scheduled,
                    $error === null ? 'no_result' : (str_contains($error, '429') ? 'rate_limited' : 'failed'),
                    errorCode: $error === null ? null : 'provider_request_failed',
                    retryAfterSeconds: $scheduler->retryAfterSeconds($error),
                    metadata: $error === null ? null : ['message' => mb_substr($error, 0, 500)],
                );

                continue;
            }

            $attachment = $captureMediaCandidate->execute($record, new MediaCandidate(
                provider: 'mapillary',
                remoteUrl: $resolved['remote_url'],
                providerAssetId: $resolved['provider_asset_id'],
                sourcePageUrl: $resolved['source_page_url'],
                role: 'hero',
                // Below Commons (20) so a real photograph always wins.
                priority: 60,
                isPrimary: false,
                rightsStatus: $resolved['rights_status'],
                healthStatus: $resolved['health_status'],
                author: $resolved['author'],
                attribution: $resolved['attribution'],
                licenseCode: $resolved['license_code'],
                licenseUrl: $resolved['license_url'],
                mimeType: $resolved['mime_type'],
                metadata: ['mapillary_id' => $resolved['provider_asset_id']],
                shouldValidate: true,
                authoritativeEvidence: true,
                matchStatus: 'pending',
                matchMethod: 'mapillary_facing_frame',
                matchEvidence: [
                    'target_type' => $record->getMorphClass(),
                    'target_id' => $record->getKey(),
                    'target_lat' => $record->lat,
                    'target_lng' => $record->lng,
                    'mapillary_id' => $resolved['provider_asset_id'],
                ],
            ));

            if ($attachment !== null) {
                $saved++;
                $scheduler->record(
                    $scheduled,
                    'captured',
                    candidateCount: 1,
                    selectedAssetIds: [$attachment->media_asset_id],
                );
            } else {
                $scheduler->record($scheduled, 'failed', errorCode: 'candidate_rejected', candidateCount: 1);
            }
        }

        $this->info("Street-level photos captured: {$saved} (no facing frame for {$skipped}).");
        $this->line('Health checks run asynchronously — publishable counts settle once the queue drains.');
        $this->line(json_encode(
            ['acquisition_summary' => $scheduler->summary($scheduledRecords)],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, ScheduledMediaAcquisition>
     */
    private function targets(MediaAcquisitionScheduler $scheduler): Collection
    {
        $query = $this->option('venues') ? Venue::query() : Spot::query()->canonical();

        $query
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->when(! $this->option('force'), fn ($builder) => $builder->whereDoesntHave(
                'mediaAttachments',
                fn ($attachment) => $attachment->publishable(),
            ));

        return $scheduler->select(
            $query,
            'mapillary',
            'facing_frame',
            max(1, (int) $this->option('limit')),
            fn ($record): array => [
                'name' => $record->name,
                'lat' => $record->lat,
                'lng' => $record->lng,
                'radius_metres' => (int) config('media.mapillary.radius_metres', 30),
                'max_bearing_offset_degrees' => (int) config('media.mapillary.max_bearing_offset_degrees', 40),
            ],
            (bool) $this->option('force'),
        );
    }
}
