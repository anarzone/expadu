<?php

use App\Models\User;
use App\Privacy\ProcessingPurpose;
use App\Services\WeatherService;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExternalProcessingFixtures;

/* Composer on the iPhone: the web's own routes and rules (routes/app/composer-shared.php), with the app's token. */

beforeEach(function () {
    config()->set('services.llm', [
        'driver' => 'anthropic', 'base_url' => 'https://processor.example.test', 'model' => 'synthetic-assistant', 'key' => 'synthetic',
        'processor_name' => 'Synthetic processor', 'processor_privacy_url' => 'https://processor.example.test/privacy', 'prompt_version' => 'test.1',
    ]);
    Http::fake(['processor.example.test/*' => Http::response([
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'synthetic-assistant', 'stop_reason' => 'tool_use',
        'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'answer', 'input' => ['sentences' => [['text' => 'I can look that up for you.', 'uses' => []]], 'show' => []]]],
        'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
    ])]);
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
});

test('🔒 Composer needs a token and a confirmed address', function () {
    $this->getJson('/api/v1/composer/workspace')->assertUnauthorized();
    $this->postJson('/api/v1/composer/ask')->assertUnauthorized();

    $unconfirmed = User::factory()->unverified()->create();
    $this->withToken($unconfirmed->createToken('iPhone')->plainTextToken)->getJson('/api/v1/composer/workspace')->assertForbidden();
});

test('the app reads and writes the same workspace the web does', function () {
    $user = User::factory()->onboarded()->create();
    $token = $user->createToken('iPhone')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/composer/workspace', ['revision' => 0, 'action' => 'message', 'text' => 'Somewhere quiet to work'])->assertOk();
    app('auth')->forgetGuards();
    $web = $this->actingAs($user)->getJson('/composer/workspace')->assertOk()->json();

    expect(collect($web['conversations'][0]['messages'])->pluck('text')->all())->toBe(['Somewhere quiet to work'])
        ->and($web['revision'])->toBe(1);
});

test('🔒 without the person\'s go-ahead for the request, the app\'s ask sends nothing out', function () {
    $token = User::factory()->onboarded()->create()->createToken('iPhone')->plainTextToken;
    $this->withToken($token)->postJson('/api/v1/composer/workspace', ['revision' => 0, 'action' => 'message', 'text' => 'Anmeldung appointment'])->assertOk();

    $answer = collect($this->withToken($token)->postJson('/api/v1/composer/ask')->assertOk()->json('conversations.0.messages'))->last();

    Http::assertNothingSent();
    expect($answer)->without_ai->toBe('unpermitted')->role->toBe('assistant');
});

test('with the go-ahead in the ask, the app\'s message is answered once', function () {
    $token = User::factory()->onboarded()->create()->createToken('iPhone')->plainTextToken;
    $this->withToken($token)->postJson('/api/v1/composer/workspace', ['revision' => 0, 'action' => 'message', 'text' => 'What is on tonight?'])->assertOk();

    $answer = collect($this->withToken($token)->postJson('/api/v1/composer/ask', [
        'processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist),
    ])->assertOk()->json('conversations.0.messages'))->last();

    Http::assertSentCount(1);
    expect($answer)->role->toBe('assistant')->and($answer['without_ai'] ?? null)->toBeNull();
});

test('🔒 another person\'s conversation can\'t be renamed or deleted from the app', function () {
    $owner = User::factory()->onboarded()->create();
    $id = $this->withToken($owner->createToken('iPhone')->plainTextToken)
        ->postJson('/api/v1/composer/workspace', ['revision' => 0, 'action' => 'message', 'text' => 'My private question'])->assertOk()->json('active');
    $stranger = User::factory()->onboarded()->create()->createToken('iPhone')->plainTextToken;
    app('auth')->forgetGuards();

    $this->withToken($stranger)->patchJson("/api/v1/composer/conversations/{$id}", ['title' => 'Mine now'])->assertNotFound();
    $this->withToken($stranger)->deleteJson("/api/v1/composer/conversations/{$id}")->assertNotFound();
    app('auth')->forgetGuards();
    expect($this->actingAs($owner)->getJson('/composer/workspace')->json('conversations.0.messages.0.text'))->toBe('My private question');
});

test('the app has no Composer page, hand-off or compose route; the web keeps them', function () {
    $token = User::factory()->onboarded()->create()->createToken('iPhone')->plainTextToken;

    expect($this->withToken($token)->getJson('/api/v1/composer')->status())->toBeIn([404, 405])
        ->and($this->withToken($token)->postJson('/api/v1/composer/entry', ['prompt' => 'x'])->status())->toBeIn([404, 405])
        ->and($this->withToken($token)->postJson('/api/v1/composer/compose', [])->status())->toBeIn([404, 405])
        ->and(route('composer.ask', absolute: false))->toBe('/composer/ask')
        ->and(route('api.composer.ask', absolute: false))->toBe('/api/v1/composer/ask');
});

test('🔒 withdrawing Composer from the app stops it, as on the web', function () {
    $token = User::factory()->onboarded()->create()->createToken('iPhone')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/privacy/processing/composer_assist')->assertOk()->assertJsonPath('purpose', 'composer_assist');
    $this->withToken($token)->deleteJson('/api/v1/privacy/processing/composer_assist')->assertOk()->assertJsonPath('withdrawn', true);
});
