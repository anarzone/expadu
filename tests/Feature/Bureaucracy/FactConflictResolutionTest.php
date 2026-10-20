<?php

use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyFactConflict;
use App\Models\BureaucracyOutboxEvent;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    $this->actor = User::factory()->onboarded()->create();
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    $this->one = app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'current_residence_title', 'blue_card', '2025-01-01', 1);
    $this->two = BureaucracyCaseFact::factory()->create(['case_id' => $this->case->id, 'key' => 'current_residence_title',
        'value' => 'settlement_permit_18c', 'source' => 'manual', 'effective_from' => '2026-01-01']);
    $this->case->increment('fact_version');
    $this->url = '/bureaucracy/v2/people/'.$this->case->person_id.'/fact-conflicts';
    $this->actingAs($this->actor);
});

test('conflicting current assertions have a read-only review even without a legacy conflict row', function () {
    $count = BureaucracyCaseFact::query()->count();
    $review = $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'conflicts')->json('conflicts.0');
    expect($review['fact_key'])->toBe('current_residence_title')->and($review['expected_revision'])->toBe(3)
        ->and($review['review_token'])->toHaveLength(64)->and(array_column($review['choices'], 'fact_id'))->toBe([$this->one->id, $this->two->id])
        ->and(BureaucracyCaseFact::query()->count())->toBe($count);
});

test('resolving confirms the present without rewriting uncertain past periods and retries are exact', function () {
    $expiry = app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'residence_title_expires_at', '2027-01-01', null, 3);
    $review = $this->getJson($this->url)->assertOk()->json('conflicts.0');
    $body = ['review_token' => $review['review_token'], 'expected_revision' => 4, 'request_id' => (string) Str::uuid(),
        'confirmed' => true, 'value' => 'settlement_permit_18c'];
    $result = $this->postJson($this->url.'/current_residence_title/resolve', $body)->assertOk()->assertJsonPath('revision', 5)->json();
    $this->getJson($this->url)->assertJsonCount(0, 'conflicts');
    $view = app(ConfirmedFactView::class)->forCase($this->case, '2026-09-08');
    expect($view['values']['current_residence_title'])->toBe('settlement_permit_18c')->and($view['values'])->not->toHaveKey('residence_title_expires_at')
        ->and($expiry->fresh()->state)->toBe('historical')->and($this->one->fresh()->state)->toBe('historical')
        ->and($this->two->fresh()->state)->toBe('historical');
    expect(app(ConfirmedFactView::class)->forCase($this->case, '2026-08-01')['states']['current_residence_title'])->toBe('conflict');
    $this->postJson($this->url.'/current_residence_title/resolve', $body)->assertOk()->assertExactJson($result);
    $this->postJson($this->url.'/current_residence_title/resolve', [...$body, 'value' => 'blue_card'])->assertConflict();
    expect(BureaucracyOutboxEvent::query()->where('event_type', 'facts.changed')->where('aggregate_version', 5)->count())->toBe(1);
});

test('stale or unconfirmed resolutions do not overwrite changes', function () {
    $review = $this->getJson($this->url)->assertOk()->json('conflicts.0');
    $body = ['review_token' => $review['review_token'], 'expected_revision' => 3, 'request_id' => (string) Str::uuid(),
        'confirmed' => true, 'value' => 'blue_card'];
    $this->postJson($this->url.'/current_residence_title/resolve', [...$body, 'confirmed' => false])->assertUnprocessable();
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'german_level', 'b1', null, 3);
    $this->postJson($this->url.'/current_residence_title/resolve', $body)->assertConflict();
    expect($this->one->fresh()->state)->toBe('confirmed')->and($this->two->fresh()->state)->toBe('confirmed');
});

test('a conflict with a retired candidate does not ask the user again', function () {
    $this->two->update(['state' => 'superseded', 'superseded_at' => now()]);
    BureaucracyFactConflict::query()->create(['case_id' => $this->case->id, 'fact_key' => $this->one->key,
        'existing_fact_id' => $this->one->id, 'candidate_fact_id' => $this->two->id]);
    expect(app(ConfirmedFactView::class)->forCase($this->case, '2026-09-08')['states']['current_residence_title'])->toBe('value');
    $this->getJson($this->url)->assertOk()->assertJsonCount(0, 'conflicts');
});

test('legacy candidate conflicts require confirmation and close both sides explicitly', function () {
    $this->two->update(['state' => 'candidate', 'confirmed_at' => null]);
    $conflict = BureaucracyFactConflict::query()->create(['case_id' => $this->case->id, 'fact_key' => $this->one->key,
        'existing_fact_id' => $this->one->id, 'candidate_fact_id' => $this->two->id]);
    expect(app(ConfirmedFactView::class)->forCase($this->case, '2026-08-15')['states']['current_residence_title'])->toBe('conflict');
    $review = $this->getJson($this->url)->assertOk()->json('conflicts.0');
    $result = $this->postJson($this->url.'/current_residence_title/resolve', ['review_token' => $review['review_token'],
        'expected_revision' => 3, 'request_id' => (string) Str::uuid(), 'confirmed' => true, 'value' => 'blue_card'])->assertOk()->json();
    expect($conflict->fresh()->status)->toBe('resolved')->and($conflict->fresh()->resolved_fact_id)->toBe($result['fact_id'])
        ->and($this->two->fresh()->state)->toBe('superseded');
    expect(app(ConfirmedFactView::class)->forCase($this->case, '2026-08-15')['states']['current_residence_title'])->toBe('conflict');
});

test('a helper cannot inspect conflict choices without fact-reading permission', function () {
    $helper = User::factory()->onboarded()->create();
    $one = app(EnsureAccountHolder::class)->dossier($helper);
    $invite = app(ManageDelegation::class)->invite($helper, $one->person->workspace, $this->actor->email, ['view_plan', 'edit_facts']);
    app(ManageDelegation::class)->accept($this->actor, $invite['token'], ['view_plan', 'edit_facts']);
    $review = $this->getJson($this->url)->assertOk()->json('conflicts.0');
    $this->actingAs($helper)->getJson($this->url)->assertForbidden();
    $this->postJson($this->url.'/current_residence_title/resolve', ['review_token' => $review['review_token'],
        'expected_revision' => 3, 'request_id' => (string) Str::uuid(), 'confirmed' => true, 'value' => 'blue_card'])->assertForbidden();
});

test('the question flow opens conflict review and continues after confirmation instead of looping', function () {
    $writer = app(RecordFactChange::class);
    $writer->execute($this->actor, $this->case->person, 'arrival_planned', false, null, 3);
    $writer->execute($this->actor, $this->case->person, 'citizenship_group', 'non_eu', null, 4);
    $base = '/bureaucracy/v2/people/'.$this->case->person_id;
    $session = $this->postJson($base.'/question-sessions', ['request_id' => (string) Str::uuid(), 'jurisdiction' => 'de-nrw-cologne'])
        ->assertCreated()->json('session_id');
    $nextUrl = '/bureaucracy/v2/question-sessions/'.$session.'/next';
    $offer = $this->postJson($nextUrl, ['request_id' => (string) Str::uuid()])->assertOk()
        ->assertJsonPath('status', 'offered')->assertJsonPath('question.kind', 'resolve_conflict')
        ->assertJsonPath('question.action.type', 'review_fact_conflict')->json('question');
    $this->getJson($base.'/question-preview?jurisdiction=de-nrw-cologne')->assertOk()->assertJsonPath('question.id', $offer['id']);
    $review = $this->getJson($this->url)->assertOk()->json('conflicts.0');
    $this->postJson($this->url.'/current_residence_title/resolve', ['review_token' => $review['review_token'],
        'expected_revision' => 5, 'request_id' => (string) Str::uuid(), 'confirmed' => true, 'value' => 'settlement_permit_18c'])->assertOk();
    $this->postJson($nextUrl, ['request_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('status', 'no_more_questions');
});

test('ordinary changes and corrections cannot bypass current conflict confirmation', function (bool $legacyCandidate) {
    if ($legacyCandidate) {
        $this->two->update(['state' => 'candidate', 'confirmed_at' => null]);
        BureaucracyFactConflict::query()->create(['case_id' => $this->case->id, 'fact_key' => $this->one->key,
            'existing_fact_id' => $this->one->id, 'candidate_fact_id' => $this->two->id]);
    }
    $url = '/bureaucracy/v2/people/'.$this->case->person_id.'/facts';
    $this->putJson($url.'/current_residence_title', ['expected_revision' => 3, 'value' => 'family_reunification'])->assertConflict();
    $this->postJson($url.'/'.$this->one->id.'/corrections', ['expected_revision' => 3, 'value' => 'family_reunification'])->assertConflict();
    expect($this->case->fresh()->fact_version)->toBe(3)->and($this->one->fresh()->state)->toBe('confirmed');
})->with([false, true]);

test('closing an undated assertion does not make the formerly disputed past certain', function () {
    $this->two->update(['effective_from' => null, 'confirmed_at' => '2026-08-01 10:00:00', 'recorded_at' => '2026-08-01 08:00:00+00:00']);
    $view = app(ConfirmedFactView::class);
    expect($view->forCase($this->case, '2026-08-15')['states']['current_residence_title'])->toBe('conflict');
    $review = $this->getJson($this->url)->assertOk()->json('conflicts.0');
    $this->postJson($this->url.'/current_residence_title/resolve', ['review_token' => $review['review_token'],
        'expected_revision' => 3, 'request_id' => (string) Str::uuid(), 'confirmed' => true, 'value' => 'settlement_permit_18c'])->assertOk();
    expect($view->forCase($this->case, '2026-08-15')['states']['current_residence_title'])->toBe('conflict')
        ->and($view->forCase($this->case, '2026-09-08')['values']['current_residence_title'])->toBe('settlement_permit_18c');
});

test('identical or expired duplicates do not block a new change behind a nonexistent conflict', function (string $duplicate) {
    $this->two->update($duplicate === 'identical' ? ['value' => 'blue_card'] : ['reconfirm_at' => now()->subMinute()]);
    $this->getJson($this->url)->assertOk()->assertJsonCount(0, 'conflicts');
    $url = '/bureaucracy/v2/people/'.$this->case->person_id.'/facts/current_residence_title';
    $this->putJson($url, ['expected_revision' => 3, 'value' => 'family_reunification', 'effective_from' => '2026-09-08'])->assertOk();
    expect(app(ConfirmedFactView::class)->forCase($this->case, '2026-09-08')['values']['current_residence_title'])->toBe('family_reunification')
        ->and($this->case->facts()->where('key', 'current_residence_title')->where('state', 'confirmed')->count())->toBe(1);
})->with(['identical', 'expired']);

test('a new change preserves a formerly disputed period after an undated assertion expires', function () {
    $this->two->update(['effective_from' => null, 'confirmed_at' => '2026-08-01 10:00:00',
        'recorded_at' => '2026-08-01 08:00:00+00:00', 'reconfirm_at' => '2026-09-07 10:00:00']);
    $view = app(ConfirmedFactView::class);
    expect($view->forCase($this->case, '2026-08-15')['states']['current_residence_title'])->toBe('conflict');
    $this->putJson('/bureaucracy/v2/people/'.$this->case->person_id.'/facts/current_residence_title',
        ['expected_revision' => 3, 'value' => 'family_reunification', 'effective_from' => '2026-09-08'])->assertOk();
    expect($view->forCase($this->case, '2026-08-15')['states']['current_residence_title'])->toBe('conflict')
        ->and($view->forCase($this->case, '2026-09-08')['values']['current_residence_title'])->toBe('family_reunification');
});
