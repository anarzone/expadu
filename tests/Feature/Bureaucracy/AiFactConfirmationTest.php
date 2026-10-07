<?php

use App\Bureaucracy\Ai\ConfirmExtractedFacts;
use App\Bureaucracy\Ai\ExtractForQuestion;
use App\Bureaucracy\Ai\RejectExtractedFacts;
use App\Bureaucracy\Ai\WithdrawPersonProcessing;
use App\Bureaucracy\Assessment\AssessPerson;
use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Bureaucracy\People\ManageDependents;
use App\Bureaucracy\Questions\AnswerQuestion;
use App\Bureaucracy\Questions\OfferNextQuestion;
use App\Bureaucracy\Questions\QuestionSessions;
use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyExtractionCandidate;
use App\Models\BureaucracyFactConflict;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcessingConsent;
use App\Models\Task;
use App\Models\User;
use App\Onboarding\CompleteBureaucracyOnboarding;
use App\Onboarding\SaveBureaucracyDraft;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ExternalProcessingFixtures;

beforeEach(function () {
    ExternalProcessingFixtures::configure();
    $this->actor = User::factory()->create();
    $this->subject = User::factory()->create();
    $one = app(EnsureAccountHolder::class)->dossier($this->actor);
    $this->case = app(EnsureAccountHolder::class)->dossier($this->subject);
    $invite = app(ManageDelegation::class)->invite($this->actor, $one->person->workspace, $this->subject->email, ['view_plan', 'edit_facts', 'request_ai']);
    app(ManageDelegation::class)->accept($this->subject, $invite['token'], ['view_plan', 'edit_facts', 'request_ai']);
    $this->session = app(QuestionSessions::class)->start($this->actor, $this->case->person, 'de-nrw-cologne', (string) Str::uuid());
    $this->offer = app(OfferNextQuestion::class)->execute($this->actor, $this->session, (string) Str::uuid())['question'];
    $this->acceptance = ExternalProcessingFixtures::acceptance(ProcessingPurpose::FactExtraction);
    $this->modelResult = ['outcome' => 'candidate', 'subject' => 'selected_person', 'value' => true];
    Http::fake(['processor.example.test/*' => fn () => Http::response(['choices' => [['message' => ['content' => null,
        'tool_calls' => [['type' => 'function', 'function' => ['name' => 'extract_authorized_fact', 'arguments' => json_encode(['result' => $this->modelResult])]]],
    ]]]])]);
});

test('AI suggests a single selected-person answer and only explicit confirmation saves it', function () {
    $extract = app(ExtractForQuestion::class);
    $candidate = $extract->execute($this->actor, $this->session, $this->offer['id'], $this->offer['token'], 'The selected person is planning to move.', $this->acceptance);
    expect($candidate['outcome'])->toBe('candidate')->and($candidate['value'])->toBeTrue()
        ->and($this->case->facts()->count())->toBe(0)->and($this->case->messages()->count())->toBe(0);
    expect($extract->execute($this->actor, $this->session, $this->offer['id'], $this->offer['token'], 'The selected person is planning to move.', $this->acceptance)['candidate_id'])->toBe($candidate['candidate_id']);
    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        $payload = json_decode($request['messages'][1]['content'], true);

        return isset($payload['selected_person']) && ! isset($payload['facts']) && ! isset($payload['household']) && ! isset($payload['selection']);
    });
    $requestId = (string) Str::uuid();
    $confirm = app(ConfirmExtractedFacts::class);
    $result = $confirm->execute($this->actor, $this->session, $candidate['candidate_id'], $candidate['token'], true, $requestId);
    expect($confirm->execute($this->actor, $this->session, $candidate['candidate_id'], $candidate['token'], true, $requestId)['fact_id'])->toBe($result['fact_id']);
    $fact = $this->case->facts()->sole();
    expect($fact->key)->toBe('arrival_planned')->and($fact->value)->toBeTrue()->and($fact->source)->toBe('ai_extracted_user_confirmed');
    expect(BureaucracyProcessingConsent::query()->sole()->result)->toBeNull();
});

test('missing consent causes no transport and leaves manual entry available', function () {
    $result = app(ExtractForQuestion::class)->execute($this->actor, $this->session, $this->offer['id'], $this->offer['token'], 'Private text');
    expect($result['outcome'])->toBe('consent_required')->and($result['manual_available'])->toBeTrue();
    Http::assertNothingSent();
});

test('failed onboarding writer rolls back sample archival and processing invalidations without an outer transaction', function () {
    app(ExtractForQuestion::class)->execute($this->actor, $this->session, $this->offer['id'], $this->offer['token'], 'Planning a move', $this->acceptance);
    $sample = $this->case->facts()->create(['key' => 'current_residence_title', 'value' => 'blue_card',
        'state' => 'confirmed', 'source' => 'qa_scenario:sample']);
    $sampleCandidate = $this->case->facts()->create(['key' => $sample->key, 'value' => 'family_reunification',
        'state' => 'candidate', 'source' => 'qa_scenario:sample']);
    BureaucracyFactConflict::query()->create(['case_id' => $this->case->id, 'fact_key' => $sample->key,
        'existing_fact_id' => $sample->id, 'candidate_fact_id' => $sampleCandidate->id]);
    $this->case->facts()->create(['key' => 'german_level', 'value' => 'a1', 'state' => 'historical', 'source' => 'manual',
        'confirmed_at' => now()->subMonth(), 'effective_from' => now()->subMonth()->toDateString(),
        'effective_until' => now()->subDay()->toDateString()]);
    $draft = app(SaveBureaucracyDraft::class)->execute($this->actor, $this->case->person, (string) Str::uuid(), 0, 1, [
        'german_level' => ['value' => 'b1', 'operation' => 'change', 'effective_from' => now()->subWeek()->toDateString()],
    ]);
    $tables = ['users', 'bureaucracy_cases', 'bureaucracy_people', 'bureaucracy_case_facts', 'bureaucracy_fact_conflicts',
        'bureaucracy_processing_consents', 'bureaucracy_extraction_candidates', 'bureaucracy_outbox_events', 'bureaucracy_onboarding_drafts'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    expect(fn () => app(CompleteBureaucracyOnboarding::class)->execute($this->actor, $this->case->person, $draft->id, 1,
        $this->case->fresh()->fact_version, (string) Str::uuid()))->toThrow(ValidationException::class);
    expect($snapshot())->toBe($before);
    Http::assertSentCount(1);
});

test('unclear people wrong subjects and extra model claims never create fact candidates', function (array $result, string $outcome) {
    $this->modelResult = $result;
    $candidate = app(ExtractForQuestion::class)->execute($this->actor, $this->session, $this->offer['id'], $this->offer['token'], 'Synthetic ambiguous reply', $this->acceptance);
    expect($candidate['outcome'])->toBe($outcome)->and($candidate)->not->toHaveKey('candidate_id')->and($this->case->facts()->count())->toBe(0);
})->with([
    [['outcome' => 'unclear_subject'], 'unclear_subject'],
    [['outcome' => 'candidate', 'subject' => 'someone_else', 'value' => true], 'invalid'],
    [['outcome' => 'candidate', 'subject' => 'selected_person', 'value' => true, 'legal_advice' => 'untrusted prose'], 'invalid'],
    [['outcome' => 'candidate', 'subject' => 'selected_person', 'value' => 'yes'], 'invalid'],
]);

test('withdrawn or expired candidates cannot later be confirmed', function (string $change) {
    $candidate = app(ExtractForQuestion::class)->execute($this->actor, $this->session, $this->offer['id'], $this->offer['token'], 'Planning a move', $this->acceptance);
    if ($change === 'withdrawn') {
        app(ProcessingConsentStore::class)->withdraw($this->actor);
    } else {
        $this->travel(16)->minutes();
    }
    expect(fn () => app(ConfirmExtractedFacts::class)->execute($this->actor, $this->session, $candidate['candidate_id'], $candidate['token'], true, (string) Str::uuid()))
        ->toThrow(AuthorizationException::class);
    expect($this->case->facts()->count())->toBe(0);
})->with(['withdrawn', 'expired']);

test('edited suggestions use manual provenance and invalid confirmations retain their attempt count', function () {
    $candidate = app(ExtractForQuestion::class)->execute($this->actor, $this->session, $this->offer['id'], $this->offer['token'], 'Planning a move', $this->acceptance);
    expect(DB::table('bureaucracy_extraction_candidates')->sole()->value)->not->toBe('true');
    expect(fn () => app(ConfirmExtractedFacts::class)->execute($this->actor, $this->session, $candidate['candidate_id'], $candidate['token'], 'yes', (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    expect(BureaucracyCaseQuestion::query()->findOrFail($this->offer['id'])->malformed_attempts)->toBe(1);
    app(ConfirmExtractedFacts::class)->execute($this->actor, $this->session, $candidate['candidate_id'], $candidate['token'], false, (string) Str::uuid());
    expect($this->case->facts()->sole()->source)->toBe('manual')->and($this->case->facts()->sole()->value)->toBeFalse();
});

test('rejection clears candidate values and prevents a cached suggestion from returning', function () {
    $candidate = app(ExtractForQuestion::class)->execute($this->actor, $this->session, $this->offer['id'], $this->offer['token'], 'Planning a move', $this->acceptance);
    app(RejectExtractedFacts::class)->execute($this->actor, $this->session, $candidate['candidate_id'], $candidate['token']);
    expect(BureaucracyExtractionCandidate::query()->sole()->value)->toBeNull()->and(BureaucracyProcessingConsent::query()->sole()->result)->toBeNull();
    expect(fn () => app(ExtractForQuestion::class)->execute($this->actor, $this->session, $this->offer['id'], $this->offer['token'], 'Planning a move', $this->acceptance))->toThrow(AuthorizationException::class);
    expect($this->case->facts()->count())->toBe(0);
    Http::assertSentCount(1);
});

test('all candidate values are promptly cleared on expiry changed facts and revoked access', function (string $change) {
    app(ExtractForQuestion::class)->execute($this->actor, $this->session, $this->offer['id'], $this->offer['token'], 'Planning a move', $this->acceptance);
    match ($change) {
        'expiry' => $this->travel(16)->minutes(),
        'facts' => app(RecordFactChange::class)->execute($this->subject, $this->case->person, 'german_level', 'b1', null, 1),
        'withdrawal' => app(ProcessingConsentStore::class)->withdraw($this->actor),
        'revocation' => app(ManageDelegation::class)->revoke($this->subject, BureaucracyAccessGrant::query()->where('person_id', $this->case->person_id)->sole()),
    };
    if ($change === 'expiry') {
        $this->artisan('bureaucracy:prune-processing')->assertSuccessful();
    }
    expect(BureaucracyExtractionCandidate::query()->sole()->value)->toBeNull()
        ->and(BureaucracyExtractionCandidate::query()->sole()->confirmation_token)->toBeNull();
})->with(['expiry', 'facts', 'withdrawal', 'revocation']);

test('the subject can withdraw family processing without giving a helper authority over other helpers', function () {
    app(ExtractForQuestion::class)->execute($this->actor, $this->session, $this->offer['id'], $this->offer['token'], 'Planning a move', $this->acceptance);
    expect(fn () => app(WithdrawPersonProcessing::class)->execute($this->actor, $this->case->person))->toThrow(AuthorizationException::class);
    app(WithdrawPersonProcessing::class)->execute($this->subject, $this->case->person);
    expect(BureaucracyProcessingConsent::query()->sole()->withdrawn_at)->not->toBeNull()->and(BureaucracyExtractionCandidate::query()->sole()->value)->toBeNull();
});

test('private AI endpoints enforce explicit confirmation and selected-session scope', function () {
    $base = '/bureaucracy/v2/question-sessions/'.$this->session->id;
    $candidate = $this->actingAs($this->actor)->postJson($base.'/extract/'.$this->offer['id'], [
        'token' => $this->offer['token'], 'message' => 'Planning a move', 'processing' => $this->acceptance,
    ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    $body = ['token' => $candidate['token'], 'value' => true, 'request_id' => (string) Str::uuid()];
    $url = $base.'/candidates/'.$candidate['candidate_id'].'/confirm';
    $this->postJson($url, $body)->assertUnprocessable();
    $this->actingAs($this->subject)->postJson($url, [...$body, 'confirmed' => true])->assertForbidden();
    $this->actingAs($this->actor)->postJson($url, [...$body, 'confirmed' => true, 'fact_key' => 'invented'])->assertUnprocessable();
    $this->postJson($url, [...$body, 'confirmed' => true])->assertOk();
    expect($this->case->facts()->count())->toBe(1);
});

test('manual and confirmed AI answers produce equivalent reviewed decisions', function () {
    // Synthetic reviewed policy exercises the same deterministic decision core;
    // this fixture is not real legal guidance or published catalogue content.
    $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.ai-parity', 'type' => 'task',
        'deadline_type' => 'none', 'depends_on' => [], 'applies_if' => [['arrival_planned' => true]],
        'documents_required' => [], 'how_to_steps' => [], 'links' => []])->fresh();
    $artifact = app(CatalogueCompiler::class)->compile([$task], [
        $task->key => ['process_id' => 'fixture.ai-parity', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial'],
    ]);
    $release = app(CatalogueReleaseStore::class)->stage($artifact);
    app(CatalogueReleaseStore::class)->activate($release->id, null);
    $offer = app(OfferNextQuestion::class)->execute($this->actor, $this->session, (string) Str::uuid())['question'];
    $candidate = app(ExtractForQuestion::class)->execute($this->actor, $this->session, $offer['id'], $offer['token'], 'Planning a move', $this->acceptance);
    app(ConfirmExtractedFacts::class)->execute($this->actor, $this->session, $candidate['candidate_id'], $candidate['token'], true, (string) Str::uuid());
    $manualUser = User::factory()->create();
    $manualCase = app(EnsureAccountHolder::class)->dossier($manualUser);
    $manualSession = app(QuestionSessions::class)->start($manualUser, $manualCase->person, 'de-nrw-cologne', (string) Str::uuid());
    $manualOffer = app(OfferNextQuestion::class)->execute($manualUser, $manualSession, (string) Str::uuid())['question'];
    app(AnswerQuestion::class)->execute($manualUser, $manualSession, $manualOffer['id'], $manualOffer['token'], true);
    $ai = app(AssessPerson::class)->assess(app(PrepareAssessmentInput::class)->for($this->actor, $this->case->person, 'de-nrw-cologne'))->toArray();
    $manual = app(AssessPerson::class)->assess(app(PrepareAssessmentInput::class)->for($manualUser, $manualCase->person, 'de-nrw-cologne'))->toArray();
    expect($ai['processes'])->not->toBeEmpty()->toBe($manual['processes'])->and($ai['question_dependencies'])->toBe($manual['question_dependencies']);
});

test('replacing and restoring a sharing grant cannot revive its earlier candidate', function () {
    $candidate = app(ExtractForQuestion::class)->execute($this->actor, $this->session, $this->offer['id'], $this->offer['token'], 'Planning a move', $this->acceptance);
    foreach ([['view_plan', 'edit_facts'], ['view_plan', 'edit_facts', 'request_ai']] as $scopes) {
        $invite = app(ManageDelegation::class)->invite($this->actor, app(EnsureAccountHolder::class)->person($this->actor)->workspace, $this->subject->email, $scopes);
        app(ManageDelegation::class)->accept($this->subject, $invite['token'], $scopes);
    }
    expect(fn () => app(ConfirmExtractedFacts::class)->execute($this->actor, $this->session, $candidate['candidate_id'], $candidate['token'], true, (string) Str::uuid()))
        ->toThrow(AuthorizationException::class);
    expect(BureaucracyExtractionCandidate::query()->sole()->value)->toBeNull();
});

test('revoking guardian authority immediately clears dependent extraction candidates', function () {
    config(['bureaucracy_family.guardian_policy_version' => 'synthetic-review-policy']);
    $guardian = User::factory()->create();
    $reviewer = User::factory()->create(['is_admin' => true]);
    $authority = app(ManageDependents::class)->request($guardian, 'Synthetic child');
    app(ManageDependents::class)->review($reviewer, $authority, 'synthetic-review-policy', 'synthetic-reference', now()->addMonth());
    $child = BureaucracyPerson::query()->findOrFail($authority->person_id);
    $session = app(QuestionSessions::class)->start($guardian, $child, 'de-nrw-cologne', (string) Str::uuid());
    $offer = app(OfferNextQuestion::class)->execute($guardian, $session, (string) Str::uuid())['question'];
    $candidate = app(ExtractForQuestion::class)->execute($guardian, $session, $offer['id'], $offer['token'], 'The selected child is planning to move', $this->acceptance);
    app(ManageDependents::class)->revoke($guardian, $authority);
    $stored = BureaucracyExtractionCandidate::query()->findOrFail($candidate['candidate_id']);
    expect($stored->value)->toBeNull()->and($stored->confirmation_token)->toBeNull()->and($stored->state)->toBe('invalidated');
});
