<?php

use App\Bureaucracy\Ai\CaseFactExtractionRequest;
use App\Bureaucracy\Ai\CaseFactExtractionResult;
use App\Bureaucracy\Ai\Contracts\ExtractsCaseFact;
use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\PlanSnapshotStore;
use App\Bureaucracy\Cases\QuestionSelector;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyCaseMessage;
use App\Models\BureaucracyCaseQuestion;
use App\Models\Task;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

final class EndpointTestExtractor implements ExtractsCaseFact
{
    /** @var list<CaseFactExtractionRequest> */
    public array $requests = [];

    public function __construct(public CaseFactExtractionResult $result) {}

    public function extract(CaseFactExtractionRequest $request): CaseFactExtractionResult
    {
        $this->requests[] = $request;

        return $this->result;
    }
}

/**
 * @return array{User, BureaucracyCase, BureaucracyCaseQuestion}
 */
function endpointAiFixture(): array
{
    $user = User::factory()->onboarded()->create([
        'situation' => 'other',
        'is_eu' => false,
        'german_level' => null,
        'profile_attributes' => [],
    ]);
    $case = BureaucracyCase::factory()->for($user)->create(['ai_consent_at' => now()]);
    Task::query()->firstOrCreate(['key' => 'endpoint.case-goal'], [
        'title' => 'Verified goal-gated task',
        'description' => 'Verified guidance for the current case goal.',
        'type' => 'task',
        'situation' => ['core'],
        'applies_if' => [['case_goal' => 'settlement_permit']],
        'phase' => 'arrival',
        'depends_on' => [],
        'deadline_type' => 'none',
        'urgency' => 'high',
        'is_published' => true,
        'jurisdiction' => 'de-nrw-cologne',
        'legal_sources' => [
            ['kind' => 'primary', 'label' => 'Residence Act', 'url' => 'https://www.gesetze-im-internet.de/aufenthg_2004/__18g.html'],
            ['kind' => 'implementation', 'label' => 'Stadt Köln', 'url' => 'https://www.stadt-koeln.de/service/produkte/20321/index.html'],
        ],
        'review_status' => 'approved',
        'source_verification' => 'dual_source',
        'reviewed_by' => 'expadu_content_owner',
        'content_version' => '2026-08-04.1',
        'verified_at' => '2026-08-04',
        'review_due_at' => '2027-08-04',
        'conflicts_with' => [],
        'coverage_scope' => 'case',
    ]);
    app(PlanSnapshotStore::class)->store($case);
    $question = app(QuestionSelector::class)->select($case, app(CaseMatcher::class)->match($case));

    expect($question)->toBeInstanceOf(BureaucracyCaseQuestion::class);

    return [$user, $case, $question];
}

test('the bounded message route requires authentication ownership and the current active question', function () {
    [$user, , $question] = endpointAiFixture();

    $this->postJson(route('bureaucracy.case.messages.store'), [
        'question_id' => $question->id,
        'message' => 'My goal is settlement.',
    ])->assertUnauthorized();

    [$otherUser, , $otherQuestion] = endpointAiFixture();

    $this->actingAs($otherUser)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => 'My goal is settlement.',
        ])
        ->assertForbidden();

    $stale = BureaucracyCaseQuestion::factory()->for($otherUser->bureaucracyCase, 'case')->create([
        'fact_key' => 'case_goal',
        'asked_at' => now()->subMinute(),
    ]);

    $this->actingAs($otherUser)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $stale->id,
            'message' => 'My goal is settlement.',
        ])
        ->assertForbidden();

    expect($otherQuestion->fresh()->answered_at)->toBeNull();
});

test('answered questions and inactive cases cannot be interpreted', function () {
    [$user, $case, $question] = endpointAiFixture();
    $extractor = new EndpointTestExtractor(CaseFactExtractionResult::unknown());
    app()->instance(ExtractsCaseFact::class, $extractor);

    $question->update(['answered_at' => now(), 'outcome' => 'answered']);

    $this->actingAs($user)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => 'My goal is settlement.',
        ])
        ->assertForbidden();

    $question->update(['answered_at' => null, 'outcome' => null]);
    $case->update(['status' => 'closed']);

    $this->actingAs($user)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => 'My goal is settlement.',
        ])
        ->assertForbidden();

    expect($extractor->requests)->toBeEmpty();
});

test('the bounded message payload validates identifiers and message length', function (array $payload) {
    [$user, , $question] = endpointAiFixture();

    $this->actingAs($user)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => 'Valid answer',
            ...$payload,
        ])
        ->assertUnprocessable();
})->with([
    'missing question' => [['question_id' => null]],
    'wrong question type' => [['question_id' => 'not-an-id']],
    'empty message' => [['message' => '']],
    'whitespace message' => [['message' => " \n\t "]],
    'message array' => [['message' => ['not', 'text']]],
    'message over max length' => [['message' => str_repeat('a', 2001)]],
]);

test('a candidate response is typed and server labelled without changing facts questions or snapshots', function () {
    [$user, $case, $question] = endpointAiFixture();
    $extractor = new EndpointTestExtractor(CaseFactExtractionResult::candidate('settlement_permit'));
    app()->instance(ExtractsCaseFact::class, $extractor);
    $factCount = BureaucracyCaseFact::where('case_id', $case->id)->count();
    $snapshotCount = $case->planSnapshots()->count();
    $factVersion = $case->fact_version;

    $this->actingAs($user)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => 'I want to apply for permanent residence.',
        ])
        ->assertSuccessful()
        ->assertExactJson([
            'outcome' => 'candidate',
            'value' => 'settlement_permit',
            'label' => 'Apply for a settlement permit',
            'message' => 'I understood this answer. Confirm it before it changes your plan.',
        ]);

    expect($extractor->requests)->toHaveCount(1)
        ->and($extractor->requests[0]->factKey)->toBe('case_goal')
        ->and($extractor->requests[0]->question)->not->toBeEmpty()
        ->and($extractor->requests[0]->message)->toBe('I want to apply for permanent residence.')
        ->and(BureaucracyCaseFact::where('case_id', $case->id)->count())->toBe($factCount)
        ->and($case->planSnapshots()->count())->toBe($snapshotCount)
        ->and($case->fresh()->fact_version)->toBe($factVersion)
        ->and($question->fresh()->answered_at)->toBeNull();
});

test('all non-candidate outcomes return fixed application copy and never provider prose', function (CaseFactExtractionResult $result, string $outcome, string $copy) {
    [$user, , $question] = endpointAiFixture();
    app()->instance(ExtractsCaseFact::class, new EndpointTestExtractor($result));

    $this->actingAs($user)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => 'Untrusted provider prose must not be returned.',
        ])
        ->assertSuccessful()
        ->assertExactJson([
            'outcome' => $outcome,
            'message' => $copy,
        ]);
})->with([
    'unknown' => [CaseFactExtractionResult::unknown(), 'unknown', 'I could not find a clear answer. Please use the choices below.'],
    'off topic' => [CaseFactExtractionResult::offTopic(), 'off_topic', 'I can only help with the current bureaucracy question. Please answer it or use the choices below.'],
    'unavailable' => [CaseFactExtractionResult::unavailable(), 'unavailable', 'The text assistant is unavailable right now. You can still use the choices below.'],
    'invalid' => [CaseFactExtractionResult::invalid(), 'invalid', 'I could not safely interpret that answer. Please use the choices below.'],
]);

test('a candidate outside the authorized fact definition is rejected before labelling', function () {
    [$user, , $question] = endpointAiFixture();
    app()->instance(ExtractsCaseFact::class, new EndpointTestExtractor(
        CaseFactExtractionResult::candidate('invented_goal'),
    ));

    $this->actingAs($user)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => 'An unsafe candidate.',
        ])
        ->assertSuccessful()
        ->assertExactJson([
            'outcome' => 'invalid',
            'message' => 'I could not safely interpret that answer. Please use the choices below.',
        ]);
});

test('the rolling quota permits twenty accepted messages and rejects the twenty first with fixed copy', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->travelTo('2026-08-04 12:00:00');
    [$user, $case, $question] = endpointAiFixture();
    $extractor = new EndpointTestExtractor(CaseFactExtractionResult::unknown());
    app()->instance(ExtractsCaseFact::class, $extractor);

    for ($attempt = 1; $attempt <= 20; $attempt++) {
        $this->actingAs($user)
            ->postJson(route('bureaucracy.case.messages.store'), [
                'question_id' => $question->id,
                'message' => "Accepted attempt {$attempt}",
            ])
            ->assertSuccessful();
    }

    $this->actingAs($user)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => 'Attempt 21',
        ])
        ->assertTooManyRequests()
        ->assertExactJson([
            'outcome' => 'limited',
            'message' => 'You have reached today’s text-assistant limit. You can still use the choices below.',
        ]);

    expect($extractor->requests)->toHaveCount(20)
        ->and(BureaucracyCaseMessage::where('case_id', $case->id)->count())->toBe(20);
});

test('messages older than the rolling twenty four hour window release quota', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->travelTo('2026-08-04 12:00:00');
    [$user, $case, $question] = endpointAiFixture();
    BureaucracyCaseMessage::factory()->count(20)->for($case, 'case')->create([
        'role' => 'user',
        'operation' => 'extract_case_fact',
        'created_at' => now()->subDay()->subSecond(),
        'updated_at' => now()->subDay()->subSecond(),
    ]);
    $extractor = new EndpointTestExtractor(CaseFactExtractionResult::unknown());
    app()->instance(ExtractsCaseFact::class, $extractor);

    $this->actingAs($user)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => 'A fresh accepted attempt.',
        ])
        ->assertSuccessful();

    expect($extractor->requests)->toHaveCount(1);
});

test('the rolling quota counts only AI-assisted user extraction messages', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    config()->set('services.bureaucracy_llm.daily_limit', 1);
    [$user, $case, $question] = endpointAiFixture();
    BureaucracyCaseMessage::factory()->for($case, 'case')->create([
        'role' => 'assistant',
        'operation' => 'extract_case_fact',
    ]);
    BureaucracyCaseMessage::factory()->for($case, 'case')->create([
        'role' => 'user',
        'operation' => 'another_operation',
    ]);
    $extractor = new EndpointTestExtractor(CaseFactExtractionResult::unknown());
    app()->instance(ExtractsCaseFact::class, $extractor);

    $this->actingAs($user)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => 'The one counted extraction.',
        ])
        ->assertSuccessful();

    $this->actingAs($user)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => 'This extraction exceeds the quota.',
        ])
        ->assertTooManyRequests();

    expect($extractor->requests)->toHaveCount(1);
});

test('the message route carries the dedicated burst limiter', function () {
    expect(Route::getRoutes()->getByName('bureaucracy.case.messages.store')->gatherMiddleware())
        ->toContain('throttle:bureaucracy-ai-burst');
});
