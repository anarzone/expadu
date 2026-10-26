<?php

namespace App\Jobs;

use App\Models\AccountExport;
use App\Privacy\Export\AccountData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Builds a person's "Download your data" ZIP: one JSON file per area and a
 * README naming each (AccountData). It goes on the private disk, nowhere
 * else, and can be downloaded for 24 hours.
 */
class BuildAccountExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public AccountExport $export)
    {
        $this->onConnection('redis');
        $this->onQueue('default');
    }

    public function handle(AccountData $data): void
    {
        $export = $this->export->fresh();
        if ($export === null || $export->status !== 'queued' || $export->user === null) {
            return;
        }

        $path = "exports/{$export->user_id}/{$export->id}.zip";
        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($path));
        $file = $disk->path($path);

        $zip = new ZipArchive;
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export file.');
        }
        foreach ($data->files($export->user) as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        $export->update([
            'status' => 'ready',
            'path' => $path,
            'bytes' => filesize($file) ?: null,
            'ready_at' => now(),
            'expires_at' => now()->addHours(AccountExport::KEEP_HOURS),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->export->fresh()?->update(['status' => 'failed']);
        if ($this->export->path !== null) {
            Storage::disk('local')->delete($this->export->path);
        }
    }
}
