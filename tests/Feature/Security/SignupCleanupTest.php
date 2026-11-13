<?php

use App\Mail\UnverifiedAccountReminder;
use App\Models\User;
use App\Models\WaitlistSignup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    config(['cleanup.waitlist.from' => '2026-01-01']);
});

test('🔒 unconfirmed waitlist entries go after a week; confirmed ones and older-policy ones stay', function () {
    $stale = WaitlistSignup::factory()->create(['created_at' => now()->subDays(8)]);
    $fresh = WaitlistSignup::factory()->create(['created_at' => now()->subDays(6)]);
    $confirmed = WaitlistSignup::factory()->confirmed()->create(['created_at' => now()->subDays(30)]);
    config(['cleanup.waitlist.from' => now()->subDays(20)->toDateString()]);
    $beforePolicy = WaitlistSignup::factory()->create(['created_at' => now()->subDays(25)]);

    $this->artisan('model:prune', ['--model' => [WaitlistSignup::class]])->assertSuccessful();

    expect(WaitlistSignup::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$fresh->id, $confirmed->id, $beforePolicy->id])->sort()->values()->all())
        ->and(WaitlistSignup::query()->find($stale->id))->toBeNull();
});

test('🔒 an unverified account gets one reminder a week before 30 days, with a link that works until then', function () {
    $user = User::factory()->unverified()->create(['created_at' => now()->subDays(24)]);
    User::factory()->unverified()->create(['created_at' => now()->subDays(10)]);

    $this->artisan('accounts:prune-unverified')->assertSuccessful();
    $this->artisan('accounts:prune-unverified')->assertSuccessful();

    Mail::assertSent(UnverifiedAccountReminder::class, 1);
    Mail::assertSent(UnverifiedAccountReminder::class, fn (UnverifiedAccountReminder $mail) => $mail->hasTo($user->email));
    expect($user->fresh()->verification_reminded_at)->not->toBeNull();

    $html = Mail::sent(UnverifiedAccountReminder::class)->first()->render();
    expect($html)->toContain('/email/verify/'.$user->id)->toContain(now()->addDays(7)->isoFormat('D MMMM'));
});

test('🔒 an account still unverified a week after its reminder is deleted, the whole way', function () {
    $user = User::factory()->unverified()->create(['created_at' => now()->subDays(31)]);
    $user->forceFill(['verification_reminded_at' => now()->subDays(8)])->save();
    $user->createToken('iPhone');
    // Reminded 7 days ago: the mail promised until the end of today.
    $promisedToday = User::factory()->unverified()->create(['created_at' => now()->subDays(31)]);
    $promisedToday->forceFill(['verification_reminded_at' => now()->subDays(7)])->save();

    $this->artisan('accounts:prune-unverified')->assertSuccessful();

    expect(User::query()->find($promisedToday->id))->not->toBeNull()
        ->and(User::query()->find($user->id))->toBeNull()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0);
});

test('🔒 no account goes without its week of notice, and verified, admin, Google and Apple accounts are never touched', function () {
    $justReminded = User::factory()->unverified()->create(['created_at' => now()->subDays(40)]);
    $justReminded->forceFill(['verification_reminded_at' => now()->subDays(2)])->save();
    $verified = User::factory()->create(['created_at' => now()->subDays(90)]);
    $admin = User::factory()->unverified()->create(['created_at' => now()->subDays(90), 'is_admin' => true]);
    $google = User::factory()->unverified()->create(['created_at' => now()->subDays(90), 'social_id' => 'g-1', 'social_provider' => 'google']);
    $apple = User::factory()->unverified()->create(['created_at' => now()->subDays(90)]);
    $apple->forceFill(['apple_id' => 'apple-1'])->save();

    $this->artisan('accounts:prune-unverified')->assertSuccessful();

    expect(User::query()->whereKey([$justReminded->id, $verified->id, $admin->id, $google->id, $apple->id])->count())->toBe(5);
    Mail::assertNothingSent();
});

test('a dry run changes nothing', function () {
    $user = User::factory()->unverified()->create(['created_at' => now()->subDays(40)]);

    $this->artisan('accounts:prune-unverified', ['--dry-run' => true])->expectsOutput('Would remind 1, would delete 0.')->assertSuccessful();

    Mail::assertNothingSent();
    expect($user->fresh()->verification_reminded_at)->toBeNull();
});
