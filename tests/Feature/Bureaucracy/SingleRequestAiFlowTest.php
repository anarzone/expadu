<?php

use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\QuestionSelector;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyProcessingConsent;
use App\Models\Task;
use App\Models\User;
use App\Privacy\ProcessingPurpose;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExternalProcessingFixtures;

beforeEach(function () {
    ExternalProcessingFixtures::configure();
    $this->person = User::factory()->onboarded()->create(['situation' => 'other', 'german_level' => null, 'profile_attributes' => []]);
    $this->dossier = BureaucracyCase::factory()->for($this->person)->create(['ai_consent_at' => now()]);
    Task::factory()->approvedFixture()->create(['key' => 'fixture.single-request', 'applies_if' => [['case_goal' => 'settlement_permit']]]);
    $question = app(QuestionSelector::class)->select($this->dossier, app(CaseMatcher::class)->match($this->dossier));
    $this->input = [
        'question_id' => $question->id, 'message' => 'I want permanent residence.',
        'processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::FactExtraction),
    ];
    Http::fake(['processor.example.test/*' => Http::response(['choices' => [['message' => [
        'content' => null,
        'tool_calls' => [['type' => 'function', 'function' => [
            'name' => 'extract_authorized_fact', 'arguments' => json_encode(['result' => ['outcome' => 'candidate', 'value' => 'settlement_permit']]),
        ]]],
    ]]]])]);
    $this->actingAs($this->person);
});

test('the message endpoint needs permission for this request not old case consent', function () {
    unset($this->input['processing']);
    $this->postJson(route('bureaucracy.case.messages.store'), $this->input)
        ->assertOk()->assertJsonPath('outcome', 'consent_required');
    Http::assertNothingSent();
    expect(BureaucracyProcessingConsent::query()->count())->toBe(0);
});

test('one request returns a candidate without storing the prompt or changing confirmed facts', function () {
    $this->postJson(route('bureaucracy.case.messages.store'), $this->input)
        ->assertOk()->assertJsonPath('outcome', 'candidate')->assertJsonPath('value', 'settlement_permit');
    Http::assertSentCount(1);
    expect($this->dossier->facts()->count())->toBe(0)->and($this->dossier->messages()->count())->toBe(0);
});

test('retrying a successful request is not limited and does not send it again', function () {
    config()->set('services.bureaucracy_llm.daily_limit', 1);
    foreach (range(1, 2) as $retry) {
        $this->postJson(route('bureaucracy.case.messages.store'), $this->input)
            ->assertOk()->assertJsonPath('outcome', 'candidate');
    }
    Http::assertSentCount(1);
    $this->input['processing'] = ExternalProcessingFixtures::acceptance(ProcessingPurpose::FactExtraction);
    $this->postJson(route('bureaucracy.case.messages.store'), $this->input)
        ->assertTooManyRequests()->assertJsonPath('outcome', 'limited');
    Http::assertSentCount(1);
});

test('withdrawing request consent prevents cached candidate replay', function () {
    $this->postJson(route('bureaucracy.case.messages.store'), $this->input)->assertOk();
    $this->putJson(route('bureaucracy.case.ai-consent.update'), ['consent' => false])->assertOk();
    $this->postJson(route('bureaucracy.case.messages.store'), $this->input)->assertForbidden();
    expect(BureaucracyProcessingConsent::query()->sole()->result)->toBeNull();
    Http::assertSentCount(1);
});

test('old case wide consent cannot silently authorise a future request', function () {
    $this->putJson(route('bureaucracy.case.ai-consent.update'), ['consent' => true])->assertUnprocessable();
    Http::assertNothingSent();
});

test('request permission cannot add another fact or person to its scope', function () {
    $this->input['processing']['person_id'] = 1234;
    $this->postJson(route('bureaucracy.case.messages.store'), $this->input)->assertUnprocessable();
    Http::assertNothingSent();
});
