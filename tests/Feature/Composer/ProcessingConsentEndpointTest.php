<?php

use App\Composer\OpenAiCompatiblePromptParser;
use App\Models\BureaucracyProcessingConsent;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;
use App\Profile\ProfileEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExternalProcessingFixtures;

beforeEach(function () {
    config()->set('services.llm', [
        'driver' => 'openai', 'base_url' => 'https://processor.example.test', 'model' => 'synthetic-parser', 'key' => 'synthetic',
        'processor_name' => 'Synthetic processor', 'processor_privacy_url' => 'https://processor.example.test/privacy', 'prompt_version' => 'test.1',
    ]);
    Http::fake(['processor.example.test/*' => Http::response(['choices' => [['message' => ['tool_calls' => [[
        'function' => ['name' => 'route_prompt', 'arguments' => json_encode(['intent' => 'bureaucracy_q', 'query' => 'registration'])],
    ]]]]]])]);
    $this->actingAs(User::factory()->onboarded()->create());
});

test('Composer uses its local parser without new processing permission', function () {
    $this->postJson(route('composer.parse'), ['text' => 'Anmeldung appointment'])
        ->assertOk()->assertJsonPath('source', 'heuristic');
    Http::assertNothingSent();
});

test('Composer permission is purpose scoped and retries do not resend the prompt', function () {
    $input = ['text' => 'Anmeldung appointment', 'processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerParse)];
    foreach (range(1, 2) as $retry) {
        $this->postJson(route('composer.parse'), $input)->assertOk()->assertJsonPath('source', 'llm');
    }
    Http::assertSentCount(1);
    expect(BureaucracyProcessingConsent::query()->sole()->purpose)->toBe('composer_parse');
});

test('permission for fact extraction does not also authorise Composer parsing', function () {
    $this->postJson(route('composer.parse'), [
        'text' => 'Anmeldung appointment', 'processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::FactExtraction),
    ])->assertUnprocessable();
    Http::assertNothingSent();
});

test('the current processing disclosure is read only and exposes no credential', function () {
    $response = $this->getJson('/privacy/processing/composer_parse')->assertOk()
        ->assertJsonPath('scope', 'single_request')->assertJsonPath('available', true);
    expect($response->json())->not->toHaveKeys(['key', 'api_key', 'token'])
        ->and(BureaucracyProcessingConsent::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a non JSON accept header cannot flash a rejected prompt into the session', function () {
    $input = ['text' => 'PRIVATE-PROMPT-4422', 'processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerParse)];
    $input['processing']['notice_version'] = 'stale';
    $response = $this->post('/composer/parse', $input, ['Accept' => 'text/html']);
    expect(session()->get('_old_input'))->toBeNull()
        ->and($response->getStatusCode())->toBe(422);
    Http::assertNothingSent();
});

test('Composer permission has an authenticated withdrawal endpoint independent of any dossier', function () {
    $input = ['text' => 'Anmeldung appointment', 'processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerParse)];
    $this->postJson(route('composer.parse'), $input)->assertOk();
    $this->deleteJson('/privacy/processing/composer_parse')->assertOk()->assertJsonPath('withdrawn', true);
    $this->postJson(route('composer.parse'), $input)->assertForbidden();
    expect(BureaucracyProcessingConsent::query()->sole()->result)->toBeNull();
    Http::assertSentCount(1);
});

test('an identical parser retry preserves its original resolved time window', function () {
    $this->travelTo('2026-09-08 12:00:00 Europe/Berlin');
    // Different synthetic host avoids an earlier registered fake for this test.
    config()->set('services.llm.base_url', 'https://time-engine.invalid');
    Http::fake(['time-engine.invalid/*' => Http::response(['choices' => [['message' => ['tool_calls' => [[
        'function' => ['name' => 'route_prompt', 'arguments' => json_encode([
            'intent' => 'plan_day', 'window_start' => '2026-09-08T12:00:00+02:00', 'window_end' => '2026-09-08T15:00:00+02:00',
        ])],
    ]]]]]])]);
    $input = ['text' => 'A plan from now', 'processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerParse)];
    $profile = app(ProfileEngine::class)->build(auth()->user());
    $permit = app(ProcessingConsentStore::class)->grant(auth()->user(), ProcessingPurpose::ComposerParse,
        OpenAiCompatiblePromptParser::processingContext($input['text'], $profile), $input['processing']);
    $parser = app(OpenAiCompatiblePromptParser::class);
    $first = $parser->parse($input['text'], $profile, CarbonImmutable::parse('2026-09-08 12:00:00 Europe/Berlin'), $permit)->toArray();
    $this->travel(1)->minutes();
    $second = $parser->parse($input['text'], $profile, CarbonImmutable::parse('2026-09-08 12:01:00 Europe/Berlin'), $permit)->toArray();
    expect($first['source'])->toBe('llm')
        ->and($first['constraints']['window_start'])->toBe('2026-09-08T12:00:00+02:00')
        ->and($second)->toBe($first);
    Http::assertSentCount(1);
});
