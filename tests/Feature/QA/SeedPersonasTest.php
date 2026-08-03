<?php

use App\Bureaucracy\BureaucracyPersonas;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\User;
use App\Models\UserTask;
use Illuminate\Support\Facades\Hash;

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
    $countsAfterFirstRun = [
        User::count(),
        BureaucracyCase::count(),
        BureaucracyCaseFact::count(),
        UserTask::count(),
    ];

    $this->artisan('qa:seed-personas', ['--force' => true])->assertSuccessful();

    expect([
        User::count(),
        BureaucracyCase::count(),
        BureaucracyCaseFact::count(),
        UserTask::count(),
    ])->toBe($countsAfterFirstRun);
});

test('case personas seed confirmed scenario facts and keep the documented password', function () {
    $this->artisan('qa:seed-personas', ['--force' => true])->assertSuccessful();

    $user = User::where('email', 'qa+case-blue-card-first@expadu.test')->firstOrFail();
    $case = BureaucracyCase::where('user_id', $user->id)->firstOrFail();

    expect(Hash::check('password', $user->password))->toBeTrue()
        ->and($case->facts()->where('source', 'qa_scenario:case-blue-card-first')->where('state', 'confirmed')->count())
        ->toBe(count(collect(BureaucracyPersonas::caseScenarios())->firstWhere('key', 'case-blue-card-first')['facts']));
});

test('re-seeding a case persona refreshes a stale scenario fact without leaving two confirmed values', function () {
    $this->artisan('qa:seed-personas', ['--force' => true])->assertSuccessful();

    $user = User::where('email', 'qa+case-blue-card-first@expadu.test')->firstOrFail();
    $case = BureaucracyCase::where('user_id', $user->id)->firstOrFail();
    $staleFact = $case->facts()
        ->where('source', 'qa_scenario:case-blue-card-first')
        ->where('key', 'case_goal')
        ->where('state', 'confirmed')
        ->firstOrFail();
    $staleFact->update(['reconfirm_at' => now()->subSecond()]);

    $this->artisan('qa:seed-personas', ['--force' => true])->assertSuccessful();

    expect($staleFact->refresh()->state)->toBe('superseded')
        ->and($case->facts()->where('key', 'case_goal')->where('state', 'confirmed')->count())->toBe(1)
        ->and($case->facts()->where('key', 'case_goal')->where('state', 'confirmed')->firstOrFail()->reconfirm_at->isFuture())->toBeTrue();
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
