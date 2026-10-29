<?php

use App\Models\BureaucracyProcessingConsent;
use App\Models\User;
use App\Privacy\ProcessingPurpose;
use App\Services\WeatherService;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\ExternalProcessingFixtures;

/** An Anthropic Messages reply holding the assistant's answer, in one round. */
function consentAnswerReply(string $text = 'I can look that up for you.'): array
{
    return [
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'synthetic-assistant', 'stop_reason' => 'tool_use',
        'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'answer', 'input' => ['sentences' => [['text' => $text, 'uses' => []]], 'show' => []]]],
        'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
    ];
}

/** The person's message into the conversation, then Composer's answer to it, as the page sends them. */
function consentAsk($test, string $text, array $ask = []): TestResponse
{
    $revision = $test->getJson('/composer/workspace')->assertOk()->json('revision');
    $test->postJson('/composer/workspace', ['revision' => $revision, 'action' => 'message', 'text' => $text])->assertOk();

    return $test->postJson(route('composer.ask'), $ask);
}

beforeEach(function () {
    config()->set('services.llm', [
        'driver' => 'anthropic', 'base_url' => 'https://processor.example.test', 'model' => 'synthetic-assistant', 'key' => 'synthetic',
        'processor_name' => 'Synthetic processor', 'processor_privacy_url' => 'https://processor.example.test/privacy', 'prompt_version' => 'test.1',
    ]);
    Http::fake(['processor.example.test/*' => Http::response(consentAnswerReply())]);
    // Composer's answer without the AI shows the weather: Cologne's, without asking anyone.
    app()->instance(WeatherService::class, new class extends WeatherService
    {
        public function __construct() {}

        public function getCurrentWeather(float $lat = 50.9375, float $lng = 6.9603): array
        {
            return ['available' => false];
        }

        public function getForecast(float $lat = 50.9375, float $lng = 6.9603): array
        {
            return ['available' => false];
        }
    });
    $this->actingAs(User::factory()->onboarded()->create());
});

test('🔒 without the person\'s say-so for the request, nothing is sent and the answer says only why', function () {
    $answer = collect(consentAsk($this, 'Anmeldung appointment')->assertOk()->json('conversations.0.messages'))->last();

    Http::assertNothingSent();
    expect($answer)->without_ai->toBe('unpermitted')->written->toBe('code')
        ->text->toBe('This message came without your go-ahead to send it on, so I haven’t read it.')
        // One line, never the "without the AI" cards (weather, listings) that read as if it were off.
        ->and($answer['blocks'] ?? [])->toBe([])
        ->and($answer['suggestions'])->toBe([['label' => 'Send it again', 'prompt' => 'Anmeldung appointment']]);
});

test('🔒 Composer permission is purpose scoped, and a retry with the same say-so sends nothing again', function () {
    $processing = ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist);
    $first = collect(consentAsk($this, 'Anmeldung appointment', ['processing' => $processing])->assertOk()->json('conversations.0.messages'))->last();
    $this->postJson(route('composer.ask'), ['processing' => $processing])->assertOk();

    Http::assertSentCount(1);
    expect($first)->written->toBe('ai')->text->toBe('I can look that up for you.')
        ->and(BureaucracyProcessingConsent::query()->sole()->purpose)->toBe('composer_assist');
});

test('🔒 permission for fact extraction does not also authorise Composer', function () {
    consentAsk($this, 'Anmeldung appointment', ['processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::FactExtraction)])
        ->assertUnprocessable();
    Http::assertNothingSent();
});

test('the current processing disclosure is read only and exposes no credential', function () {
    $response = $this->getJson('/privacy/processing/composer_assist')->assertOk()
        ->assertJsonPath('scope', 'single_request')->assertJsonPath('available', true);
    expect($response->json())->not->toHaveKeys(['key', 'api_key', 'token'])
        ->and(BureaucracyProcessingConsent::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('🔒 a non JSON accept header cannot flash a rejected request into the session', function () {
    $revision = $this->getJson('/composer/workspace')->json('revision');
    $this->postJson('/composer/workspace', ['revision' => $revision, 'action' => 'message', 'text' => 'PRIVATE-PROMPT-4422'])->assertOk();
    $processing = ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist);
    $processing['notice_version'] = 'stale';
    $response = $this->post('/composer/ask', ['processing' => $processing, 'from_label' => 'PRIVATE-PLACE-5511'], ['Accept' => 'text/html']);

    expect(session()->get('_old_input'))->toBeNull()
        ->and($response->getStatusCode())->toBe(422);
    Http::assertNothingSent();
});

test('🔒 Composer permission has an authenticated withdrawal endpoint independent of any dossier', function () {
    $input = ['processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist)];
    consentAsk($this, 'Anmeldung appointment', $input)->assertOk();
    $this->deleteJson('/privacy/processing/composer_assist')->assertOk()->assertJsonPath('withdrawn', true);
    $this->postJson(route('composer.ask'), $input)->assertForbidden();

    expect(BureaucracyProcessingConsent::query()->sole()->result)->toBeNull();
    Http::assertSentCount(1);
});

test('🔒 an answer without the AI says why, and one the AI wrote says nothing of it', function () {
    $ai = collect(consentAsk($this, 'Anmeldung appointment', ['processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist)])
        ->assertOk()->json('conversations.0.messages'))->last();
    expect($ai)->written->toBe('ai')->and($ai)->not->toHaveKey('without_ai');

    // The day's allowance used up: the gate holds it as limited.
    config()->set('bureaucracy_privacy.daily_limits.composer_assist', 1);
    $limited = collect(consentAsk($this, 'Anmeldung appointment please', ['processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist)])
        ->assertOk()->json('conversations.0.messages'))->last();
    expect($limited)->without_ai->toBe('limited')->and($limited['text'])->toStartWith('I’ve reached today’s limit');

    // The AI off: nothing is sent, whatever the page sends.
    config()->set('services.llm.driver', 'off');
    $off = collect(consentAsk($this, 'Anmeldung appointment', ['processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist)])
        ->assertOk()->json('conversations.0.messages'))->last();
    expect($off)->without_ai->toBe('off');
    Http::assertSentCount(1);
});

test('🔒 the page can only say it couldn\'t get an answer: no reason or kind of its own', function () {
    $this->postJson('/composer/workspace', ['revision' => 0, 'action' => 'message', 'text' => 'Anmeldung appointment'])->assertOk();
    $failed = collect($this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'reply', 'kind' => 'failed'])->assertOk()
        ->json('conversations.0.messages'))->last();

    expect($failed)->kind->toBe('failed')->and($failed)->not->toHaveKey('reader');
    $this->postJson('/composer/workspace', ['revision' => 2, 'action' => 'reply', 'kind' => 'bureaucracy'])
        ->assertUnprocessable()->assertJsonValidationErrors('kind');
    $this->postJson('/composer/workspace', ['revision' => 2, 'action' => 'reply', 'kind' => 'failed', 'reader' => 'limited'])
        ->assertUnprocessable();
});
