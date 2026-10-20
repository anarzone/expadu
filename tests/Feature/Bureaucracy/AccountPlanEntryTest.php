<?php

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcess;
use App\Models\User;
use App\Models\UserTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ReviewedHomePlan;

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
});

test('the account entry returns actionable canonical guidance without importing old completion', function () {
    $user = User::factory()->onboarded()->create();
    $fixture = ReviewedHomePlan::activate($user, ['fixture.entry' => ['title' => 'Synthetic preparation']],
        ['arrival_date' => '2026-09-01']);
    $old = UserTask::factory()->completed()->create(['user_id' => $user->id, 'task_id' => $fixture['tasks']['fixture.entry']->id]);
    $before = $old->fresh()->getRawOriginal();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->actingAs($user)->getJson('/bureaucracy/v2/plan')->assertSuccessful()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('state', 'ready')
        ->assertJsonPath('plan.person_id', $fixture['case']->person_id)
        ->assertJsonPath('plan.actions.0.title', 'Synthetic preparation')
        ->assertJsonPath('plan.progress.completed.count', 0)
        ->assertJsonPath('plan.assessment_revision', $fixture['plan']['assessment_revision']);

    $writes = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => preg_match('/^\s*(insert|update|delete)\b/i', $sql));
    DB::disableQueryLog();
    expect($writes)->toBeEmpty()
        ->and(BureaucracyProcess::query()->count())->toBe(0)
        ->and(BureaucracyCaseQuestion::query()->count())->toBe(0)
        ->and($old->fresh()->getRawOriginal())->toBe($before);
});

test('an account without a dossier receives an explicit setup state without creating one', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user)->getJson('/bureaucracy/v2/plan')->assertSuccessful()
        ->assertJsonPath('state', 'setup_required')->assertJsonPath('plan', null);
    expect(BureaucracyPerson::query()->count())->toBe(0)
        ->and($user->bureaucracyCase()->count())->toBe(0);
});

test('an active account identity without a dossier can explicitly finish setup', function () {
    $user = User::factory()->onboarded()->create();
    $person = app(EnsureAccountHolder::class)->person($user);
    $this->actingAs($user)->getJson('/bureaucracy/v2/plan')->assertSuccessful()
        ->assertJsonPath('state', 'setup_required')->assertJsonPath('plan', null);
    expect($person->dossier()->exists())->toBeFalse();
    $this->postJson('/bureaucracy/v2/people/self')->assertSuccessful();
    $this->getJson('/bureaucracy/v2/plan')->assertSuccessful()->assertJsonPath('state', 'ready')
        ->assertJsonPath('plan.person_id', $person->id)->assertJsonPath('plan.coverage.state', 'not_activated');
});

test('the same account entry rechecks source withdrawal and changed city on every request', function () {
    $user = User::factory()->onboarded()->create();
    $fixture = ReviewedHomePlan::activate($user, ['fixture.entry' => ['title' => 'Synthetic preparation']],
        ['arrival_date' => '2026-09-01']);
    $this->actingAs($user)->getJson('/bureaucracy/v2/plan')->assertSuccessful()->assertJsonCount(1, 'plan.actions');
    $fixture['tasks']['fixture.entry']->update(['review_status' => 'legacy']);
    $this->getJson('/bureaucracy/v2/plan')->assertSuccessful()->assertJsonCount(0, 'plan.actions');
    User::query()->whereKey($user->id)->update(['city' => 'Berlin']);
    $this->getJson('/bureaucracy/v2/plan')->assertSuccessful()->assertJsonPath('plan.coverage.state', 'outside_coverage');
});

test('a closed or erased dossier is unavailable rather than offered a silent reset', function (string $state) {
    $user = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($user);
    $case->update(['status' => $state]);
    $this->actingAs($user)->getJson('/bureaucracy/v2/plan')->assertSuccessful()
        ->assertJsonPath('state', 'record_unavailable')->assertJsonPath('plan', null);
    expect($case->fresh()->status)->toBe($state);
})->with(['closed', 'erased']);

test('the account entry never accepts another person or city from query parameters', function () {
    $user = User::factory()->onboarded()->create(['city' => 'Berlin']);
    $self = app(EnsureAccountHolder::class)->dossier($user);
    $other = User::factory()->onboarded()->create();
    $otherCase = app(EnsureAccountHolder::class)->dossier($other);
    $this->actingAs($user)->getJson('/bureaucracy/v2/plan?person_id='.$otherCase->person_id.'&jurisdiction=de-nrw-cologne')
        ->assertSuccessful()->assertJsonPath('plan.person_id', $self->person_id)
        ->assertJsonPath('plan.coverage.state', 'outside_coverage');
});

test('the account entry requires a verified authenticated account', function () {
    $this->getJson('/bureaucracy/v2/plan')->assertUnauthorized();
    $this->actingAs(User::factory()->unverified()->create())->getJson('/bureaucracy/v2/plan')->assertForbidden();
});

test('an inactive or erased account person cannot be silently recreated', function (string $state, bool $withDossier) {
    $user = User::factory()->onboarded()->create();
    $person = $withDossier ? app(EnsureAccountHolder::class)->dossier($user)->person : app(EnsureAccountHolder::class)->person($user);
    $person->update(['record_status' => $state]);
    $before = $person->fresh()->getRawOriginal();
    $this->actingAs($user)->getJson('/bureaucracy/v2/plan')->assertSuccessful()
        ->assertJsonPath('state', 'record_unavailable')->assertJsonPath('plan', null);
    expect($person->fresh()->getRawOriginal())->toBe($before)
        ->and(BureaucracyPerson::query()->count())->toBe(1)
        ->and($person->dossier()->exists())->toBe($withDossier);
})->with(['inactive', 'erased'])->with([false, true]);

test('verification revoked between requests stops the account entry even with a stale authenticated model', function () {
    $user = User::factory()->onboarded()->create();
    app(EnsureAccountHolder::class)->dossier($user);
    $this->actingAs($user)->getJson('/bureaucracy/v2/plan')->assertSuccessful()->assertJsonPath('state', 'ready');
    User::query()->whereKey($user->id)->update(['email_verified_at' => null]);
    $this->getJson('/bureaucracy/v2/plan')->assertForbidden();
});

test('skipped onboarding dates become a visible question without hiding known preparation', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    $this->actingAs($user)->post('/onboarding/complete', [
        'situation' => 'non_eu_employee', 'veedel' => 'Ehrenfeld',
        'arrival_planned' => false, 'arrival_date' => '2026-09-01',
    ])->assertSessionHasNoErrors();
    $fixture = ReviewedHomePlan::activate($user, ['fixture.occupancy' => [
        'title' => 'Synthetic occupancy preparation', 'deadline_type' => 'days_since_move_in',
        'applies_if' => [['citizenship_group' => 'non_eu']],
    ]], []);
    $plan = $this->getJson('/bureaucracy/v2/plan')->assertSuccessful()
        ->assertJsonPath('plan.overview.question.fact_key', 'moved_in_at')
        ->assertJsonPath('plan.actions.0.dates.0.state', 'date_unknown')
        ->assertJsonPath('plan.actions.0.dates.0.date', null)
        ->assertJsonPath('plan.actions.0.title', 'Synthetic occupancy preparation')->json('plan');
    expect(BureaucracyCaseQuestion::query()->count())->toBe(0);
    $session = $this->postJson('/bureaucracy/v2/people/'.$plan['person_id'].'/question-sessions', [
        'jurisdiction' => 'de-nrw-cologne', 'request_id' => (string) Str::uuid(),
    ])->assertCreated()->json('session_id');
    $offer = $this->postJson('/bureaucracy/v2/question-sessions/'.$session.'/next', [
        'request_id' => (string) Str::uuid(),
    ])->assertSuccessful()->json('question');
    $this->postJson('/bureaucracy/v2/question-sessions/'.$session.'/defer/'.$offer['id'], ['token' => $offer['token']])->assertSuccessful();
    $this->getJson('/bureaucracy/v2/plan')->assertSuccessful()
        ->assertJsonPath('plan.actions.0.title', 'Synthetic occupancy preparation')
        ->assertJsonPath('plan.actions.0.dates.0.date', null);
    expect($fixture['case']->facts()->where('key', 'moved_in_at')->exists())->toBeFalse();
});

test('a supplied move-in date drives the new plan independently of registration proof', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    $this->actingAs($user)->post('/onboarding/complete', [
        'situation' => 'other', 'veedel' => 'Ehrenfeld', 'arrival_planned' => false, 'arrival_date' => '2026-09-01',
        'moved_in_at' => '2026-09-05', 'address_registration_status' => 'not_registrable',
    ])->assertSessionHasNoErrors();
    ReviewedHomePlan::activate($user, ['fixture.occupancy' => ['deadline_type' => 'days_since_move_in']], []);
    $this->getJson('/bureaucracy/v2/plan')->assertSuccessful()
        ->assertJsonPath('plan.actions.0.dates.0.date', '2026-09-19')
        ->assertJsonPath('plan.actions.0.dates.0.state', 'dated');
});
