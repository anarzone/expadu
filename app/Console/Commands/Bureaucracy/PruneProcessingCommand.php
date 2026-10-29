<?php

namespace App\Console\Commands\Bureaucracy;

use App\Models\BureaucracyProcessingConsent;
use Illuminate\Console\Command;

class PruneProcessingCommand extends Command
{
    protected $signature = 'bureaucracy:prune-processing';

    protected $description = 'Remove expired encrypted responses and expired minimal consent metadata';

    public function handle(): int
    {
        BureaucracyProcessingConsent::query()->where('expires_at', '<=', now()->utc())->whereNotNull('result')->update(['result' => null]);
        BureaucracyProcessingConsent::query()->where('delete_after', '<=', now()->utc())->delete();

        return self::SUCCESS;
    }
}
