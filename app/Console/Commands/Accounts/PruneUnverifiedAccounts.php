<?php

namespace App\Console\Commands\Accounts;

use App\Mail\UnverifiedAccountReminder;
use App\Models\User;
use App\Privacy\DeleteAccount;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Accounts whose address is never verified can't use the app and are usually
 * a bot's (config/cleanup.php). A week before the 30 days are up the address
 * gets one reminder with a fresh link; an account still unverified at least
 * a week after its reminder is deleted as a person deleting their own would.
 * Admins and accounts from Google or Apple sign-in are never touched.
 */
class PruneUnverifiedAccounts extends Command
{
    protected $signature = 'accounts:prune-unverified {--dry-run : Say what would happen, change nothing}';

    protected $description = 'Remind, then delete, accounts whose email was never verified';

    public function handle(DeleteAccount $deleteAccount): int
    {
        $days = (int) config('cleanup.accounts.unverified_days');
        $notice = (int) config('cleanup.accounts.reminder_days_before');
        $dry = (bool) $this->option('dry-run');

        $toRemind = $this->unverified()->whereNull('verification_reminded_at')
            ->where('created_at', '<', now()->subDays($days - $notice))->get();
        foreach ($toRemind as $user) {
            if (! $dry) {
                Mail::to($user)->send(new UnverifiedAccountReminder($user, now()->addDays($notice)->endOfDay()));
                $user->forceFill(['verification_reminded_at' => now()])->save();
            }
        }

        $toDelete = $this->unverified()->where('created_at', '<', now()->subDays($days))
            ->where('verification_reminded_at', '<', now()->subDays($notice)->startOfDay())->get();
        foreach ($toDelete as $user) {
            if (! $dry) {
                $deleteAccount->delete($user);
            }
        }

        $summary = ($dry ? 'Would remind ' : 'Reminded ').$toRemind->count().', '.($dry ? 'would delete ' : 'deleted ').$toDelete->count().'.';
        if (! $dry && ($toRemind->isNotEmpty() || $toDelete->isNotEmpty())) {
            Log::info('accounts.prune_unverified', ['reminded' => $toRemind->count(), 'deleted' => $toDelete->count()]);
        }
        $this->info($summary);

        return self::SUCCESS;
    }

    /** @return Builder<User> */
    private function unverified(): Builder
    {
        return User::query()->whereNull('email_verified_at')->where('is_admin', false)
            ->whereNull('social_id')->whereNull('apple_id');
    }
}
