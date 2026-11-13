<?php

use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(fn () => Filament::setCurrentPanel('admin'));

/** An admin with the app's two-step sign-in on: its secret and recovery codes. */
function adminWithTwoFactor(): array
{
    $secret = app(Google2FA::class)->generateSecretKey();
    $codes = ['aaaaaaaaaa-bbbbbbbbbb', 'cccccccccc-dddddddddd'];
    $admin = User::factory()->create(['is_admin' => true]);
    $admin->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($codes)),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return [$admin, $secret, $codes];
}

test('🔒 an admin without two-step sign-in must set it up before the panel opens', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin, 'admin')->get('/admin')
        ->assertRedirect('/admin/multi-factor-authentication/set-up');
    $this->actingAs($admin, 'admin')->get('/admin/multi-factor-authentication/set-up')->assertOk();
});

test('🔒 an admin’s password alone opens nothing; the authenticator code from the app’s own set-up does', function () {
    [$admin, $secret] = adminWithTwoFactor();

    $login = Livewire::test(Login::class)
        ->fillForm(['email' => $admin->email, 'password' => 'password'])
        ->call('authenticate');

    $this->assertGuest('admin');
    expect($login->get('userUndertakingMultiFactorAuthentication'))->not->toBeNull();

    $login->set('data.multiFactor.app.code', '000000')->call('authenticate')->assertHasErrors();
    $this->assertGuest('admin');

    $login->set('data.multiFactor.app.code', app(Google2FA::class)->getCurrentOtp($secret))->call('authenticate');
    $this->assertAuthenticatedAs($admin, 'admin');
});

test('🔒 one of the app’s recovery codes works once, and is replaced', function () {
    [$admin, , $codes] = adminWithTwoFactor();

    Livewire::test(Login::class)
        ->fillForm(['email' => $admin->email, 'password' => 'password'])
        ->call('authenticate')
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', $codes[0])
        ->call('authenticate');

    $this->assertAuthenticatedAs($admin, 'admin');
    expect($admin->fresh()->recoveryCodes())->not->toContain($codes[0])->toContain($codes[1])->toHaveCount(2);
});

test('🔒 setting it up in the panel turns on the app’s two-step sign-in with the same secret', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $secret = app(Google2FA::class)->generateSecretKey();

    $admin->saveAppAuthenticationSecret($secret);

    expect($admin->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and($admin->fresh()->getAppAuthenticationSecret())->toBe($secret);
});
