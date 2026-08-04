<?php

use App\Bureaucracy\Ai\CaseFactExtractionRequest;
use App\Bureaucracy\Ai\CaseFactExtractionResult;
use App\Bureaucracy\Ai\Contracts\ExtractsCaseFact;
use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\PlanSnapshotStore;
use App\Bureaucracy\Cases\QuestionSelector;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseMessage;
use App\Models\BureaucracyCaseQuestion;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

final class PrivacyTestExtractor implements ExtractsCaseFact
{
    public int $calls = 0;

    public function extract(CaseFactExtractionRequest $request): CaseFactExtractionResult
    {
        $this->calls++;

        return CaseFactExtractionResult::candidate('settlement_permit');
    }
}

/**
 * @return array{User, BureaucracyCase, BureaucracyCaseQuestion}
 */
function privacyAiFixture(bool $consented = true): array
{
    $user = User::factory()->onboarded()->create([
        'situation' => 'other',
        'is_eu' => false,
        'german_level' => null,
        'profile_attributes' => [],
    ]);
    $case = BureaucracyCase::factory()->for($user)->create([
        'ai_consent_at' => $consented ? now() : null,
        'ai_consent_withdrawn_at' => null,
    ]);
    Task::factory()->create([
        'key' => 'privacy.case-goal',
        'title' => 'Verified goal-gated task',
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

test('AI consent requires authentication and updates only the current users case', function () {
    [$user, $case] = privacyAiFixture(false);

    $this->putJson(route('bureaucracy.case.ai-consent.update'), ['consent' => true])
        ->assertUnauthorized();

    $this->actingAs($user)
        ->putJson(route('bureaucracy.case.ai-consent.update'), ['consent' => true])
        ->assertSuccessful()
        ->assertExactJson(['consented' => true]);

    expect($case->fresh()->ai_consent_at)->not->toBeNull()
        ->and($case->fresh()->ai_consent_withdrawn_at)->toBeNull();

    $this->actingAs($user)
        ->putJson(route('bureaucracy.case.ai-consent.update'), ['consent' => false])
        ->assertSuccessful()
        ->assertExactJson(['consented' => false]);

    expect($case->fresh()->ai_consent_withdrawn_at)->not->toBeNull();
});

test('consent and message requests reject fields outside their bounded contracts', function () {
    [$user, , $question] = privacyAiFixture();

    $this->actingAs($user)
        ->putJson(route('bureaucracy.case.ai-consent.update'), [
            'consent' => true,
            'processor' => 'attacker-selected',
        ])
        ->assertUnprocessable();

    $this->actingAs($user)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => 'I want a settlement permit.',
            'fact_key' => 'sponsor_current_title',
        ])
        ->assertUnprocessable();
});

test('no AI message is stored or dispatched without current explicit consent', function () {
    [$user, $case, $question] = privacyAiFixture(false);
    $extractor = new PrivacyTestExtractor;
    app()->instance(ExtractsCaseFact::class, $extractor);

    $this->actingAs($user)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => 'I want a settlement permit.',
        ])
        ->assertForbidden();

    expect($extractor->calls)->toBe(0)
        ->and(BureaucracyCaseMessage::where('case_id', $case->id)->count())->toBe(0);

    $case->update([
        'ai_consent_at' => now()->subMinute(),
        'ai_consent_withdrawn_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => 'I want a settlement permit.',
        ])
        ->assertForbidden();

    expect($extractor->calls)->toBe(0)
        ->and(BureaucracyCaseMessage::where('case_id', $case->id)->count())->toBe(0);
});

test('a disabled or incomplete provider returns fixed unavailable copy without storing or dispatching', function () {
    [$user, $case, $question] = privacyAiFixture();
    config()->set('services.bureaucracy_llm.enabled', false);
    app()->forgetInstance(ExtractsCaseFact::class);
    Http::preventStrayRequests();

    $this->actingAs($user)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => 'I want a settlement permit.',
        ])
        ->assertSuccessful()
        ->assertExactJson([
            'outcome' => 'unavailable',
            'message' => 'The text assistant is unavailable right now. You can still use the choices below.',
        ]);

    expect(BureaucracyCaseMessage::where('case_id', $case->id)->count())->toBe(0);
    Http::assertNothingSent();
});

test('accepted raw messages are encrypted hidden and expire within thirty days', function () {
    $this->travelTo('2026-08-04 12:00:00');
    [$user, $case, $question] = privacyAiFixture();
    $extractor = new PrivacyTestExtractor;
    app()->instance(ExtractsCaseFact::class, $extractor);
    $plaintext = 'I want a settlement permit. Private reference 1234.';

    $this->actingAs($user)
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => $plaintext,
        ])
        ->assertSuccessful();

    $message = BureaucracyCaseMessage::where('case_id', $case->id)->sole();
    $stored = DB::table('bureaucracy_case_messages')->where('id', $message->id)->value('content');

    expect($message->content)->toBe($plaintext)
        ->and($message->toArray())->not->toHaveKey('content')
        ->and($stored)->toBeString()->not->toBe($plaintext)->not->toContain('Private reference')
        ->and($message->expires_at->lessThanOrEqualTo($message->created_at->addDays(30)))->toBeTrue()
        ->and($message->expires_at->lessThanOrEqualTo(now()->addDays(30)))->toBeTrue();
});

test('expired raw messages are pruned while unexpired messages remain', function () {
    [, $case] = privacyAiFixture();
    $expired = BureaucracyCaseMessage::factory()->for($case, 'case')->create([
        'expires_at' => now()->subSecond(),
    ]);
    $unexpired = BureaucracyCaseMessage::factory()->for($case, 'case')->create([
        'expires_at' => now()->addSecond(),
    ]);

    Artisan::call('model:prune', [
        '--model' => [BureaucracyCaseMessage::class],
    ]);

    $this->assertModelMissing($expired);
    $this->assertModelExists($unexpired);
});

test('raw message pruning is scheduled daily', function () {
    Artisan::call('schedule:list', ['--json' => true]);
    $events = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    $event = collect($events)
        ->firstWhere('command', 'php artisan model:prune');

    expect($event)->not->toBeNull()
        ->and($event['expression'])->toBe('45 3 * * *');
});

test('the burst limiter contains independent five-per-minute user and IP budgets', function () {
    [$user] = privacyAiFixture();
    $request = Request::create('/bureaucracy/case/messages', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.44',
    ]);
    $request->setUserResolver(fn (): User => $user);

    $limits = RateLimiter::limiter('bureaucracy-ai-burst')($request);

    expect($limits)->toBeArray()->toHaveCount(2)
        ->and($limits[0]->maxAttempts)->toBe(5)
        ->and($limits[1]->maxAttempts)->toBe(5)
        ->and($limits[0]->key)->not->toBe($limits[1]->key)
        ->and($limits[0]->key)->toContain((string) $user->id)
        ->and($limits[1]->key)->toContain('203.0.113.44');
});

test('the burst limiter returns short-window fixed copy instead of the daily quota copy', function () {
    [$user, , $question] = privacyAiFixture();
    app()->instance(ExtractsCaseFact::class, new PrivacyTestExtractor);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
            ->postJson(route('bureaucracy.case.messages.store'), [
                'question_id' => $question->id,
                'message' => "Burst attempt {$attempt}",
            ])
            ->assertSuccessful();
    }

    $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
        ->postJson(route('bureaucracy.case.messages.store'), [
            'question_id' => $question->id,
            'message' => 'Burst attempt 6',
        ])
        ->assertTooManyRequests()
        ->assertExactJson([
            'outcome' => 'limited',
            'message' => 'Please wait a moment before trying again. You can still use the choices below.',
        ]);
});
