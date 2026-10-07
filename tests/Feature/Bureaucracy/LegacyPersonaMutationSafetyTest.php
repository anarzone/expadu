<?php

use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\QA\ResetPersonaState;
use App\Models\User;
use App\Models\UserTask;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

test('obsolete persona mutations cannot reset a real account or its dossier', function (string $url) {
    $user = User::factory()->onboarded()->create(['is_admin' => true]);
    $case = app(EnsureAccountHolder::class)->dossier($user);
    app(RecordFactChange::class)->execute($user, $case->person, 'current_residence_title', 'blue_card', null, $case->fact_version);
    UserTask::factory()->completed()->create(['user_id' => $user->id]);
    $other = User::factory()->onboarded()->create();
    app(EnsureAccountHolder::class)->dossier($other);
    $tables = ['users', 'user_tasks', 'bureaucracy_people', 'bureaucracy_cases', 'bureaucracy_case_facts',
        'bureaucracy_processes', 'bureaucracy_outbox_events', 'bureaucracy_question_sessions'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => hash('sha256', DB::table($table)->orderBy('id')->get()->toJson())])->all();
    $before = $snapshot();

    $response = $this->actingAs($user)->postJson($url);
    expect($snapshot())->toBe($before);
    $response->assertGone()->assertJsonPath('code', 'qa_mutation_retired');
})->with(['/qa/become/neu-student', '/qa/reset-tasks']);

test('obsolete persona mutations are unavailable before a dossier exists too', function (string $url) {
    $user = User::factory()->notOnboarded()->create(['is_admin' => true]);
    $before = $user->fresh()->getRawOriginal();
    $this->actingAs($user)->postJson($url)->assertGone()->assertJsonPath('code', 'qa_mutation_retired');
    expect($user->fresh()->getRawOriginal())->toBe($before)
        ->and($user->bureaucracyCase()->count())->toBe(0);
})->with(['/qa/become/neu-student', '/qa/reset-tasks']);

test('calling the retired reset service directly cannot delete a dossier either', function () {
    $user = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($user);
    $before = $case->fresh()->getRawOriginal();
    expect(fn () => app(ResetPersonaState::class)->execute($user))->toThrow(HttpResponseException::class);
    expect($case->fresh()->getRawOriginal())->toBe($before);
});

test('local environment and a stale admin model do not grant QA mutation access', function (string $url) {
    $this->app->instance('env', 'local');
    $user = User::factory()->onboarded()->create(['is_admin' => true]);
    $this->actingAs($user);
    User::query()->whereKey($user->id)->update(['is_admin' => false]);
    $this->withSession(['_token' => 'qa-test-csrf'])->postJson($url, ['_token' => 'qa-test-csrf'])->assertForbidden();
})->with(['/qa/become/neu-student', '/qa/reset-tasks']);

test('legacy reset flags cannot bypass protection for a person without a dossier', function (bool $keepTasks) {
    $user = User::factory()->onboarded()->create();
    $person = app(EnsureAccountHolder::class)->person($user);
    $before = $user->fresh()->getRawOriginal();
    UserTask::factory()->completed()->create(['user_id' => $user->id]);
    $this->artisan('user:reset-journey', ['email' => $user->email, '--force' => true, '--keep-tasks' => $keepTasks])
        ->assertFailed();
    expect($user->fresh()->getRawOriginal())->toBe($before)
        ->and($user->userTasks()->count())->toBe(1)
        ->and($person->fresh())->not->toBeNull();
})->with([false, true]);

test('a genuinely legacy profile can still explicitly reset without touching another persons records', function (bool $keepTasks) {
    $user = User::factory()->onboarded()->create();
    UserTask::factory()->completed()->create(['user_id' => $user->id]);
    $other = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($other);
    $before = $case->fresh()->getRawOriginal();
    $this->artisan('user:reset-journey', ['email' => $user->email, '--force' => true, '--keep-tasks' => $keepTasks])
        ->assertSuccessful();
    expect($user->fresh()->onboarded_at)->toBeNull()
        ->and($user->userTasks()->count())->toBe($keepTasks ? 1 : 0)
        ->and($case->fresh()->getRawOriginal())->toBe($before);
})->with([false, true]);
