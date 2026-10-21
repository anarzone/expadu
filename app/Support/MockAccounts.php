<?php

namespace App\Support;

use App\Enums\Situation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

/**
 * Local only: the accounts tools/mock-compare signs in as. Each session
 * compares as its own account (mock-s1@ … mock-s4@expadu.test), and a config
 * that needs an untouched one uses a sibling of it (<name>+<sibling>@…), so a
 * missing one is made as E2ETestUserSeeder makes the E2E account: verified
 * and onboarded, with the E2E password. It refuses outside local.
 */
final class MockAccounts
{
    /** @param  callable(string): void|null  $created  told the email when an account is made */
    public static function ensure(string $email, ?callable $created = null): User
    {
        if (! app()->environment('local')) {
            throw new LogicException('Mock comparison accounts are local only.');
        }

        $user = User::query()->where('email', $email)->first();
        if ($user !== null) {
            return $user;
        }

        $user = new User;
        // forceFill: email_verified_at and onboarded_at are not fillable.
        $user->forceFill([
            'email' => $email,
            'name' => Str::of($email)->before('@')->before('+')->headline()->toString(),
            'password' => Hash::make((string) env('E2E_PASSWORD', 'e2e-password')),
            'email_verified_at' => now(),
            'onboarded_at' => now(),
            'situation' => Situation::NonEuEmployee,
            'veedel' => 'Ehrenfeld',
            'is_eu' => false,
            'arrival_date' => now()->subMonths(6)->startOfDay(),
            'german_level' => null,
            'city' => 'cologne',
        ])->save();

        if ($created !== null) {
            $created($email);
        }

        return $user;
    }

    /** The comparison account's sibling for one config: <name>+<sibling>@… */
    public static function sibling(string $email, string $sibling): string
    {
        return str_contains(Str::before($email, '@'), "+{$sibling}")
            ? $email
            : Str::before($email, '@')."+{$sibling}@".Str::after($email, '@');
    }
}
