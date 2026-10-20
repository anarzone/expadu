<?php

namespace App\Console\Commands\Bureaucracy;

use App\Models\BureaucracyExtractionCandidate;
use App\Models\BureaucracyProcessingConsent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneProcessingCommand extends Command
{
    protected $signature = 'bureaucracy:prune-processing';

    protected $description = 'Remove expired encrypted responses and expired minimal consent metadata';

    public function handle(): int
    {
        BureaucracyExtractionCandidate::query()->where('expires_at', '<=', now()->utc())
            ->update(['value' => null, 'confirmation_token' => null]);
        BureaucracyExtractionCandidate::query()->where('expires_at', '<=', now()->utc())->where('state', 'pending')->update(['state' => 'expired']);
        BureaucracyProcessingConsent::query()->where('expires_at', '<=', now()->utc())->whereNotNull('result')->update(['result' => null]);
        BureaucracyProcessingConsent::query()->where('delete_after', '<=', now()->utc())->delete();
        DB::table('bureaucracy_processing_legacy_usage')->where('delete_after', '<=', now()->utc())->delete();

        return self::SUCCESS;
    }
}
