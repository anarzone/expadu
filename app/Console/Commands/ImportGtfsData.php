<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use ZipArchive;

class ImportGtfsData extends Command
{
    /**
     * @var string
     */
    protected $signature = 'gtfs:import {--url=https://download.vrsinfo.de/gtfs/GTFS_VRS_mit_SPNV.zip}';

    /**
     * @var string
     */
    protected $description = 'Import GTFS static timetable data from VRS/Open NRW feed';

    /**
     * @var array<string, array{table: string, columns: array<string, string>}>
     */
    protected array $files = [
        'stops.txt' => [
            'table' => 'gtfs_stops',
            'columns' => [
                'stop_id' => 'stop_id',
                'stop_name' => 'stop_name',
                'stop_lat' => 'stop_lat',
                'stop_lon' => 'stop_lng',
                'location_type' => 'location_type',
                'parent_station' => 'parent_station',
            ],
        ],
        'routes.txt' => [
            'table' => 'gtfs_routes',
            'columns' => [
                'route_id' => 'route_id',
                'route_short_name' => 'route_short_name',
                'route_long_name' => 'route_long_name',
                'route_type' => 'route_type',
                'route_color' => 'route_color',
            ],
        ],
        'trips.txt' => [
            'table' => 'gtfs_trips',
            'columns' => [
                'trip_id' => 'trip_id',
                'route_id' => 'route_id',
                'service_id' => 'service_id',
                'trip_headsign' => 'trip_headsign',
                'direction_id' => 'direction_id',
            ],
        ],
        'stop_times.txt' => [
            'table' => 'gtfs_stop_times',
            'columns' => [
                'trip_id' => 'trip_id',
                'stop_id' => 'stop_id',
                'arrival_time' => 'arrival_time',
                'departure_time' => 'departure_time',
                'stop_sequence' => 'stop_sequence',
            ],
        ],
        'calendar.txt' => [
            'table' => 'gtfs_calendar',
            'columns' => [
                'service_id' => 'service_id',
                'monday' => 'monday',
                'tuesday' => 'tuesday',
                'wednesday' => 'wednesday',
                'thursday' => 'thursday',
                'friday' => 'friday',
                'saturday' => 'saturday',
                'sunday' => 'sunday',
                'start_date' => 'start_date',
                'end_date' => 'end_date',
            ],
        ],
        'calendar_dates.txt' => [
            'table' => 'gtfs_calendar_dates',
            'columns' => [
                'service_id' => 'service_id',
                'date' => 'date',
                'exception_type' => 'exception_type',
            ],
        ],
    ];

    public function handle(): int
    {
        $url = $this->option('url');
        $tempDir = storage_path('app/temp');
        $zipPath = $tempDir.'/gtfs.zip';
        $extractDir = $tempDir.'/gtfs';

        // Ensure temp directory exists
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Download
        $this->info('Downloading GTFS feed...');
        $this->output->write("  URL: {$url}\n");

        try {
            $response = Http::timeout(120)->withOptions(['sink' => $zipPath])->get($url);

            if (! $response->successful()) {
                $this->error("Download failed with status {$response->status()}");

                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Download failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('  Downloaded: '.number_format(filesize($zipPath) / 1024 / 1024, 1).' MB');

        // Extract
        $this->info('Extracting ZIP archive...');

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            $this->error('Failed to open ZIP file');

            return self::FAILURE;
        }

        // Clean extract directory
        if (is_dir($extractDir)) {
            $this->deleteDirectory($extractDir);
        }
        mkdir($extractDir, 0755, true);

        $zip->extractTo($extractDir);
        $zip->close();

        $this->info('  Extracted to: '.$extractDir);

        $requiredFiles = ['stops.txt', 'routes.txt', 'trips.txt', 'stop_times.txt'];
        $missingFiles = array_values(array_filter(
            $requiredFiles,
            fn (string $filename): bool => ! file_exists($extractDir.'/'.$filename),
        ));

        if ($missingFiles !== []) {
            $this->error('Archive is missing required files: '.implode(', ', $missingFiles));
            @unlink($zipPath);
            $this->deleteDirectory($extractDir);

            return self::FAILURE;
        }

        if (! file_exists($extractDir.'/calendar.txt') && ! file_exists($extractDir.'/calendar_dates.txt')) {
            $this->error('Archive is missing a service calendar source (calendar.txt or calendar_dates.txt)');
            @unlink($zipPath);
            $this->deleteDirectory($extractDir);

            return self::FAILURE;
        }

        try {
            // PostgreSQL TRUNCATE is transactional. Keeping every table import
            // in one transaction preserves the previous complete feed if any
            // chunk or later file fails.
            DB::transaction(function () use ($extractDir): void {
                foreach ($this->files as $filename => $config) {
                    $filePath = $extractDir.'/'.$filename;

                    if (! file_exists($filePath)) {
                        // Optional calendar variants still replace their table:
                        // retaining rows from the prior feed would mix service
                        // dates with the newly imported trips.
                        DB::table($config['table'])->truncate();
                        $this->warn("  Cleared optional {$filename} table — file not found in archive");

                        continue;
                    }

                    $this->importFile($filePath, $config['table'], $config['columns']);
                }
            });
        } catch (\Throwable $exception) {
            $this->error('GTFS import failed; previous timetable preserved: '.$exception->getMessage());
            @unlink($zipPath);
            $this->deleteDirectory($extractDir);

            return self::FAILURE;
        }

        // Clean up
        $this->info('Cleaning up temporary files...');
        unlink($zipPath);
        $this->deleteDirectory($extractDir);

        $this->newLine();
        $this->info('GTFS import completed successfully.');

        return self::SUCCESS;
    }

    /**
     * Import a single CSV file into the given table.
     *
     * @param  array<string, string>  $columnMap
     */
    protected function importFile(string $filePath, string $table, array $columnMap): void
    {
        $fileSize = filesize($filePath);
        $this->newLine();
        $this->info("Importing {$table} (".number_format($fileSize / 1024 / 1024, 1).' MB)...');

        // Truncate existing data
        DB::table($table)->truncate();

        // Count lines for progress bar (subtract 1 for header)
        $lineCount = $this->countLines($filePath) - 1;
        $bar = $this->output->createProgressBar($lineCount);
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%%');

        $dbColumns = array_values($columnMap);
        $csvColumns = array_keys($columnMap);
        $inserted = 0;

        LazyCollection::make(function () use ($filePath) {
            $handle = fopen($filePath, 'r');
            $header = fgetcsv($handle);

            // Trim BOM from first column header
            if ($header && isset($header[0])) {
                $header[0] = preg_replace('/^\x{FEFF}/u', '', $header[0]);
            }

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) === count($header)) {
                    yield array_combine($header, $row);
                }
            }

            fclose($handle);
        })
            ->chunk(1000)
            ->each(function (LazyCollection $chunk) use ($table, $csvColumns, $dbColumns, $bar, &$inserted) {
                $rows = [];

                // GTFS date columns that need YYYYMMDD → YYYY-MM-DD conversion
                static $dateColumns = ['start_date', 'end_date', 'date'];

                foreach ($chunk as $record) {
                    $row = [];
                    foreach ($csvColumns as $i => $csvCol) {
                        $value = $record[$csvCol] ?? null;
                        $dbCol = $dbColumns[$i];

                        if ($value !== '' && $value !== null && in_array($dbCol, $dateColumns, true) && strlen($value) === 8) {
                            $value = substr($value, 0, 4).'-'.substr($value, 4, 2).'-'.substr($value, 6, 2);
                        }

                        $row[$dbCol] = $value !== '' ? $value : null;
                    }
                    $rows[] = $row;
                }

                DB::table($table)->insert($rows);
                $inserted += count($rows);
                $bar->advance(count($rows));
            });

        $bar->finish();
        $this->newLine();
        $this->info("  Imported {$inserted} rows into {$table}");
    }

    /**
     * Count lines in a file efficiently without loading it into memory.
     */
    protected function countLines(string $filePath): int
    {
        $count = 0;
        $handle = fopen($filePath, 'r');

        while (! feof($handle)) {
            $buffer = fread($handle, 8192);
            $count += substr_count($buffer, "\n");
        }

        fclose($handle);

        return $count;
    }

    /**
     * Recursively delete a directory.
     */
    protected function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
