<?php

namespace App\Console\Commands;

use App\Media\CaptureMediaCandidate;
use App\Media\MapillaryPhotoResolver;
use App\Media\MediaCandidate;
use App\Models\Spot;
use App\Models\Venue;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
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

    public function handle(CaptureMediaCandidate $captureMediaCandidate): int
    {
        if (! $this->resolver->configured()) {
            $this->warn('MAPILLARY_TOKEN is not set — nothing to do.');

            return self::SUCCESS;
        }

        $records = $this->targets();
        $this->info("Checking {$records->count()} place(s) for street-level photos...");

        $saved = 0;
        $skipped = 0;

        foreach ($records as $record) {
            $resolved = $this->resolver->resolve(
                (float) $record->lat,
                (float) $record->lng,
                fn (string $error) => $this->warn("  mapillary failed for #{$record->id}: {$error}"),
            );

            if ($resolved === null) {
                $skipped++;

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
            ));

            if ($attachment !== null) {
                $saved++;
            }
        }

        $this->info("Street-level photos captured: {$saved} (no facing frame for {$skipped}).");
        $this->line('Health checks run asynchronously — publishable counts settle once the queue drains.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Model>
     */
    private function targets()
    {
        $query = $this->option('venues') ? Venue::query() : Spot::query();

        return $query
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->when(! $this->option('force'), fn ($builder) => $builder->whereDoesntHave(
                'mediaAttachments.mediaAsset',
                fn ($asset) => $asset->published(),
            ))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();
    }
}
