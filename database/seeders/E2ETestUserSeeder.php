<?php

namespace Database\Seeders;

use App\Enums\Situation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The dedicated end-to-end browser-test user. Verified + onboarded so the
 * Playwright suite's global-setup (which logs in and waits for /dashboard)
 * never hits the verify-email wall or the onboarding redirect.
 *
 * Defaults mirror tests/Browser/global-setup.ts (e2e@expadu.test / e2e-password);
 * the E2E_EMAIL / E2E_PASSWORD env vars override both in sync.
 *
 * IMPORTANT: this is deliberately NOT registered in DatabaseSeeder — run it
 * explicitly (the CI browser job / local browser runs) so a known-password
 * account can never be created by a production `db:seed`.
 */
class E2ETestUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('E2E_EMAIL', 'e2e@expadu.test');
        $password = (string) env('E2E_PASSWORD', 'e2e-password');

        // forceFill so GUARDED columns persist too — notably email_verified_at,
        // which is not in User::$fillable. Mass-assigning it (updateOrCreate)
        // silently drops it, so a freshly-created user is unverified and the
        // email-verification wall then blocks the browser suite's login.
        User::firstOrNew(['email' => $email])
            ->forceFill([
                'name' => 'E2E',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'onboarded_at' => now(),
                'situation' => Situation::NonEuEmployee,
                'veedel' => 'Ehrenfeld',
                'is_eu' => false,
                'arrival_date' => now()->subMonths(6)->startOfDay(),
                'german_level' => null,
                'city' => 'cologne',
            ])
            ->save();
    }
}
