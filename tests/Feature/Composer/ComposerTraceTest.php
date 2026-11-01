<?php

use App\Composer\WorkspaceStore;
use App\Composer\WorkspaceUpgrade;
use App\Models\ComposerTrace;
use App\Models\ComposerWorkspace;
use App\Models\User;
use App\Privacy\ProcessingPurpose;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExternalProcessingFixtures;

/*
 * How each Composer answer was made, kept so a wrong one can be looked into (owner, 1 Nov
 * 2026, after a person's Saturday plan on a Sunday): the message, the lookups and what they
 * found, the answer shown. Never the Paperwork record; 30 days at most, and gone with the
 * conversation, the Paperwork record or the account.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 19:05', 'Europe/Berlin'));
    config()->set('services.llm', [
        'driver' => 'anthropic', 'base_url' => 'https://processor.example.test', 'model' => 'claude-haiku-5-5', 'key' => 'synthetic',
        'processor_name' => 'Synthetic processor', 'processor_privacy_url' => 'https://processor.example.test/privacy', 'prompt_version' => 'test.1',
    ]);
    app()->instance(WeatherService::class, new class extends WeatherService
    {
        public function __construct() {}

        public function getCurrentWeather(float $lat = 50.9375, float $lng = 6.9603): array
        {
            return ['available' => true, 'temperature' => 9, 'condition' => 'Overcast'];
        }

        public function getForecast(float $lat = 50.9375, float $lng = 6.9603): array
        {
            return ['available' => true, 'rain_summary' => 'Dry next 8 hours'];
        }
    });
});

function traceTurn(array $uses): array
{
    return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use',
        'content' => array_map(fn (array $use, int $i): array => ['type' => 'tool_use', 'id' => "toolu_{$i}", 'name' => $use[0], 'input' => $use[1]], $uses, array_keys($uses)),
        'usage' => ['input_tokens' => 900, 'output_tokens' => 120]];
}

function traceWorkspace(User $user, array $conversations): void
{
    ComposerWorkspace::query()->create(['user_id' => $user->id, 'revision' => 0, 'state' => [
        'schema' => WorkspaceUpgrade::SCHEMA, 'active' => $conversations[0], 'focus' => null, 'changes' => [], 'plans' => [],
        'conversations' => array_map(fn (string $id): array => ['id' => $id, 'title' => 'New conversation', 'created_at' => now()->toIso8601String(), 'messages' => []], $conversations),
    ]]);
}

function traceRow(User $user, string $conversation): void
{
    ComposerTrace::query()->create(['user_id' => $user->id, 'conversation_id' => $conversation, 'message' => 'Plan my Saturday', 'outcome' => 'ai', 'steps' => []]);
}

test('🔒 an answer is kept with its message, lookups and what was shown; Paperwork lookups only by name', function () {
    $user = User::factory()->onboarded()->create();
    traceWorkspace($user, ['initial']);
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(traceTurn([['weather', []], ['paperwork', []]]))
        ->push(traceTurn([['answer', ['sentences' => [['text' => 'It’s 9° and overcast, dry for the next hours.', 'uses' => ['weather']]], 'show' => []]]]))]);
    $this->actingAs($user);

    $revision = $this->getJson('/composer/workspace')->assertOk()->json('revision');
    $this->postJson('/composer/workspace', ['revision' => $revision, 'action' => 'message', 'text' => 'How is the weather?'])->assertOk();
    $messages = $this->postJson(route('composer.ask'), ['processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist)])
        ->assertOk()->json('conversations.0.messages');
    $shown = collect($messages)->last(fn (array $m): bool => ($m['request'] ?? null) === 'ask')['lead'];

    $trace = ComposerTrace::query()->sole();
    $steps = collect($trace->steps)->keyBy('tool');
    expect($trace)->user_id->toBe($user->id)->conversation_id->toBe('initial')->message->toBe('How is the weather?')->outcome->toBe('ai')
        ->shown->toBe($shown)
        ->and($steps->keys()->all())->toBe(['context', 'weather', 'paperwork'])
        ->and($steps['context']['result'])->toContain('Message: "How is the weather?"')
        ->and($steps['weather']['result'])->toContain('Overcast')
        ->and($steps['paperwork'])->input->toBeNull()->result->toBe('[Paperwork: not kept]');
});

test('🔒 traces go with their conversation, with the Paperwork record, with the account, and after 30 days', function () {
    $user = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    traceWorkspace($user, ['first', 'second']);
    traceRow($user, 'first');
    traceRow($user, 'second');
    traceRow($other, 'first');

    app(WorkspaceStore::class)->deleteConversation($user, 'first');
    expect(ComposerTrace::query()->where('user_id', $user->id)->pluck('conversation_id')->all())->toBe(['second'])
        ->and(ComposerTrace::query()->where('user_id', $other->id)->count())->toBe(1);

    app(WorkspaceStore::class)->purgePaperworkCopies($user->id);
    expect(ComposerTrace::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(ComposerTrace::query()->where('user_id', $other->id)->count())->toBe(1);

    traceRow($user, 'second');
    $user->delete();
    expect(ComposerTrace::query()->where('user_id', $user->id)->count())->toBe(0);

    $this->travel(ComposerTrace::RETENTION_DAYS + 1)->days();
    traceRow($other, 'fresh');
    $this->artisan('model:prune', ['--model' => [ComposerTrace::class]])->assertSuccessful();
    expect(ComposerTrace::query()->pluck('conversation_id')->all())->toBe(['fresh']);
});
