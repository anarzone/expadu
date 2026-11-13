<?php

namespace App\Filament\Auth;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Laravel\Fortify\RecoveryCode;

/**
 * The admin panel's code from an authenticator app, sharing the app's
 * two-step sign-in (Fortify): the same secret (User::getAppAuthenticationSecret)
 * and the same recovery codes. Fortify keeps those encrypted, not hashed, so
 * they're checked and replaced its way: a used code gives way to a new one,
 * as on the app's own challenge.
 */
class FortifyAppAuthentication extends AppAuthentication
{
    /** @return array<string> */
    public function generateRecoveryCodes(): array
    {
        return array_map(fn (): string => RecoveryCode::generate(), range(1, 8));
    }

    /** @param  array<string>|null  $codes */
    public function saveRecoveryCodes(HasAppAuthenticationRecovery $user, ?array $codes): void
    {
        $user->saveAppAuthenticationRecoveryCodes($codes);
    }

    public function verifyRecoveryCode(string $recoveryCode, ?HasAppAuthenticationRecovery $user = null): bool
    {
        $user ??= Filament::auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $match = collect($user->getAppAuthenticationRecoveryCodes() ?? [])
            ->first(fn (string $code): bool => hash_equals($code, trim($recoveryCode)));
        if ($match === null) {
            return false;
        }

        $user->replaceRecoveryCode($match);

        return true;
    }
}
