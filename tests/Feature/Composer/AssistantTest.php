<?php

use App\Composer\Assistant\Assistant;
use App\Composer\WorkspaceUpgrade;
use App\Models\ComposerWorkspace;
use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use App\Personalisation\Pseudonym;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;
use App\Services\NearbyStopService;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ExternalProcessingFixtures;

/*
 * Composer's assistant (owner, 22 Oct 2026: one assistant for every message): it looks
 * things up with its tools, then answers from what it found. Every sentence is checked
 * against the results it cites, the person's one say-so covers the whole exchange, and
 * what's theirs stays theirs: where they are only as a stop and a Veedel, their records
 * shown by Composer itself, their taste and day only with the opt-in.
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

/** Sends a message as the page does (into the conversation, then Composer's answer) and returns the answer. */
function assistantAsk($test, string $text = 'What’s up?', array $ask = []): array
{
    $revision = $test->getJson('/composer/workspace')->assertOk()->json('revision');
    $test->postJson('/composer/workspace', ['revision' => $revision, 'action' => 'message', 'text' => $text])->assertOk();
    $messages = $test->postJson(route('composer.ask'), [...$ask, 'processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist)])
        ->assertOk()->json('conversations.0.messages');

    return collect($messages)->last(fn (array $m): bool => ($m['request'] ?? null) === 'ask');
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
        ], 'show' => [$id, 'e999@1']]]]))]);
    $this->actingAs($user);

    $reply = assistantAsk($this);

    // One round of lookups, then the answer: no reader first.
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Jazz im Stadtgarten') && str_contains($request->body(), 'tool_result'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), 'PRIVATE-PLAN-7781'));
    expect($reply)->written->toBe('ai')->read->toBe(['listings', 'weather'])
        ->lead->toBe('Jazz im Stadtgarten starts at 20:00 at Stadtgarten. Rain is due from 22:00, so bring a jacket.')
        ->and(collect($reply['blocks'])->pluck('type')->all())->toBe(['events'])
        ->and(collect($reply['events'])->pluck('title')->all())->toBe(['Jazz im Stadtgarten']);
});

test('🔒 where the person is reaches the AI as their nearest stop and Veedel, never coordinates or the start they typed', function () {
    $user = User::factory()->onboarded()->create();
    app()->instance(NearbyStopService::class, new class extends NearbyStopService
    {
        public function getWalkableStops(float $lat, float $lng, int $radiusMeters = 500): array
        {
            return [['id' => 1, 'name' => 'Venloer Str./Gürtel', 'lines' => ['3', '4'], 'lat' => 50.9490, 'lng' => 6.9190, 'distance_m' => 120, 'walk_min' => 2]];
        }
    });
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['where_am_i', []]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'Your nearest stop is Venloer Str./Gürtel, a 2 min walk.', 'uses' => ['here']]], 'show' => []]]]))]);
    $this->actingAs($user);

    $reply = assistantAsk($this, 'where I am?', ['lat' => 50.94971, 'lng' => 6.91773, 'from_label' => 'PRIVATE-START-3390']);

    Http::assertSent(fn (Request $request): bool => str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'Nearest KVB stop: Venloer Str./Gürtel'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), '50.949') || str_contains($request->body(), '6.917')
        || str_contains($request->body(), 'PRIVATE-START-3390'));
    expect($reply)->lead->toBe('Your nearest stop is Venloer Str./Gürtel, a 2 min walk.')
        ->and($reply['blocks'][0])->type->toBe('where')->stop->toBe(['name' => 'Venloer Str./Gürtel', 'walk_min' => 2, 'lines' => ['3', '4']]);
});

test('🔒 their next plan and their Paperwork are shown by Composer itself, never sent to the AI', function () {
    $user = User::factory()->onboarded()->create();
    ComposerWorkspace::query()->create(['user_id' => $user->id, 'revision' => 0, 'state' => [
        'schema' => WorkspaceUpgrade::SCHEMA, 'active' => 'initial', 'focus' => null, 'changes' => [],
        'conversations' => [['id' => 'initial', 'title' => 'New conversation', 'created_at' => now()->toIso8601String(), 'messages' => []]],
        'plans' => ['2026-11-05' => ['date' => '2026-11-05', 'slots' => [['id' => 'custom:private', 'name' => 'PRIVATE-PLAN-7781', 'category' => 'custom',
            'start_at' => '2026-11-05T21:30:00+01:00', 'end_at' => '2026-11-05T22:30:00+01:00']]]],
    ]]);
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['my_next', []], ['paperwork', []]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'Here’s your next plan.', 'uses' => ['next']]], 'show' => []]]]))]);
    $this->actingAs($user);

    $reply = assistantAsk($this, "what's next for me?");

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), 'PRIVATE-PLAN-7781'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Its contents stay private'));
    // Shown whether or not the answer named them: Composer's own words, from their records.
    expect(collect($reply['blocks'])->pluck('type')->all())->toBe(['note', 'note'])
        ->and($reply['blocks'][0]['text'])->toBe('Your next plan: PRIVATE-PLAN-7781, today at 21:30.')
        ->and($reply['text'])->toContain('PRIVATE-PLAN-7781');
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
    expect($reply)->written->toBe('code')->without_ai->toBe('failed');
});

test('🔒 without the opt-in it gets no taste or plans to look at, and the answer invites the opt-in', function () {
    $user = User::factory()->onboarded()->create();
    $jazz = assistantJazz();
    $id = "e{$jazz->id}@".CarbonImmutable::parse('2026-11-05 20:00', 'Europe/Berlin')->getTimestamp();
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['search_events', ['day' => 'today']]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'Jazz im Stadtgarten starts at 20:00.', 'uses' => [$id]]],
            'show' => [$id], 'because' => [['id' => $id, 'text' => 'You like culture', 'uses' => ['t1']]]]]]))]);
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
            'show' => [$id], 'because' => [
                ['id' => $id, 'text' => 'You like culture', 'uses' => ['t1']],
                ['id' => $id, 'text' => 'You saved two jazz nights', 'uses' => ['t1']],
            ]]]]))]);
    $this->actingAs($user);

    $reply = assistantAsk($this);

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'likes a lot culture') && ! str_contains($request->body(), 'Skipped Jazz'));
    expect($reply)->read->toBe(['taste', 'listings'])->lead->toBe('Since you like culture, Jazz im Stadtgarten starts at 20:00.')
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

test('🔒 Composer\'s notice names what it sends, and that where you are goes only as a stop and a Veedel', function () {
    $categories = $this->actingAs(User::factory()->onboarded()->create())->getJson('/privacy/processing/composer_assist')->assertOk()->json('payload_categories');

    expect($categories)->toContain('your_text', 'your_earlier_messages_in_this_conversation', 'page_you_asked_on', 'your_nearest_stop_and_veedel',
        'your_saved_places_names', 'your_taste_if_personalised', 'your_plans_if_personalised')
        ->and(collect($categories)->contains(fn (string $c): bool => str_contains($c, 'coordinates') || str_contains($c, 'paperwork_record')))->toBeFalse();
});

test('a follow-up is read in its conversation: "nope, something else" leaves out what was suggested', function () {
    $user = User::factory()->onboarded()->create();
    $jazz = assistantJazz();
    $other = assistantJazz();
    $other->update(['title' => 'Salsa Night']);
    $jazzId = "e{$jazz->id}@".CarbonImmutable::parse('2026-11-05 20:00', 'Europe/Berlin')->getTimestamp();
    $salsaId = "e{$other->id}@".CarbonImmutable::parse('2026-11-05 20:00', 'Europe/Berlin')->getTimestamp();
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['search_events', ['day' => 'today']]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'Jazz im Stadtgarten starts at 20:00.', 'uses' => [$jazzId]]], 'show' => [$jazzId]]]]))
        ->push(assistantTurn([['search_events', ['day' => 'today', 'leave_out_earlier' => true]]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'Salsa Night starts at 20:00.', 'uses' => [$salsaId]]], 'show' => [$salsaId, $jazzId]]]]))]);
    $this->actingAs($user);

    assistantAsk($this, "I'm bored");
    $reply = assistantAsk($this, 'nope, I want something else');

    // The AI is given the conversation; its second lookup leaves Jazz out, so it can't show it again.
    Http::assertSentCount(4);
    $recorded = Http::recorded()->map(fn (array $pair): string => $pair[0]->body())->values();
    expect($recorded[2])->toContain('They asked:')->toContain('Jazz im Stadtgarten')
        ->and($recorded[3])->toContain('Salsa Night')
        ->and(str_contains(collect(json_decode($recorded[3], true)['messages'])->last()['content'][0]['content'], 'Jazz im Stadtgarten'))->toBeFalse()
        ->and($reply)->lead->toBe('Salsa Night starts at 20:00.')
        ->and(collect($reply['events'])->pluck('title')->all())->toBe(['Salsa Night'])
        ->and(Assistant::history([['role' => 'assistant', 'text' => 'x', 'events' => [['id' => 7, 'title' => 'Jazz']], 'destination' => ['name' => 'Cafe Nova', 'area' => 'Deutz']]])['suggested'])
        ->toBe(['event' => [7 => 'Jazz'], 'spot' => [], 'areas' => ['Deutz'], 'places' => [['name' => 'Cafe Nova', 'area' => 'Deutz', 'start' => null]]]);
});

test('without the AI, Composer shows what\'s around, and a fact shows once per conversation', function () {
    $user = User::factory()->onboarded()->create();
    config()->set('services.llm.driver', 'off');
    Http::fake();
    $this->actingAs($user);

    $first = assistantAsk($this);
    $second = assistantAsk($this, 'hi');

    Http::assertNothingSent();
    expect($first)->without_ai->toBe('off')->and(collect($first['facts'])->pluck('label')->all())->toContain('Weather')
        ->and($second)->not->toHaveKey('facts');
});
