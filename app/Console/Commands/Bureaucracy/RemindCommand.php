<?php

namespace App\Console\Commands\Bureaucracy;

use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Bureaucracy\ReadModel\PlanAttention;
use App\Bureaucracy\ReadModel\ReassessPerson;
use App\Models\BureaucracyPerson;
use App\Models\User;
use Illuminate\Console\Command;

final class RemindCommand extends Command
{
    protected $signature = 'bureaucracy:remind {--user= : Only process this user id} {--dry-run : Read current plans without writing processes, cache or notifications}';

    protected $description = 'Surface attention events from the shared account-holder plan';

    public function handle(ReassessPerson $refresh, AccountHolderPlan $plans, PlanAttention $attention): int
    {
        $usersProcessed = 0;
        $eventsEvaluated = 0;
        $query = User::query()->whereNotNull('onboarded_at')->whereNotNull('email_verified_at')
            ->whereIn('id', BureaucracyPerson::query()->where('record_status', 'active')->whereNotNull('account_user_id')->select('account_user_id'));
        if ($specific = $this->option('user')) {
            $query->whereKey((int) $specific);
        }
        $query->chunkById(100, function ($users) use ($refresh, $plans, $attention, &$usersProcessed, &$eventsEvaluated): void {
            foreach ($users as $user) {
                $usersProcessed++;
                $eventsEvaluated += count($attention->for($plans->for($user)));
                if (! $this->option('dry-run')) {
                    $personId = BureaucracyPerson::query()->where('account_user_id', $user->id)->where('record_status', 'active')->value('id');
                    if ($personId !== null) {
                        $refresh->execute($personId);
                    }
                }
            }
        });
        $this->info("Done. users={$usersProcessed} attention_events_evaluated={$eventsEvaluated}");

        return self::SUCCESS;
    }
}
