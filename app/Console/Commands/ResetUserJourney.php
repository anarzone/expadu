<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Testing tool: send a user back to square one so the full journey —
 * onboarding (any persona) → bureaucracy path → teasers → life events —
 * can be replayed without registering a fresh account every time.
 *
 * Works the same everywhere: locally, on staging, and on prod test
 * accounts via `docker exec <app> php artisan user:reset-journey …`.
 */
class ResetUserJourney extends Command
{
    protected $signature = 'user:reset-journey
        {email : The account to reset}
        {--keep-tasks : Keep bureaucracy progress (only redo onboarding)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Reset a user to pre-onboarding state to replay the full journey';

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

        $user->update([
            'onboarded_at' => null,
            'situation' => null,
            'is_eu' => null,
            'veedel' => null,
            'arrival_date' => null,
            'german_level' => null,
            'bureaucracy_path' => null,
            'profile_attributes' => null,
        ]);
        $user->attributeChanges()->delete();

        if (! $this->option('keep-tasks')) {
            $user->userTasks()->delete();
        }

        $this->info("Done — {$user->email} restarts at onboarding on their next visit.");

        return self::SUCCESS;
    }
}
