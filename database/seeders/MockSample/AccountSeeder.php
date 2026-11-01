<?php

namespace Database\Seeders\MockSample;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Sign-in's one state that needs an account, for tools/mock-compare: someone
 * who has just created theirs and not opened the link yet (the mock's
 * #check-email, prototype/dev/design/account/). Local only; never runs
 * anywhere else. Seed a comparison database of your own, never the shared one:
 *
 * DB_DATABASE=expadu_s4 php artisan db:seed --class="Database\Seeders\MockSample\AccountSeeder"
 */
class AccountSeeder extends Seeder
{
    public const EMAIL = 'new.arrival@example.com';

    public const PASSWORD = 'check-email-password';

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('The mock sample is local only.');

            return;
        }

        // forceFill: email_verified_at isn't fillable, and it must stay empty.
        User::firstOrNew(['email' => self::EMAIL])
            ->forceFill([
                'name' => 'New arrival',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => null,
            ])
            ->save();
    }
}
