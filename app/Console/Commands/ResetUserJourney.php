<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Profile\ResetLegacyJourney;
use Illuminate\Console\Command;

/**
 * Testing tool: send a user back to square one so the full journey —
 * onboarding (any persona) → bureaucracy path → teasers → life events —
 * can be replayed without registering a fresh account every time.
 *
 * Legacy discovery profiles only. A dossier/person record must use the
 * canonical correction or erasure flow, never a partial profile reset.
 */
class ResetUserJourney extends Command
{
    protected $signature = 'user:reset-journey
        {email : The account to reset}
        {--keep-tasks : Keep bureaucracy progress (only redo onboarding)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Reset a legacy profile only when no bureaucracy dossier or person exists';

    public function handle(ResetLegacyJourney $resetJourney): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("No user with email {$this->argument('email')}");

            return self::FAILURE;
        }

        $taskCount = $user->userTasks()->count();
        $summary = 'onboarding answers, profile attributes + change log, bureaucracy path'
            .($this->option('keep-tasks') ? '' : ", {$taskCount} task record(s)");

        if (! $this->option('force')
            && ! $this->confirm("Reset {$user->email}? This wipes: {$summary}.")) {
            return self::FAILURE;
        }

        if (! $resetJourney->execute($user, (bool) $this->option('keep-tasks'))) {
            $this->error('This account has bureaucracy records. Use read-only persona preview for QA, or the dedicated correction/erasure flow. Nothing was reset.');

            return self::FAILURE;
        }

        $this->info("Done — {$user->email} restarts at onboarding on their next visit.");

        return self::SUCCESS;
    }
}
