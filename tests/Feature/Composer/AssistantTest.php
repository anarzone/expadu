<?php

use App\Composer\WorkspaceUpgrade;
use App\Models\ComposerWorkspace;
use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use App\Personalisation\Pseudonym;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPermit;
use App\Privacy\ProcessingPurpose;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ExternalProcessingFixtures;

/*
 * Composer's assistant (owner, 22 Oct 2026): for an open request it looks things up, then
 * answers from what it found. It sees public data only, every sentence is checked against
 * the results it cites, and the person's one say-so covers the whole exchange.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 19:05', 'Europe/Berlin'));
    config()->set('services.llm', [
        'driver' => 'anthropic', 'base_url' => 'https://processor.example.test', 'model' => 'claude-haiku-5-5', 'key' => 'synthetic',
        'processor_name' => 'Synthetic processor', 'processor_privacy_url' => 'https://processor.example.test/privacy', 'prompt_version' => 'test.1',
        'assistant' => true, 'assistant_prompt_version' => 'test.assistant.1',
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
            return ['available' => true, 'rain_summary' => 'Rain from 22:00 (2h+)'];
        }
    });
});

/** A Messages reply holding these tool calls. */
function assistantTurn(array $uses): array
{
    return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use',
        'content' => array_map(fn (array $use, int $i): array => ['type' => 'tool_use', 'id' => "toolu_{$i}", 'name' => $use[0], 'input' => $use[1]], $uses, array_keys($uses)),
        'usage' => ['input_tokens' => 900, 'output_tokens' => 120]];
}

function assistantJazz(): Event
{
    $event = Event::factory()->create(['title' => 'Jazz im Stadtgarten', 'title_en' => null, 'location_name' => 'Stadtgarten', 'category' => 'music',
        'is_curated' => true, 'starts_at' => CarbonImmutable::parse('2026-11-05 20:00', 'Europe/Berlin'), 'ends_at' => CarbonImmutable::parse('2026-11-05 23:00', 'Europe/Berlin')]);
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [6.93, 50.94, $event->id]);

    return $event;
}

/** Sends "What's up?" as the page does and returns the answer. */
function assistantAsk($test): array
{
    $test->postJson('/composer/workspace', ['revision' => 0, 'action' => 'message', 'text' => 'What’s up?'])->assertOk();
    $test->postJson(route('composer.parse'), ['text' => 'What’s up?', 'processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerParse)])
        ->assertOk()->assertJsonPath('answer', true);

    return collect($test->postJson('/composer/workspace', ['revision' => 1, 'action' => 'answer'])->assertOk()->json('conversations.0.messages'))->last();
}

test('🔒 the assistant answers from what it looked up, never the person’s own day; a sentence its results don’t back is dropped', function () {
    $user = User::factory()->onboarded()->create();
    ComposerWorkspace::query()->create(['user_id' => $user->id, 'revision' => 0, 'state' => [
        'schema' => WorkspaceUpgrade::SCHEMA, 'active' => 'initial', 'focus' => null, 'changes' => [],
        'conversations' => [['id' => 'initial', 'title' => 'New conversation', 'created_at' => now()->toIso8601String(), 'messages' => []]],
        'plans' => ['2026-11-05' => ['date' => '2026-11-05', 'slots' => [['id' => 'custom:private', 'name' => 'PRIVATE-PLAN-7781', 'category' => 'custom',
            'start_at' => '2026-11-05T21:30:00+01:00', 'end_at' => '2026-11-05T22:30:00+01:00']]]],
    ]]);
    $jazz = assistantJazz();
    $id = "e{$jazz->id}@".CarbonImmutable::parse('2026-11-05 20:00', 'Europe/Berlin')->getTimestamp();
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['search_events', ['day' => 'today', 'topic' => 'music']], ['weather', []]]))
        ->push(assistantTurn([['answer', ['sentences' => [
            ['text' => 'Jazz im Stadtgarten starts at 20:00 at Stadtgarten.', 'uses' => [$id]],
            ['text' => 'There’s also a boat party on the Rhine at 21:00.', 'uses' => [$id]],
            ['text' => 'Rain is due from 22:00, so bring a jacket.', 'uses' => ['weather']],
        ], 'events' => [$id, 'e999@1'], 'places' => []]]]))]);
    $this->actingAs($user);

    $reply = assistantAsk($this);

    // Plainly casual: no read first, one round of lookups, then the answer.
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Jazz im Stadtgarten') && str_contains($request->body(), 'tool_result'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), 'PRIVATE-PLAN-7781'));
    expect($reply)->kind->toBe('around')->written->toBe('ai')
        ->text->toBe('Jazz im Stadtgarten starts at 20:00 at Stadtgarten. Rain is due from 22:00, so bring a jacket.')
        ->and(collect($reply['events'])->pluck('title')->all())->toBe(['Jazz im Stadtgarten'])
        ->and(collect($reply['facts'])->pluck('value')->all())->toContain('PRIVATE-PLAN-7781');
});

test('🔒 a withdrawal during the exchange stops the assistant’s next call', function () {
    $user = User::factory()->onboarded()->create();
    assistantJazz();
    Http::fake(['processor.example.test/*' => function () use ($user) {
        // The person takes back their say-so while the first lookup is under way.
        app(ProcessingConsentStore::class)->withdraw($user);

        return Http::response(assistantTurn([['weather', []]]));
    }]);
    $this->actingAs($user);

    $reply = assistantAsk($this);

    Http::assertSentCount(1);
    expect($reply)->kind->toBe('around')->written->toBe('listings');
});

test('🔒 only Composer’s read can lend its say-so, and only to the assistant', function () {
    $user = User::factory()->onboarded()->create();
    $read = app(ProcessingConsentStore::class)->grant($user, ProcessingPurpose::ComposerParse, ['text' => 'hi', 'veedel' => null],
        ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerParse));
    $store = app(ProcessingConsentStore::class);

    expect($store->derive($read, ProcessingPurpose::FactExtraction, ['text' => 'hi', 'veedel' => null]))->toBeNull()
        ->and($store->derive($read, ProcessingPurpose::ComposerAssist, ['text' => 'hi', 'veedel' => null]))->toBeInstanceOf(ProcessingPermit::class)
        ->and($store->derive(new ProcessingPermit($read->id, $user->id + 1), ProcessingPurpose::ComposerAssist, ['text' => 'hi', 'veedel' => null]))->toBeNull();
});

test('🔒 without the opt-in it gets no taste or plans to look at, and the answer invites the opt-in', function () {
    $user = User::factory()->onboarded()->create();
    $jazz = assistantJazz();
    $id = "e{$jazz->id}@".CarbonImmutable::parse('2026-11-05 20:00', 'Europe/Berlin')->getTimestamp();
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['search_events', ['day' => 'today']]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'Jazz im Stadtgarten starts at 20:00.', 'uses' => [$id]]],
            'events' => [$id], 'places' => [], 'because' => [['id' => $id, 'text' => 'You like culture', 'uses' => ['t1']]]]]]))]);
    $this->actingAs($user);

    $reply = assistantAsk($this);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), 'my_taste') || str_contains($request->body(), 'my_day'));
    expect($reply)->invite->toBeTrue()->and($reply)->not->toHaveKey('taste')
        ->and($reply['events'][0])->not->toHaveKey('because');
});

test('🔒 with the opt-in it may look at their taste: a "because" holds only what their taste says, and "not for me" stays out', function () {
    $user = User::factory()->onboarded()->create();
    $user->userSetting()->updateOrCreate([], ['settings' => ['personalisation' => true]]);
    $subject = Pseudonym::for($user);
    DB::table('user_affinities')->insert(array_map(fn (array $row): array => ['subject_key' => $subject, 'dimension' => $row[0], 'key' => $row[1],
        'context' => 'all', 'score' => $row[2], 'signals_count' => $row[3], 'updated_at' => now()], [['category', 'culture', 12.0, 6], ['veedel', 'Ehrenfeld', 8.0, 5]]));
    $jazz = assistantJazz();
    $skipped = assistantJazz();
    $skipped->update(['title' => 'Skipped Jazz']);
    DB::table('user_place_negatives')->insert(['subject_key' => $subject, 'item_type' => 'event', 'item_id' => $skipped->id, 'context' => 'all',
        'score' => -4.0, 'hidden_until' => now()->addDays(90), 'signals_count' => 1, 'updated_at' => now()]);
    $id = "e{$jazz->id}@".CarbonImmutable::parse('2026-11-05 20:00', 'Europe/Berlin')->getTimestamp();
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['my_taste', []], ['search_events', ['day' => 'today']]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'Since you like culture, Jazz im Stadtgarten starts at 20:00.', 'uses' => [$id, 't1']]],
            'events' => [$id], 'places' => [], 'because' => [
                ['id' => $id, 'text' => 'You like culture', 'uses' => ['t1']],
                ['id' => $id, 'text' => 'You saved two jazz nights', 'uses' => ['t1']],
            ]]]]))]);
    $this->actingAs($user);

    $reply = assistantAsk($this);

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'likes a lot culture') && ! str_contains($request->body(), 'Skipped Jazz'));
    expect($reply)->text->toBe('Since you like culture, Jazz im Stadtgarten starts at 20:00.')
        ->taste->toBeTrue()->and($reply)->not->toHaveKey('invite')
        ->and($reply['events'][0]['because'])->toBe('You like culture');
});

test('🔒 the page reads how far its own request has got, and nobody else\'s', function () {
    $user = User::factory()->onboarded()->create();
    $requestId = (string) Str::uuid();
    Cache::put("composer:progress:{$user->id}:{$requestId}", ['step' => 'writing', 'looked' => ['listings', 'weather'], 'at' => 1], 60);

    $this->actingAs($user)->getJson("/composer/progress/{$requestId}")->assertOk()
        ->assertJsonPath('step', 'writing')->assertJsonPath('looked', ['listings', 'weather']);
    $this->actingAs(User::factory()->onboarded()->create())->getJson("/composer/progress/{$requestId}")->assertOk()->assertJsonPath('step', null);
});

test('"not this" on a suggested event is recorded; a place\'s goes through its feedback', function () {
    $user = User::factory()->onboarded()->create();
    $user->userSetting()->updateOrCreate([], ['settings' => ['personalisation' => true]]);
    $jazz = assistantJazz();
    $spot = Spot::factory()->create();

    $this->actingAs($user)->postJson('/api/signals', ['signals' => [['action' => 'not_for_me', 'surface' => 'composer', 'item_type' => 'event', 'item_id' => $jazz->id]]])
        ->assertStatus(202);
    $this->postJson('/api/signals', ['signals' => [['action' => 'not_for_me', 'surface' => 'composer', 'item_type' => 'spot', 'item_id' => $spot->id]]])
        ->assertUnprocessable()->assertJsonValidationErrors('signals.0.action');
    expect(DB::table('user_signals')->where('action', 'not_for_me')->where('item_type', 'event')->where('item_id', $jazz->id)->exists())->toBeTrue();
});
