<?php

namespace App\Console\Commands;

use App\Models\AccountExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('account-exports:prune')]
#[Description('Delete "Download your data" files 24 hours after they were ready, and failed or stuck requests')]
class PruneAccountExports extends Command
{
    public function handle(): int
    {
        $gone = AccountExport::query()
            ->where(fn ($q) => $q->where('expires_at', '<=', now())
                // Never built (a failed or lost job): gone after a day too.
                ->orWhere(fn ($stuck) => $stuck->whereNull('expires_at')->where('created_at', '<=', now()->subDay())))
            ->get();

        foreach ($gone as $export) {
            if ($export->path !== null) {
                Storage::disk('local')->delete($export->path);
            }
            $export->delete();
        }

        $this->info("Pruned {$gone->count()} exports.");

        return self::SUCCESS;
    }
}
