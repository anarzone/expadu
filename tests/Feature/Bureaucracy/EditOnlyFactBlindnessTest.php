<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\BureaucracyCase;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * A helper who may change someone's answers but not see them (edit_facts without
 * view_facts) never learns whether a value they try matches the hidden one.
 */

/** The subject's answer, due again, and a helper the subject shares their record with. */
function blindFixture($test, array $scopes, bool $due = true): array
{
    $task = Task::factory()->approvedFixture()->create([
        'key' => 'fixture.blind', 'type' => 'task', 'deadline_type' => 'none', 'depends_on' => [],
        'applies_if' => [['citizenship_group' => 'non_eu', 'german_level' => 'b1']],
        'documents_required' => [], 'how_to_steps' => [], 'links' => [],
    ])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $store->activate($store->stage(app(CatalogueCompiler::class)->compile([$task], [
        $task->key => ['process_id' => 'fixture.blind', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial'],
    ]))->id, null);

    $subject = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($subject);
    app(RecordFactChange::class)->execute($subject, $case->person, 'citizenship_group', 'non_eu', null, $case->fact_version);

    $helper = User::factory()->onboarded()->create();
    $self = app(EnsureAccountHolder::class)->person($helper);
    $token = $test->actingAs($helper)->postJson('/paperwork/v2/invitations', [
        'workspace_id' => $self->workspace_id, 'email' => $subject->email, 'scopes' => $scopes,
    ])->assertCreated()->json('token');
    $test->actingAs($subject)->postJson('/paperwork/v2/invitations/accept', [
        'token' => $token, 'scopes' => $scopes, 'accept' => true,
        'notice_version' => config('bureaucracy_family.sharing_notice_version'),
    ])->assertSuccessful();

    // Due for reconfirming, the answer is asked again (well inside the grant's life).
    if ($due) {
        $case->facts()->where('key', 'citizenship_group')->update(['reconfirm_at' => now()->subDay()]);
    }

    return [$helper, $case->fresh()];
}

/** The helper's offer for that answer, as the page asks for it. */
function blindOffer($test, User $helper, BureaucracyCase $case): array
{
    $session = $test->actingAs($helper)->postJson('/paperwork/v2/people/'.$case->person_id.'/question-sessions', [
        'jurisdiction' => 'de-nrw-cologne', 'request_id' => (string) Str::uuid(),
    ])->assertSuccessful()->json('session_id');
    $offer = $test->postJson('/paperwork/v2/question-sessions/'.$session.'/next', ['request_id' => (string) Str::uuid()])
        ->assertSuccessful()->json('question');
    expect($offer['fact_key'])->toBe('citizenship_group');

    return [$session, $offer];
}

test('🔒 an edit-only helper gets the same reply whether their value matches the hidden one or not', function () {
    [$helper, $case] = blindFixture($this, ['view_plan', 'edit_facts']);
    [$session, $offer] = blindOffer($this, $helper, $case);
    $assert = fn (string $value) => $this->postJson('/paperwork/v2/question-sessions/'.$session.'/answers/'.$offer['id'], [
        'token' => $offer['token'], 'value' => $value, 'answer_state' => 'value', 'operation' => 'assert',
    ])->assertUnprocessable()->json();

    $same = $assert('non_eu');
    $different = $assert('eu');

    expect($same)->toBe($different)
        ->and(json_encode($same))->not->toContain('non_eu')
        ->and($case->facts()->where('key', 'citizenship_group')->where('state', 'confirmed')->sole()->value)->toBe('non_eu');
});

test('🔒 an edit-only change records either way, so the receipt and revision never tell', function (string $value) {
    // A current answer: the same value would otherwise be "nothing to change".
    [$helper, $case] = blindFixture($this, ['view_plan', 'edit_facts'], due: false);
    $before = $case->fresh()->fact_version;

    $receipt = $this->actingAs($helper)->putJson('/paperwork/v2/people/'.$case->person_id.'/facts/citizenship_group', [
        'value' => $value, 'answer_state' => 'value', 'expected_revision' => $before,
    ])->assertSuccessful()->json();

    expect(array_keys($receipt))->toBe(['fact_id', 'revision'])
        ->and($receipt['revision'])->toBe($before + 1)
        ->and(json_encode($receipt))->not->toContain('non_eu');
})->with(['the hidden value' => 'non_eu', 'another value' => 'eu']);

test('someone who can see the answers still confirms a matching one without being asked to choose', function () {
    [$helper, $case] = blindFixture($this, ['view_plan', 'view_facts', 'edit_facts']);
    [$session, $offer] = blindOffer($this, $helper, $case);

    $this->postJson('/paperwork/v2/question-sessions/'.$session.'/answers/'.$offer['id'], [
        'token' => $offer['token'], 'value' => 'non_eu', 'answer_state' => 'value', 'operation' => 'assert',
    ])->assertSuccessful()->assertJsonPath('status', 'recorded');
});
