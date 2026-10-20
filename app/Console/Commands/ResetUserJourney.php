<?php

namespace App\Console\Commands;

use App\Models\BureaucracyPerson;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

    public function handle(): int
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

        $reset = DB::transaction(function () use ($user): bool {
            $locked = User::query()->whereKey($user->id)->lock('for no key update')->firstOrFail();
            if ($locked->bureaucracyCase()->exists()
                || BureaucracyPerson::query()->where('account_user_id', $locked->id)->exists()) {
                return false;
            }
            $locked->update([
                'onboarded_at' => null,
                'situation' => null,
                'is_eu' => null,
                'veedel' => null,
                'arrival_date' => null,
                'german_level' => null,
                'bureaucracy_path' => null,
                'profile_attributes' => null,
            ]);
            $locked->attributeChanges()->delete();
            if (! $this->option('keep-tasks')) {
                $locked->userTasks()->delete();
            }

            return true;
        });
        if (! $reset) {
            $this->error('This account has bureaucracy records. Use read-only persona preview for QA, or the dedicated correction/erasure flow. Nothing was reset.');

            return self::FAILURE;
        }

        $this->info("Done — {$user->email} restarts at onboarding on their next visit.");

        return self::SUCCESS;
    }
}
