<?php

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\BureaucracyProcess;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\ReviewedHomePlan;

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
});

/** Mechanical UI invariants, not a second applicability or deadline calculation. */
function assertPlanContract(array $plan): void
{
    $contract = json_decode(file_get_contents(base_path('tests/Fixtures/bureaucracy/v2-plan-contract.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($plan['schema_version'])->toBe($contract['schema_version']);
    foreach ($contract['required_types'] as $key => $type) {
        expect($plan)->toHaveKey($key);
        expect(gettype($plan[$key]))->toBe($type, $key);
    }
    expect($plan['coverage']['state'])->toBeIn($contract['coverage_states'])
        ->and($plan['overview']['next_actions'])->toBe(array_slice($plan['actions'], 0, 3))
        ->and($plan['overview']['remaining_action_count'])->toBe(max(0, count($plan['actions']) - 3))
        ->and($plan['overview']['question'])->toBe($plan['questions']['question'])
        ->and($plan['ai']['confirmation_required'])->toBeTrue()
        ->and($plan['ai']['consent_scope'])->toBe('single_request');
    $ids = [];
    foreach ($contract['progress_buckets'] as $bucket) {
        expect($plan['progress'][$bucket]['count'])->toBe(count($plan['progress'][$bucket]['ids']));
        $ids = [...$ids, ...$plan['progress'][$bucket]['ids']];
    }
    expect($ids)->toHaveCount(count(array_unique($ids)))->toHaveCount($plan['progress']['total']);
    foreach ($plan['actions'] as $action) {
        expect($action['person_id'])->toBe($plan['person_id'])
            ->and($action['id'])->toBeIn($plan['progress']['todo']['ids'])
            ->and($action['requires_process_start'])->toBe($action['process_id'] === null)
            ->and($action['source_rule_id'])->toBeIn(array_column($plan['guidance'], 'id'));
        $process = collect($plan['processes'])->firstWhere('occurrence_key', $action['occurrence_key']);
        expect($process)->not->toBeNull()->and($process['person_id'])->toBe($plan['person_id']);
    }
    foreach ($contract['future_capabilities'] as $capability) {
        if (($plan['paperwork']['available'] ?? true) !== false) {
            expect($plan['paperwork']['capabilities'][$capability])->toBe(['available' => false, 'reason' => 'not_implemented']);
        }
    }
}

test('onboarding answer progress reopen and reload follow one HTTP plan contract', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user)->post('/onboarding/complete', [
        'situation' => 'non_eu_employee', 'veedel' => 'Ehrenfeld',
        'arrival_planned' => false, 'arrival_date' => '2026-08-01',
    ])->assertSessionHasNoErrors();
    expect($user->fresh()->onboarded_at)->not->toBeNull();
    $fixture = ReviewedHomePlan::activate($user, ['fixture.contract' => [
        'deadline_type' => 'days_since_move_in', 'applies_if' => [['citizenship_group' => 'non_eu']],
        'documents_required' => [['id' => 'identity', 'label' => 'Synthetic identity document', 'evidence_kind' => 'identity', 'requirement_version' => '1']],
    ]], []);
    $read = fn () => $this->getJson('/bureaucracy/v2/plan')->assertSuccessful()->assertHeader('Cache-Control', 'no-store, private')->json('plan');
    $unknown = $read();
    assertPlanContract($unknown);
    expect($unknown['actions'][0]['dates'][0]['state'])->toBe('date_unknown')
        ->and($unknown['overview']['question']['fact_key'])->toBe('moved_in_at');

    $session = $this->postJson('/bureaucracy/v2/people/'.$unknown['person_id'].'/question-sessions', [
        'jurisdiction' => 'de-nrw-cologne', 'request_id' => (string) Str::uuid(),
    ])->assertCreated()->json('session_id');
    $offer = $this->postJson('/bureaucracy/v2/question-sessions/'.$session.'/next', ['request_id' => (string) Str::uuid()])
        ->assertSuccessful()->json('question');
    $answerUrl = '/bureaucracy/v2/question-sessions/'.$session.'/answers/'.$offer['id'];
    $this->postJson($answerUrl, ['token' => $offer['token'], 'value' => '2026-02-30'])->assertUnprocessable();
    expect($fixture['case']->facts()->where('key', 'moved_in_at')->exists())->toBeFalse();
    $this->postJson($answerUrl, ['token' => $offer['token'], 'value' => '2026-08-01'])->assertSuccessful();
    $dated = $read();
    assertPlanContract($dated);
    expect($dated['assessment_revision'])->not->toBe($unknown['assessment_revision'])
        ->and($dated['actions'][0]['dates'][0]['date'])->toBe('2026-08-15')
        ->and($dated['coverage']['state'])->toBe('partial');
    $this->getJson('/bureaucracy/v2/people/'.$dated['person_id'].'/paperwork?jurisdiction=de-nrw-cologne')
        ->assertSuccessful()->assertExactJson($dated['paperwork']);

    $proposal = $dated['processes'][0];
    $start = ['jurisdiction' => 'de-nrw-cologne', 'occurrence_key' => $proposal['occurrence_key'],
        'review_token' => $proposal['review_token'], 'request_id' => (string) Str::uuid()];
    $receipt = $this->postJson('/bureaucracy/v2/people/'.$dated['person_id'].'/processes', $start)->assertSuccessful()->json();
    $detailUrl = '/bureaucracy/v2/processes/'.$receipt['process_id'];
    $event = function (string $type, array $payload = []) use ($detailUrl): void {
        $detail = $this->getJson($detailUrl)->assertSuccessful()->json();
        $command = ['event' => $type, 'payload' => $payload, 'expected_version' => $detail['version'],
            'review_token' => $detail['review_token'], 'request_id' => (string) Str::uuid()];
        $receipt = $this->postJson($detailUrl.'/events', $command)->assertSuccessful()->json();
        $this->postJson($detailUrl.'/events', $command)->assertSuccessful()->assertExactJson($receipt);
    };
    $step = $dated['actions'][0]['step_id'];
    $event('step_completed', ['step_id' => $step]);
    $event('completion_reported');
    $completed = $read();
    assertPlanContract($completed);
    expect($completed['progress']['completed']['count'])->toBe(1)->and($completed['actions'])->toBeEmpty()
        ->and($completed['paperwork']['requirements'][0]['readiness'])->toBe('missing')
        ->and($completed['coverage']['state'])->toBe('partial');
    $event('step_reopened', ['step_id' => $step]);
    $reopened = $read();
    assertPlanContract($reopened);
    expect($reopened['progress']['todo']['count'])->toBe(1)->and($reopened['progress']['completed']['count'])->toBe(0)
        ->and($reopened['actions'][0]['dates'][0]['date'])->toBe('2026-08-15')
        ->and(BureaucracyProcess::query()->count())->toBe(1);
    expect($read())->toBe($reopened);
    $this->getJson($detailUrl)->assertSuccessful()->assertJsonPath('assessment_revision', $reopened['assessment_revision']);
});

test('no activated catalogue and unsupported city retain an explicit contract instead of claiming completion', function (string $city, string $coverage) {
    $user = User::factory()->onboarded()->create(['city' => $city]);
    app(EnsureAccountHolder::class)->dossier($user);
    $plan = $this->actingAs($user)->getJson('/bureaucracy/v2/plan')->assertSuccessful()->json('plan');
    assertPlanContract($plan);
    expect($plan['coverage']['state'])->toBe($coverage)->and($plan['actions'])->toBeEmpty()->and($plan['progress']['total'])->toBe(0);
})->with([['Köln', 'not_activated'], ['Berlin', 'outside_coverage']]);

test('confirming a document for one task never confirms it for another task through HTTP', function () {
    $user = User::factory()->onboarded()->create();
    $rule = ['deadline_type' => 'none', 'documents_required' => [
        ['id' => 'identity', 'label' => 'Synthetic identity paper', 'evidence_kind' => 'identity', 'requirement_version' => '1'],
    ]];
    ReviewedHomePlan::activate($user, ['fixture.one' => $rule, 'fixture.two' => $rule], []);
    $read = fn () => $this->getJson('/bureaucracy/v2/plan')->assertSuccessful()->json('plan');
    $this->actingAs($user);
    $plan = $read();
    $processes = [];
    foreach (array_column($plan['processes'], 'definition_id') as $definitionId) {
        $proposal = collect($read()['processes'])->firstWhere('definition_id', $definitionId);
        $processes[$proposal['definition_id']] = $this->postJson('/bureaucracy/v2/people/'.$plan['person_id'].'/processes', [
            'jurisdiction' => 'de-nrw-cologne', 'occurrence_key' => $proposal['occurrence_key'],
            'review_token' => $proposal['review_token'], 'request_id' => (string) Str::uuid(),
        ])->assertSuccessful()->json();
    }
    $evidenceId = (string) Str::uuid();
    $this->putJson('/bureaucracy/v2/people/'.$plan['person_id'].'/evidence/'.$evidenceId, [
        'request_id' => (string) Str::uuid(), 'expected_version' => 0,
        'details' => ['label' => 'Synthetic identity paper', 'kind' => 'identity', 'reported_available' => true, 'expires_on' => null],
    ])->assertSuccessful();
    $before = $read();
    $requirement = collect($before['paperwork']['requirements'])->firstWhere('id', 'fixture.one.document.identity');
    $this->postJson('/bureaucracy/v2/processes/'.$processes['fixture.one']['process_id'].'/requirements/'.$requirement['id'].'/confirm', [
        'request_id' => (string) Str::uuid(), 'expected_version' => $processes['fixture.one']['version'],
        'evidence_id' => $evidenceId, 'evidence_version' => 1, 'requirement_hash' => $requirement['semantic_hash'], 'confirmed' => true,
    ])->assertSuccessful();
    $after = $read();
    assertPlanContract($after);
    $rows = collect($after['paperwork']['requirements'])->keyBy('id');
    expect($rows['fixture.one.document.identity']['readiness'])->toBe('confirmed_for_use')
        ->and($rows['fixture.two.document.identity']['readiness'])->toBe('reported_available')
        ->and($rows['fixture.two.document.identity']['evidence_id'])->toBeNull()
        ->and($after['paperwork']['evidence'])->toHaveCount(1)
        ->and($after['progress'])->toBe($before['progress']);
    $this->getJson('/bureaucracy/v2/people/'.$plan['person_id'].'/paperwork?jurisdiction=de-nrw-cologne')
        ->assertSuccessful()->assertExactJson($after['paperwork']);
});
