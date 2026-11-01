<?php

namespace App\Console\Commands;

use App\Services\GtfsDepartureService;
use App\Services\GtfsRailStations;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RefreshGtfs extends Command
{
    protected $signature = 'gtfs:refresh {--force : Import even when VRS has not published a new feed}';

    protected $description = 'Download and re-import GTFS static timetable data from VRS when it has published a new feed';

    public function handle(): int
    {
        // VRS republishes several times a week, and each publication renames
        // trips; realtime only names the newest. Daily, but only when changed.
        $published = self::publishedVersion();
        $imported = self::importedVersion();
        if (! $this->option('force') && $published !== null && self::sameVersion($published, $imported)) {
            $this->info('VRS has not published a new timetable since the last import; nothing to do.');

            return self::SUCCESS;
        }

        $this->info('Refreshing GTFS timetable data from VRS...');

        try {
            // Run the existing import command. A run killed part-way (a deploy
            // recreating the container) leaves the live tables as they were;
            // the version below is stored only after success, so the next run
            // tries again.
            $exitCode = Artisan::call('gtfs:import', ['--url' => ImportGtfsData::FEED_URL], $this->output);

            if ($exitCode !== 0) {
                $this->error('GTFS import failed');
                Log::error('gtfs:refresh — import failed with exit code '.$exitCode);

                return self::FAILURE;
            }

            // Keep the last good cached boards while the import runs. The
            // short-lived departure caches expire naturally; only the GTFS
            // availability flag needs immediate invalidation. Cache failure
            // after the DB commit is non-fatal and must not report the refresh
            // as failed when the new timetable is already live.
            try {
                Cache::forget('gtfs_has_data');
                Cache::forget(GtfsRailStations::CACHE_KEY);
                // The stops looked up by name start over: their ids may have changed.
                Cache::forever(GtfsDepartureService::GENERATION_KEY, now()->timestamp);
            } catch (\Throwable $exception) {
                Log::warning('gtfs:refresh — cache invalidation failed after successful import', [
                    'error' => $exception->getMessage(),
                ]);
            }

            try {
                self::recordVersion($published);
            } catch (\Throwable $exception) {
                Log::warning('gtfs:refresh — could not record the imported feed version', ['error' => $exception->getMessage()]);
            }

            $this->info('GTFS refresh complete');
            Log::info('gtfs:refresh — timetable data refreshed successfully');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('GTFS refresh error: '.$e->getMessage());
            Log::error('gtfs:refresh — '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * What VRS publishes now, from a HEAD request; null when it can't be told
     * (the import then runs, as before).
     *
     * @return array{etag: ?string, last_modified: ?string}|null
     */
    public static function publishedVersion(): ?array
    {
        try {
            $response = Http::timeout(20)->head(ImportGtfsData::FEED_URL);
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $version = ['etag' => $response->header('ETag') ?: null, 'last_modified' => $response->header('Last-Modified') ?: null];

        return $version['etag'] === null && $version['last_modified'] === null ? null : $version;
    }

    /**
     * The feed version last imported: etag, last_modified (as VRS sent them)
     * and imported_at. In the database, not the cache: a lost key made a
     * healthy feed look stale.
     *
     * @return array{etag: ?string, last_modified: ?string, imported_at: string}|null
     */
    public static function importedVersion(): ?array
    {
        $row = DB::table('gtfs_imports')->find(1);

        return $row === null ? null : [
            'etag' => $row->etag,
            'last_modified' => $row->last_modified,
            'imported_at' => Carbon::parse($row->imported_at)->toIso8601String(),
        ];
    }

    /** @param  array{etag: ?string, last_modified: ?string}|null  $published */
    public static function recordVersion(?array $published, ?CarbonInterface $at = null): void
    {
        DB::table('gtfs_imports')->upsert(
            [['id' => 1, 'etag' => $published['etag'] ?? null, 'last_modified' => $published['last_modified'] ?? null, 'imported_at' => $at ?? now()]],
            ['id'],
        );
    }

    /** @param  array{etag: ?string, last_modified: ?string}  $published */
    public static function sameVersion(array $published, mixed $imported): bool
    {
        if (! is_array($imported)) {
            return false;
        }
        if ($published['etag'] !== null && ($imported['etag'] ?? null) !== null) {
            return $published['etag'] === $imported['etag'];
        }

        return $published['last_modified'] !== null && $published['last_modified'] === ($imported['last_modified'] ?? null);
    }
}
