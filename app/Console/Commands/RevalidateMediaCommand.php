<?php

namespace App\Console\Commands;

use App\Jobs\ValidateMediaAssetJob;
use App\Media\MediaAssetValidator;
use App\Models\MediaAsset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\OutputInterface;

#[Signature('media:revalidate {--limit=200 : Maximum due attached assets to queue}')]
#[Description('Queue due media health checks with stale-input protection')]
class RevalidateMediaCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 2000) {
            $this->error('Limit must be between 1 and 2000.');

            return self::FAILURE;
        }

        $now = now()->utc();
        $leaseCutoff = $now->copy()->subMinutes((int) config('media.validation.queue_lease_minutes', 30));
        $assets = MediaAsset::query()
            ->whereHas('attachments')
            ->where(fn ($query) => $query->whereNull('next_validation_at')->orWhere('next_validation_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('validation_queued_at')->orWhere('validation_queued_at', '<=', $leaseCutoff))
            ->orderByRaw('case when next_validation_at is null then 0 else 1 end')
            ->orderBy('next_validation_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

        $queued = 0;
        foreach ($assets as $asset) {
            $claimed = DB::transaction(function () use ($asset, $now, $leaseCutoff): ?array {
                $current = MediaAsset::query()->whereKey($asset->id)->lockForUpdate()->first();
                if ($current === null
                    || ! $current->attachments()->exists()
                    || ($current->next_validation_at !== null && $current->next_validation_at->gt($now))
                    || ($current->validation_queued_at !== null && $current->validation_queued_at->gt($leaseCutoff))) {
                    return null;
                }

                $fingerprint = MediaAssetValidator::inputFingerprint($current);
                DB::table($current->getTable())->where('id', $current->id)->update([
                    'validation_queued_at' => $now,
                    'validation_queued_fingerprint' => $fingerprint,
                ]);
                $current->validation_queued_at = $now;
                $current->validation_queued_fingerprint = $fingerprint;

                return [$current, $fingerprint];
            });

            if ($claimed === null) {
                continue;
            }

            [$claimedAsset, $fingerprint] = $claimed;
            ValidateMediaAssetJob::dispatch($claimedAsset, $fingerprint);
            $queued++;
        }

        $this->output->writeln(json_encode([
            'due_considered' => $assets->count(),
            'queued' => $queued,
            'limit' => $limit,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
