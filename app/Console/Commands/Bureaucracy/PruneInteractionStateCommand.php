<?php

namespace App\Console\Commands\Bureaucracy;

use App\Models\BureaucracyOnboardingDraft;
use App\Models\BureaucracyQuestionSession;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('bureaucracy:prune-interactions')]
#[Description('Remove expired private drafts and question sessions, retaining confirmed facts')]
class PruneInteractionStateCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = 0;
        foreach ([BureaucracyOnboardingDraft::class, BureaucracyQuestionSession::class] as $model) {
            $count += DB::transaction(function () use ($model): int {
                $ids = $model::query()->where('expires_at', '<=', now()->utc())->orderBy('id')
                    ->limit(500)->lock('for update skip locked')->pluck('id');

                return $model::query()->whereKey($ids)->delete();
            });
        }
        $this->info('Removed '.$count.' expired interaction records. Confirmed facts were retained.');

        return self::SUCCESS;
    }
}
