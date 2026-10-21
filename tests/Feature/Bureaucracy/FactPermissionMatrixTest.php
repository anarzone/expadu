<?php

use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Models\User;
use Illuminate\Support\Str;

// 🔒 Who may see and who may change a person's answers (PW4): the owner; a
// helper who may only see them (view_facts); one who may only change them
// (edit_facts), who gets the offered question alone and never the current
// value, its history or a conflict; and one with neither. The canary is a
// saved answer that must never reach anyone without view_facts.
//
// That an edit-only helper can't learn a hidden value by answering it (the
// same response for the hidden value and any other) is covered by
// EditOnlyFactBlindnessTest (#207); this matrix and that test together are
// PW4's rule.

function matrixCanary(): string
{
    return '2031-07-19';
}

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    $this->owner = User::factory()->onboarded()->create();
    $this->case = app(EnsureAccountHolder::class)->dossier($this->owner);
    $this->fact = app(RecordFactChange::class)->execute($this->owner, $this->case->person, 'residence_title_expires_at', matrixCanary(), null, 1);
    $this->people = '/bureaucracy/v2/people/'.$this->case->person_id;
});

/** Someone the owner shared their plan with, with exactly these permissions. */
function matrixHelperOf(User $owner, array $scopes): User
{
    $helper = User::factory()->onboarded()->create();
    $workspace = app(EnsureAccountHolder::class)->person($helper)->workspace;
    $invite = app(ManageDelegation::class)->invite($helper, $workspace, $owner->email, $scopes);
    app(ManageDelegation::class)->accept($owner, $invite['token'], $scopes);

    return $helper;
}

/** A well-formed answer to an offered question, whatever its kind. */
function matrixAnswerTo(array $question): mixed
{
    $schema = $question['answer_schema'];
    $first = $schema['options'][0] ?? null;

    return match ($schema['type']) {
        'boolean' => true,
        'date' => '2030-01-01',
        'integer' => 1,
        default => is_array($first) ? ($first['value'] ?? $first[0]) : $first,
    };
}

function matrixCannotChange(object $test, string $people, int $factId): void
{
    $test->putJson($people.'/facts/residence_title_expires_at', ['value' => '2032-01-01', 'expected_revision' => 2])->assertForbidden();
    $test->postJson($people.'/facts/'.$factId.'/corrections', ['value' => '2032-01-01', 'expected_revision' => 2])->assertForbidden();
    $test->postJson($people.'/fact-conflicts/residence_title_expires_at/resolve', ['value' => '2032-01-01', 'expected_revision' => 2,
        'review_token' => str_repeat('a', 64), 'request_id' => (string) Str::uuid(), 'confirmed' => true])->assertForbidden();
}

test('the owner sees their own answers and is asked the next question', function () {
    $this->actingAs($this->owner);

    $this->getJson($this->people.'/facts')->assertOk()->assertJsonPath('values.residence_title_expires_at', matrixCanary());
    $this->getJson($this->people.'/facts/residence_title_expires_at/history')->assertOk();
    $this->getJson($this->people.'/fact-conflicts')->assertOk();
    $this->getJson($this->people.'/question-preview?jurisdiction=de-nrw-cologne')->assertOk()->assertJsonPath('status', 'preview');
    $this->postJson($this->people.'/question-sessions', ['request_id' => (string) Str::uuid(), 'jurisdiction' => 'de-nrw-cologne'])->assertCreated();
});

test('a helper who may only see the answers reads them but never changes them or answers questions', function () {
    $this->actingAs(matrixHelperOf($this->owner, ['view_plan', 'view_facts']));

    $this->getJson($this->people.'/facts')->assertOk()->assertJsonPath('values.residence_title_expires_at', matrixCanary());
    $this->getJson($this->people.'/facts/residence_title_expires_at/history')->assertOk();
    $this->getJson($this->people.'/fact-conflicts')->assertOk();
    matrixCannotChange($this, $this->people, $this->fact->id);
    $this->getJson($this->people.'/question-preview?jurisdiction=de-nrw-cologne')->assertOk()
        ->assertJsonPath('status', 'permission_required')->assertJsonPath('question', null);
    $this->postJson($this->people.'/question-sessions', ['request_id' => (string) Str::uuid(), 'jurisdiction' => 'de-nrw-cologne'])->assertForbidden();
});

test('edit only sees no current value: the helper gets the offered question alone', function () {
    $this->actingAs(matrixHelperOf($this->owner, ['view_plan', 'edit_facts']));

    // No answers, history or conflicts to read, and nothing on the page carries them.
    $this->getJson($this->people.'/facts')->assertForbidden();
    $this->getJson($this->people.'/facts/residence_title_expires_at/history')->assertForbidden();
    $this->getJson($this->people.'/fact-conflicts')->assertForbidden();
    expect(json_encode($this->get('/bureaucracy?person='.$this->case->person_id)->assertOk()->viewData('page')))
        ->not->toContain(matrixCanary());

    // A direct change works, and its receipt carries no saved value.
    $change = $this->putJson($this->people.'/facts/residence_title_expires_at', ['value' => '2032-01-01', 'expected_revision' => 2])
        ->assertOk()->json();
    expect(array_keys($change))->toEqualCanonicalizing(['fact_id', 'revision'])
        ->and(json_encode($change))->not->toContain(matrixCanary());

    // The question, and everything said back while answering it, carries no saved value.
    // (The first offered question isn't the canary's fact; #207 covers answering a hidden one.)
    $preview = $this->getJson($this->people.'/question-preview?jurisdiction=de-nrw-cologne')->assertOk();
    expect($preview->json('question.fact_key'))->toBeString()->and($preview->getContent())->not->toContain(matrixCanary());
    $session = $this->postJson($this->people.'/question-sessions', ['request_id' => (string) Str::uuid(), 'jurisdiction' => 'de-nrw-cologne'])
        ->assertCreated()->json('session_id');
    $next = $this->postJson('/bureaucracy/v2/question-sessions/'.$session.'/next', ['request_id' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('status', 'offered');
    expect($next->getContent())->not->toContain(matrixCanary());
    $offer = $next->json('question');
    $receipt = $this->postJson('/bureaucracy/v2/question-sessions/'.$session.'/answers/'.$offer['id'], ['token' => $offer['token'], 'value' => matrixAnswerTo($offer)])
        ->assertOk()->json();
    expect(array_keys($receipt))->toEqualCanonicalizing(['fact_id', 'fact_revision', 'status'])
        ->and(json_encode($receipt))->not->toContain(matrixCanary());
});

test('a helper with neither permission may not see or change answers, nor answer questions', function () {
    $this->actingAs(matrixHelperOf($this->owner, ['view_plan']));

    $this->getJson($this->people.'/facts')->assertForbidden();
    $this->getJson($this->people.'/facts/residence_title_expires_at/history')->assertForbidden();
    $this->getJson($this->people.'/fact-conflicts')->assertForbidden();
    matrixCannotChange($this, $this->people, $this->fact->id);
    $this->getJson($this->people.'/question-preview?jurisdiction=de-nrw-cologne')->assertOk()
        ->assertJsonPath('status', 'permission_required')->assertJsonPath('question', null);
    $this->postJson($this->people.'/question-sessions', ['request_id' => (string) Str::uuid(), 'jurisdiction' => 'de-nrw-cologne'])->assertForbidden();
    expect(json_encode($this->get('/bureaucracy?person='.$this->case->person_id)->assertOk()->viewData('page')))
        ->not->toContain(matrixCanary());
});
