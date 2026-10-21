<?php

namespace Database\Seeders\MockSample;

use App\Composer\WorkspaceStore;
use App\Models\ComposerWorkspace;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Local only: the conversations tools/mock-compare/configs/composer-answers*.json compare.
 * On the mock's sample moment (Wednesday 30 September 2026, 13:10) it asks the requests
 * those configs ask the mock, and Composer answers each one through its own `message` and
 * `answer` commands. The page sends `answer` only when parse() says Composer answers the
 * request (Answers::handles, which defers to the prompt parser); asked directly, each answer
 * shows as Composer draws it, whichever parser is on. It starts from MockSample\ComposerSeeder's
 * state (Events' sample, nothing saved, one new conversation).
 *
 *   E2E_EMAIL=you@expadu.test COMPOSER_ANSWERS=main php artisan db:seed --class='Database\Seeders\MockSample\ComposerAnswersSeeder'
 *
 * IMPORTANT: never registered in DatabaseSeeder; it refuses to run outside local.
 */
class ComposerAnswersSeeder extends Seeder
{
    /**
     * The conversations the configs compare, by COMPOSER_ANSWERS (default `main`). Only the
     * latest reply offers next steps, so each ends with the reply whose steps it compares.
     */
    public const REQUESTS = [
        // composer-answers.json: the Paperwork starter's request first, a clarify (and its options) last.
        'main' => ['What do I need to bring at 14:00?', 'Is the cinema step-free?', 'blorp fizz'],
        // composer-answers-help.json: what's next, then help and its next steps.
        'help' => ['When is my next appointment?', 'Hi'],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->error('Refusing: the Composer sample is for local mock comparisons only.');

            return;
        }

        $user = User::query()->where('email', (string) env('E2E_EMAIL', 'e2e@expadu.test'))->first();
        if ($user === null) {
            $this->command?->error('No E2E account. Run the E2ETestUserSeeder first.');

            return;
        }

        $set = (string) env('COMPOSER_ANSWERS', 'main');
        if (! isset(self::REQUESTS[$set])) {
            $this->command?->error('COMPOSER_ANSWERS is one of: '.implode(', ', array_keys(self::REQUESTS)).'.');

            return;
        }

        $this->call(ComposerSeeder::class);

        $moment = CarbonImmutable::parse('2026-09-30 13:10', 'Europe/Berlin');
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);

        try {
            $store = app(WorkspaceStore::class);
            foreach (self::REQUESTS[$set] as $text) {
                $revision = fn (): int => (int) ComposerWorkspace::query()->where('user_id', $user->id)->value('revision');
                $store->command($user, ['revision' => $revision(), 'action' => 'message', 'text' => $text]);
                $store->command($user, ['revision' => $revision(), 'action' => 'answer']);
            }
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }

        $this->command?->info("Composer answered the {$set} sample requests.");
    }
}
