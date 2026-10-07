<?php

use App\Bureaucracy\Ai\Contracts\ExtractsCaseFact;
use App\Bureaucracy\Ai\DeepSeekCaseFactExtractor;
use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\QuestionSelector;
use App\Models\BureaucracyCase;
use App\Models\Task;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExternalProcessingFixtures;

test('an external answer is withheld when its authorisation or case changes in flight', function (string $change, int $status) {
    ExternalProcessingFixtures::configure();
    $this->travelTo('2026-09-08 12:00:00');
    $user = User::factory()->onboarded()->create([
        'is_eu' => false, 'situation' => 'other', 'profile_attributes' => [],
    ]);
    $case = BureaucracyCase::factory()->for($user)->create(['ai_consent_at' => now()]);
    $task = Task::factory()->approvedFixture()->create([
        'key' => 'fixture.delayed-answer',
        'applies_if' => [['case_goal' => 'settlement_permit']],
    ]);
    $question = app(QuestionSelector::class)->select($case, app(CaseMatcher::class)->match($case));
    $other = User::factory()->create();
    config()->set('services.bureaucracy_llm.base_url', 'https://api.deepseek.com');
    config()->set('services.bureaucracy_llm.key', 'synthetic-test-key');
    app()->bind(ExtractsCaseFact::class, DeepSeekCaseFactExtractor::class);

    Http::fake(function () use ($change, $case, $question, $task, $other, $user) {
        match ($change) {
            'withdrawn' => app(ProcessingConsentStore::class)->withdraw($user, $case),
            'closed' => $case->update(['status' => 'closed']),
            'owner' => $case->update(['user_id' => $other->id]),
            'facts' => $case->increment('fact_version'),
            'answered' => $question->update(['answered_at' => now(), 'outcome' => 'answered']),
            'rule' => $task->update(['review_status' => 'legacy']),
        };

        return Http::response(['choices' => [['message' => [
            'content' => null,
            'tool_calls' => [[
                'type' => 'function',
                'function' => [
                    'name' => 'extract_authorized_fact',
                    'arguments' => json_encode(['result' => [
                        'outcome' => 'candidate', 'value' => 'settlement_permit',
                    ]]),
                ],
            ]],
        ]]]]);
    });

    $response = $this->actingAs($user)->postJson(route('bureaucracy.case.messages.store'), [
        'question_id' => $question->id,
        'message' => 'I want to apply for permanent residence.',
        'processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::FactExtraction),
    ]);

    $response->assertStatus($status)->assertJsonMissingPath('value');
    if ($status === 200) {
        $response->assertJsonPath('outcome', 'invalid');
    }
    Http::assertSentCount(1);
    expect($case->facts()->count())->toBe(0);
})->with([
    'consent withdrawn' => ['withdrawn', 403],
    'case closed' => ['closed', 403],
    'ownership changed' => ['owner', 403],
    'facts changed' => ['facts', 200],
    'question answered' => ['answered', 200],
    'rule withdrawn' => ['rule', 200],
]);
