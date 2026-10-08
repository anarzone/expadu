<?php

namespace Database\Seeders;

use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\RuleSourcePolicy;
use App\Bureaucracy\Verification\ClaimCheck;
use App\Bureaucracy\Verification\SourceCheckRecorder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Browser-test data for Paperwork: a published catalogue and a Blue Card plan for the E2E user.
 *
 * The browser job has no reliable network to the official pages, so a machine-checked card
 * counts as passed here only when its quotes pass every offline part of the check (the same
 * rule the PHP test suite applies). Production publishes only after bureaucracy:verify-sources
 * has read the live pages.
 *
 * IMPORTANT: test-only, like E2ETestUserSeeder. Never registered in DatabaseSeeder.
 */
class E2EPaperworkSeeder extends Seeder
{
    public function run(): void
    {
        $checks = app(ClaimCheck::class);
        $recorder = app(SourceCheckRecorder::class);
        Task::query()->where('source_verification', RuleSourcePolicy::QuoteChecked)->where('is_published', true)->each(function (Task $task) use ($checks, $recorder): void {
            if ($checks->offlineErrors($task->attributesToArray()) === []) {
                $recorder->record($task, ['outcome' => 'passed', 'failures' => [], 'unreachable' => []]);
                $recorder->apply($task);
            }
        });
        Artisan::call('bureaucracy:compile-catalogue', ['--deploy' => true]);

        $user = User::query()->where('email', (string) env('E2E_EMAIL', 'e2e@expadu.test'))->firstOrFail();
        $case = app(EnsureAccountHolder::class)->dossier($user);
        if ($case->facts()->exists()) {
            return; // Keep a returning test account's own answers.
        }
        $facts = ['purpose' => 'employment', 'citizenship_group' => 'non_eu', 'current_residence_title' => 'national_d_visa',
            'entry_mode' => 'd_visa', 'case_goal' => 'blue_card', 'arrival_planned' => false, 'registration_status' => 'not_registered',
            'visa_expires_at' => now()->addDays(3)->toDateString(), 'health_coverage_confirmed' => false];
        foreach ($facts as $key => $value) {
            app(RecordFactChange::class)->execute($user, $case->person->fresh(), $key, $value, null, $case->fresh()->fact_version);
        }
    }
}
