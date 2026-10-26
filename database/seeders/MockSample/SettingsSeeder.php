<?php

namespace Database\Seeders\MockSample;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Settings for tools/mock-compare (settings.json): the compared account as
 * the mock's settings-next previews an email account, named Anar, in
 * Cologne, with personalised suggestions off. Local only.
 *
 * E2E_EMAIL=<you>@expadu.test php artisan db:seed --class="Database\Seeders\MockSample\SettingsSeeder"
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('The mock sample is local only.');

            return;
        }

        $user = User::query()->where('email', (string) env('E2E_EMAIL', 'e2e@expadu.test'))->firstOrFail();
        $user->forceFill(['name' => 'Anar', 'city' => 'Cologne'])->save();
        $setting = $user->userSetting()->firstOrNew();
        $setting->settings = ['personalisation' => false] + ($setting->settings ?? []);
        $setting->save();

        $this->command?->info("{$user->email} is Settings' sample account.");
    }
}
