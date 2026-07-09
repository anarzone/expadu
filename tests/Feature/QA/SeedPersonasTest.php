<?php

use App\Bureaucracy\BureaucracyPersonas;
use App\Models\User;

test('seeds one onboarded, verified account per persona plus an admin', function () {
    $this->artisan('qa:seed-personas', ['--force' => true])->assertSuccessful();

    $expectedCount = count(BureaucracyPersonas::demo()) + 1;
    expect(User::count())->toBe($expectedCount);

    $student = User::where('email', 'qa+neu-student@expadu.test')->first();
    expect($student)->not->toBeNull()
        ->and($student->onboarded_at)->not->toBeNull()
        ->and($student->email_verified_at)->not->toBeNull()
        ->and($student->situation->value)->toBe('student')
        ->and($student->is_eu)->toBeFalse();

    $admin = User::where('email', 'qa+admin@expadu.test')->first();
    expect($admin)->not->toBeNull()
        ->and($admin->is_admin)->toBeTrue();
});

test('the planning persona has no arrival date', function () {
    $this->artisan('qa:seed-personas', ['--force' => true])->assertSuccessful();

    $planning = User::where('email', 'qa+planning@expadu.test')->first();

    expect($planning)->not->toBeNull()
        ->and($planning->arrival_date)->toBeNull();
});

test('running the command twice is idempotent', function () {
    $this->artisan('qa:seed-personas', ['--force' => true])->assertSuccessful();
    $countAfterFirstRun = User::count();

    $this->artisan('qa:seed-personas', ['--force' => true])->assertSuccessful();

    expect(User::count())->toBe($countAfterFirstRun);
});

test('a seeded persona account gets Home and Work places', function () {
    $this->artisan('qa:seed-personas', ['--force' => true])->assertSuccessful();

    $user = User::where('email', 'qa+neu-student@expadu.test')->first();

    expect($user->places()->where('category', 'home')->exists())->toBeTrue()
        ->and($user->places()->where('category', 'work')->exists())->toBeTrue();
});

test('without --force, an unrecognised host refuses to run', function () {
    config(['app.url' => 'https://app.expadu.com']);

    $this->artisan('qa:seed-personas')->assertFailed();

    expect(User::where('email', 'like', 'qa+%')->count())->toBe(0);
});
