<?php

use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\PersonDataLifecycle;
use App\Models\BureaucracyOnboardingDraft;
use App\Models\User;
use App\Onboarding\SaveBureaucracyDraft;
use Illuminate\Support\Str;

test('a new user can save resume review and explicitly confirm onboarding through HTTP', function () {
    $actor = User::factory()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $url = '/bureaucracy/v2/people/'.$case->person_id.'/onboarding';
    $id = (string) Str::uuid();
    $this->actingAs($actor)->getJson($url.'/draft')->assertSuccessful()->assertJsonPath('draft', null);
    $this->putJson($url.'/draft', ['draft_id' => $id, 'expected_version' => 0, 'step' => 2,
        'answers' => ['current_residence_title' => ['value' => 'settlement_permit_unknown']]])->assertSuccessful()->assertJsonPath('draft.version', 1);
    $this->getJson($url.'/draft')->assertSuccessful()->assertJsonPath('draft.step', 2)
        ->assertJsonPath('draft.answers.current_residence_title.value', 'settlement_permit_unknown');
    $this->getJson($url.'/review')->assertSuccessful()->assertJsonPath('answers.0.fact_key', 'current_residence_title');
    $request = ['draft_id' => $id, 'draft_version' => 1, 'expected_fact_revision' => 1, 'request_id' => (string) Str::uuid()];
    $this->postJson($url.'/complete', $request)->assertUnprocessable();
    expect($case->facts()->count())->toBe(0);
    $this->postJson($url.'/complete', [...$request, 'confirmed' => true])->assertSuccessful()->assertJsonPath('fact_revision', 2);
    expect($case->facts()->sole()->value)->toBe('settlement_permit_unknown');
});

test('draft validation never flashes private incomplete text on HTML requests', function () {
    $actor = User::factory()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $url = '/bureaucracy/v2/people/'.$case->person_id.'/onboarding';
    $this->actingAs($actor)->put($url.'/draft', ['draft_id' => (string) Str::uuid(), 'expected_version' => 0,
        'step' => 2, 'answers' => ['unregistered_secret' => ['value' => 'private-draft-text']]], ['Accept' => 'text/html'])
        ->assertUnprocessable()->assertSessionMissing('_old_input');
    expect(BureaucracyOnboardingDraft::query()->count())->toBe(0);
});

test('draft cleanup and person erasure remove unfinished text without deleting unrelated confirmed answers', function () {
    $actor = User::factory()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    app(RecordFactChange::class)->execute($actor, $case->person, 'citizenship_group', 'eu', null, 1);
    $save = app(SaveBureaucracyDraft::class);
    $draft = $save->execute($actor, $case->person, (string) Str::uuid(), 0, 2, ['arrival_date' => ['value' => '08/0']]);
    $this->travel(31)->days();
    $this->artisan('bureaucracy:prune-interactions')->assertSuccessful();
    expect(BureaucracyOnboardingDraft::query()->find($draft->id))->toBeNull()->and($case->facts()->count())->toBe(1);
    $draft = $save->execute($actor, $case->person, (string) Str::uuid(), 0, 2, ['arrival_date' => ['value' => '08/0']]);
    app(PersonDataLifecycle::class)->erase($actor, $case->person);
    expect(BureaucracyOnboardingDraft::query()->find($draft->id))->toBeNull();
});

test('an outdated form has an explicit discard path that never clears confirmed history', function () {
    $actor = User::factory()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    app(RecordFactChange::class)->execute($actor, $case->person, 'citizenship_group', 'eu', null, 1);
    $draft = app(SaveBureaucracyDraft::class)->execute($actor, $case->person, (string) Str::uuid(), 0, 2, ['arrival_date' => ['value' => '08/0']]);
    $draft->update(['schema_version' => 'old-schema']);
    $url = '/bureaucracy/v2/people/'.$case->person_id.'/onboarding/draft';
    $this->actingAs($actor)->getJson($url)->assertSuccessful()->assertJsonPath('draft.needs_review', true);
    $this->deleteJson($url, ['draft_id' => $draft->id, 'expected_version' => 1])->assertNoContent();
    $this->getJson($url)->assertSuccessful()->assertJsonPath('draft', null);
    expect($case->facts()->count())->toBe(1)->and($draft->fresh()->payload)->toBeNull();
});
