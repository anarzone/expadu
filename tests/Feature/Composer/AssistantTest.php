<?php

use App\Composer\Answers\EventAnswers;
use App\Composer\Assistant\Assistant;
use App\Composer\Assistant\AssistantTools;
use App\Composer\CandidateRepository;
use App\Composer\WorkspaceUpgrade;
use App\Enums\GermanLevel;
use App\Enums\LocationSource;
use App\Models\ComposerWorkspace;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\FavouriteStop;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Personalisation\Memory;
use App\Personalisation\Pseudonym;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;
use App\Services\LocationContext;
use App\Services\NearbyStopService;
use App\Services\WeatherService;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\JourneyResult;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
    $state = $test->postJson(route('composer.ask'), [...$ask, 'processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist)])
        ->assertOk()->json();
    // The conversation it was asked in (a new one after "fresh").
    $messages = collect($state['conversations'])->firstWhere('id', $state['active'] ?? $state['conversations'][0]['id'])['messages'];

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
    $user->userSetting()->updateOrCreate([], ['settings' => ['personalisation' => true], 'sixteen_or_older' => true]);
    $subject = Pseudonym::for($user);
    DB::table('user_affinities')->insert(array_map(fn (array $row): array => ['subject_key' => $subject, 'dimension' => $row[0], 'key' => $row[1],
        'context' => 'all', 'score' => $row[2], 'signals_count' => $row[3], 'updated_at' => now()], [['category', 'music', 12.0, 6], ['veedel', 'Ehrenfeld', 8.0, 5]]));
    $jazz = assistantJazz();
    $skipped = assistantJazz();
    $skipped->update(['title' => 'Skipped Jazz']);
    DB::table('user_place_negatives')->insert(['subject_key' => $subject, 'item_type' => 'event', 'item_id' => $skipped->id, 'context' => 'all',
        'score' => -4.0, 'hidden_until' => now()->addDays(90), 'signals_count' => 1, 'updated_at' => now()]);
    $id = "e{$jazz->id}@".CarbonImmutable::parse('2026-11-05 20:00', 'Europe/Berlin')->getTimestamp();
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['my_taste', []], ['search_events', ['day' => 'today']]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'Since you like music, Jazz im Stadtgarten starts at 20:00.', 'uses' => [$id, 't1']]],
            'show' => [$id], 'because' => [
                ['id' => $id, 'text' => 'You like music', 'uses' => ['t1']],
                ['id' => $id, 'text' => 'You saved two jazz nights', 'uses' => ['t1']],
            ]]]]))]);
    $this->actingAs($user);

    $reply = assistantAsk($this);

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'likes a lot music') && ! str_contains($request->body(), 'Skipped Jazz'));
    expect($reply)->read->toBe(['taste', 'listings'])->lead->toBe('Since you like music, Jazz im Stadtgarten starts at 20:00.')
        ->taste->toBeTrue()->and($reply)->not->toHaveKey('invite')
        ->and($reply['events'][0]['because'])->toBe('You like music');
});

test('🔒 "I\'m vegetarian" is remembered with Undo and reaches the next conversation\'s first message, cited like any fact', function () {
    $user = User::factory()->onboarded()->create(['german_level' => GermanLevel::A1]);
    $user->userSetting()->updateOrCreate([], ['settings' => ['personalisation' => true], 'sixteen_or_older' => true]);
    $jazz = assistantJazz();
    $id = "e{$jazz->id}@".CarbonImmutable::parse('2026-11-05 20:00', 'Europe/Berlin')->getTimestamp();
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['remember', ['fact' => 'vegetarian']]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'I’ll remember that.', 'uses' => []]], 'show' => []]]]))
        ->push(assistantTurn([['search_events', ['day' => 'today']]]))
        ->push(assistantTurn([['answer', ['sentences' => [
            ['text' => 'Jazz im Stadtgarten starts at 20:00.', 'uses' => [$id]],
            ['text' => 'You’re vegetarian, so eat before you go.', 'uses' => ['k1']],
        ], 'show' => [$id]]]]))]);
    $this->actingAs($user);

    $told = assistantAsk($this, 'I’m vegetarian');

    expect(collect($told['blocks'])->firstWhere('type', 'done'))->text->toBe('Remembered: Vegetarian.')->undo->not->toBeNull()
        ->and(app(Memory::class)->facts($user))->sequence(fn ($fact) => $fact->text->toBe('Vegetarian')->source->toBe('told'));

    $revision = $this->getJson('/composer/workspace')->json('revision');
    $this->postJson('/composer/workspace', ['revision' => $revision, 'action' => 'fresh'])->assertOk();
    $next = assistantAsk($this, 'anything tonight?');

    expect(Http::recorded()[2][0]->body())->toContain('What you know about them:')->toContain('[k1] Vegetarian')->toContain('[u2] German: A1')
        ->and($next['lead'])->toBe('Jazz im Stadtgarten starts at 20:00. You’re vegetarian, so eat before you go.')
        // Said under the answer, from what they told Composer.
        ->and($next['told'])->toBe(['Vegetarian'])
        ->and($told)->not->toHaveKey('told');
});

test('🔒 without the opt-in nothing about them is given and remember keeps nothing', function () {
    $user = User::factory()->onboarded()->create(['german_level' => GermanLevel::A1]);
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['remember', ['fact' => 'Vegetarian']]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'Turn on personalisation and I can remember that.', 'uses' => []]], 'show' => []]]]))]);
    $this->actingAs($user);

    assistantAsk($this, 'I’m vegetarian');

    // Not offered, and when called anyway, refused.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), 'What you know about them'));
    expect(Http::recorded()[0][0]->body())->not->toContain('"name":"remember"')
        ->and(Http::recorded()[1][0]->body())->toContain('needs personalisation turned on')
        ->and(DB::table('user_facts')->count())->toBe(0);
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
    $user->userSetting()->updateOrCreate([], ['settings' => ['personalisation' => true], 'sixteen_or_older' => true]);
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
        ->push(assistantTurn([['search_events', ['day' => 'today', 'leave_out_earlier' => true, 'asking_for_others' => 'something else']]]))
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
        ->toBe(['event' => [7 => 'Jazz'], 'spot' => [], 'areas' => ['Deutz'], 'places' => [['name' => 'Cafe Nova', 'area' => 'Deutz', 'start' => null]], 'plans' => [], 'lines' => []]);
});

test('the same question again leaves nothing out: only their new words asking for others do', function () {
    $user = User::factory()->onboarded()->create();
    $jazz = assistantJazz();
    $jazzId = "e{$jazz->id}@".CarbonImmutable::parse('2026-11-05 20:00', 'Europe/Berlin')->getTimestamp();
    $answer = assistantTurn([['answer', ['sentences' => [['text' => 'Jazz im Stadtgarten starts at 20:00.', 'uses' => [$jazzId]]], 'show' => [$jazzId]]]]);
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['search_events', ['day' => 'today']]]))->push($answer)
        // Asked again, the AI leaves out what it showed, quoting words they'd said before: nothing is left out.
        ->push(assistantTurn([['search_events', ['day' => 'today', 'leave_out_earlier' => true, 'asking_for_others' => 'what is on tonight']]]))->push($answer)
        // Or with no words of theirs at all: nothing is left out either.
        ->push(assistantTurn([['search_events', ['day' => 'today', 'leave_out_earlier' => true]]]))->push($answer)]);
    $this->actingAs($user);

    assistantAsk($this, "What's on tonight?");
    $again = assistantAsk($this, "what's on tonight?");
    $third = assistantAsk($this, 'and tonight?');

    $recorded = Http::recorded()->map(fn (array $pair): string => $pair[0]->body())->values();
    expect(collect($again['events'])->pluck('title')->all())->toBe(['Jazz im Stadtgarten'])
        ->and(collect($third['events'])->pluck('title')->all())->toBe(['Jazz im Stadtgarten'])
        ->and($recorded[2])->toContain('They sent this same message before')
        ->and($recorded[4])->not->toContain('They sent this same message before');
});

test('an answer saying a change was made, with nothing behind it, gets another round: the change is made by its tool', function () {
    $user = User::factory()->onboarded()->create();
    $jazz = assistantJazz();
    $id = "e{$jazz->id}@".CarbonImmutable::parse('2026-11-05 20:00', 'Europe/Berlin')->getTimestamp();
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'Saved Jazz im Stadtgarten for later.', 'uses' => []]], 'show' => []]]]))
        ->push(assistantTurn([['save_event', ['event' => $id, 'saved' => true]]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'Saved Jazz im Stadtgarten.', 'uses' => []]], 'show' => []]]]))]);
    $this->actingAs($user);

    $reply = assistantAsk($this, 'save the jazz one for later');

    Http::assertSentCount(3);
    expect(Http::recorded()[1][0]->body())->toContain('says a change was made, but none was made')
        ->and(EventBookmark::query()->where('user_id', $user->id)->where('event_id', $jazz->id)->exists())->toBeTrue()
        ->and(collect($reply['blocks'])->pluck('type')->all())->toContain('done');
});

test('an answer pointing at a card it doesn\'t show gets another round: the card comes from its tool', function () {
    $user = User::factory()->onboarded()->create();
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'The place to do it is shown on the card.', 'uses' => []]], 'show' => []]]]))
        ->push(assistantTurn([['open_in_app', ['what' => 'delete_account']]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'The place to do it is shown on the card.', 'uses' => []]], 'show' => []]]]))]);
    $this->actingAs($user);

    $reply = assistantAsk($this, 'delete my account');

    Http::assertSentCount(3);
    expect(collect($reply['blocks'])->pluck('type')->all())->toContain('handoff')
        ->and($reply['lead'])->toBe('The place to do it is shown on the card.');
});

test('an answer saying it made a change, with none made, gets another round: the change is made by its tool', function () {
    $user = User::factory()->onboarded()->create();
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'I\'ve turned off weather notifications for you.', 'uses' => []]], 'show' => []]]]))
        ->push(assistantTurn([['set_notifications', ['kind' => 'weather', 'on' => false]]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'I\'ve turned off weather notifications for you.', 'uses' => []]], 'show' => []]]]))]);
    $this->actingAs($user);

    $reply = assistantAsk($this, 'turn off weather alerts');

    Http::assertSentCount(3);
    expect($user->fresh()->wantsNotification('weather'))->toBeFalse()
        ->and(collect($reply['blocks'])->pluck('type')->all())->toContain('done')
        ->and($reply['lead'])->toBe('I\'ve turned off weather notifications for you.');
});

test('place cards come nearest first, in the search\'s order, whichever the AI chose', function () {
    $user = User::factory()->onboarded()->create();
    $near = Spot::factory()->create(['name' => 'Café Near', 'category' => 'cafe', 'veedel' => 'Ehrenfeld', 'is_active' => true, 'lat' => 50.9500, 'lng' => 6.9180]);
    $far = Spot::factory()->create(['name' => 'Café Far', 'category' => 'cafe', 'veedel' => 'Deutz', 'is_active' => true, 'lat' => 50.9380, 'lng' => 6.9750]);
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['search_places', ['kinds' => ['cafe']]]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'Café Far is in Deutz.', 'uses' => ["spot:{$far->id}"]]],
            'show' => ["spot:{$far->id}", "spot:{$near->id}"]]]]))]);
    $this->actingAs($user);

    $reply = assistantAsk($this, 'a café?', ['lat' => 50.9497, 'lng' => 6.9177]);

    expect(collect($reply['places'] ?? collect($reply['blocks'])->firstWhere('type', 'places')['places'] ?? [])->pluck('name')->all())
        ->toBe(['Café Near', 'Café Far']);
});

test('🔒 "a climbing wall or a gym": both searches get cards, and the one its words left out is named', function () {
    // places_matrix.gym in the 30 Oct grade: two searches ran, the answer spoke only of the gym.
    $user = User::factory()->onboarded()->create();
    $gyms = collect(range(1, 6))->map(fn (int $i) => Spot::factory()->create(['name' => "Gym {$i}", 'category' => 'fitness_centre', 'veedel' => 'Ehrenfeld',
        'is_active' => true, 'lat' => 50.9500 + $i * 0.0005, 'lng' => 6.9180]));
    $wall = Spot::factory()->create(['name' => 'Kletterfabrik', 'category' => 'climbing', 'veedel' => 'Ehrenfeld', 'is_active' => true, 'lat' => 50.9560, 'lng' => 6.9200]);
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['search_places', ['kinds' => ['fitness_centre']]], ['search_places', ['kinds' => ['climbing']]]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'Gym 1 is the nearest gym.', 'uses' => ["spot:{$gyms[0]->id}"]]],
            'show' => ["spot:{$gyms[0]->id}"]]]]))]);
    $this->actingAs($user);

    $reply = assistantAsk($this, 'a climbing wall or a gym near me?', ['lat' => 50.9497, 'lng' => 6.9177]);

    $cards = collect(collect($reply['blocks'])->firstWhere('type', 'places')['places'] ?? [])->pluck('name')->all();
    expect($cards)->toContain('Kletterfabrik')->toContain('Gym 1')->toHaveCount(6)
        ->and($reply['lead'])->toBe('Gym 1 is the nearest gym. Kletterfabrik (climbing) is also on the cards.');
});

test('an answer after a lookup that says it will make a change, with none made, gets a round to make it', function () {
    $user = User::factory()->onboarded()->create();
    $jazz = assistantJazz();
    $id = "e{$jazz->id}@".CarbonImmutable::parse('2026-11-05 20:00', 'Europe/Berlin')->getTimestamp();
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['search_events', ['day' => 'today']]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'I\'ll save Jazz im Stadtgarten for later.', 'uses' => [$id]]], 'show' => [$id]]]]))
        ->push(assistantTurn([['save_event', ['event' => $id]]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'Saved Jazz im Stadtgarten.', 'uses' => []]], 'show' => []]]]))]);
    $this->actingAs($user);

    $reply = assistantAsk($this, 'save the jazz one tonight');

    Http::assertSentCount(4);
    expect(Http::recorded()[2][0]->body())->toContain('says a change was made, but none was made')
        ->and(EventBookmark::query()->where('user_id', $user->id)->where('event_id', $jazz->id)->exists())->toBeTrue()
        ->and(collect($reply['blocks'])->pluck('type')->all())->toContain('done');
});

test('a place they name is looked up by its name: its card, which one when several have it, and none when none has', function () {
    $user = User::factory()->onboarded()->create();
    $wohnraum = Spot::factory()->create(['name' => 'Café Wohnraum', 'category' => 'cafe', 'veedel' => 'Nippes', 'is_active' => true, 'lat' => 50.9640, 'lng' => 6.9510]);
    Spot::factory()->create(['name' => 'Café Nova', 'category' => 'cafe', 'veedel' => 'Deutz', 'is_active' => true, 'lat' => 50.9380, 'lng' => 6.9750]);
    Spot::factory()->create(['name' => 'Café Nova', 'category' => 'cafe', 'veedel' => 'Ehrenfeld', 'is_active' => true, 'lat' => 50.9500, 'lng' => 6.9180]);
    $said = fn (string $text): array => ['answer', ['sentences' => [['text' => $text, 'uses' => []]], 'show' => []]];
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['find_place', ['name' => 'Café Wohnraum']]]))
        ->push(assistantTurn([$said('Café Wohnraum is in Nippes.')]))
        ->push(assistantTurn([['find_place', ['name' => 'Café Nova']]]))
        ->push(assistantTurn([$said('There\'s more than one Café Nova.')]))
        ->push(assistantTurn([['find_place', ['name' => 'Café Nowhere-Such']]]))
        ->push(assistantTurn([$said('No place called Café Nowhere-Such is among Expadu\'s places.')]))]);
    $this->actingAs($user);

    $one = assistantAsk($this, 'Is Café Wohnraum good for working on a laptop?', ['lat' => 50.9497, 'lng' => 6.9177]);
    $two = assistantAsk($this, 'what about Café Nova?');
    $none = assistantAsk($this, 'and Café Nowhere-Such?');

    expect(collect(collect($one['blocks'])->firstWhere('type', 'places')['places'] ?? [])->pluck('id')->all())->toBe([$wohnraum->id])
        ->and(collect(collect($two['blocks'])->firstWhere('type', 'choices')['options'] ?? [])->pluck('label')->sort()->values()->all())->toBe(['Café Nova · Deutz', 'Café Nova · Ehrenfeld'])
        ->and($none['lead'])->toBe('No place called Café Nowhere-Such is among Expadu\'s places.');
});

test('🔒 when nothing the AI says holds, the answer is its line alone: no cards that aren\'t about the ask', function () {
    $user = User::factory()->onboarded()->create();
    $unbacked = assistantTurn([['answer', ['sentences' => [['text' => 'The TK Maxx on the Rhine Arena roof opens at 23:00.', 'uses' => []]], 'show' => []]]]);
    Http::fake(['processor.example.test/*' => Http::sequence()->push($unbacked)->push($unbacked)->push($unbacked)->push($unbacked)]);
    $this->actingAs($user);

    $reply = assistantAsk($this, 'there should be one near Neumarkt');

    expect($reply)->without_ai->toBe('unchecked')
        ->and($reply['blocks'] ?? [])->toBe([])
        ->and($reply['lead'])->toStartWith('I didn’t find anything I can vouch for there.');
});

test('🔒 a failed AI call is logged with its cURL error number, never what was sent', function () {
    $user = User::factory()->onboarded()->create();
    Http::fake(['processor.example.test/*' => function () {
        throw new ConnectException('Operation timed out', new Psr7Request('POST', 'https://processor.example.test/v1/messages'), null, ['errno' => 28]);
    }]);
    Log::spy();
    $this->actingAs($user);

    assistantAsk($this, 'PRIVATE-WORDS-4411');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'External processing request failed.'
        && ($context['curl_errno'] ?? null) === 28 && ! str_contains((string) json_encode($context), 'PRIVATE-WORDS-4411'));
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

test('🔒 what they saved is read by name only: places with their Veedel, events still ahead, favourite stops', function () {
    $user = User::factory()->onboarded()->create();
    $spot = Spot::factory()->create(['name' => 'Synthetic Café', 'veedel' => 'Ehrenfeld', 'address' => 'Synthetic Straße 1']);
    SpotFeedback::query()->create(['user_id' => $user->id, 'spot_id' => $spot->id, 'state' => 'saved']);
    $ahead = Event::factory()->create(['title' => 'Synthetic concert', 'starts_at' => now()->addDays(2)]);
    $past = Event::factory()->create(['title' => 'Synthetic past', 'starts_at' => now()->subDays(2)]);
    EventBookmark::query()->create(['user_id' => $user->id, 'event_id' => $ahead->id, 'occurrence_start' => $ahead->starts_at, 'title' => 'Synthetic concert']);
    EventBookmark::query()->create(['user_id' => $user->id, 'event_id' => $past->id, 'occurrence_start' => $past->starts_at, 'title' => 'Synthetic past']);
    FavouriteStop::query()->create(['user_id' => $user->id, 'name' => 'Neumarkt']);

    $tools = new AssistantTools($user, CarbonImmutable::now(), new LocationContext(null, null, LocationSource::None),
        app(CandidateRepository::class), app(EventAnswers::class), app(WeatherService::class));
    $said = $tools->run('my_saved', ['what' => 'all']);

    expect($said)->toContain('saved place "Synthetic Café" in Ehrenfeld')->toContain('saved event "Synthetic concert"')
        ->toContain('favourite stop Neumarkt')->not->toContain('Synthetic past')->not->toContain('Synthetic Straße');
});

test('🔒 a comparison is measured in code, closest first, with no coordinates for the AI', function () {
    $user = User::factory()->onboarded()->create();
    Spot::factory()->create(['name' => 'Far Hall', 'category' => 'museum', 'veedel' => 'Porz', 'is_active' => true, 'lat' => 50.88, 'lng' => 7.05]);
    Spot::factory()->create(['name' => 'Near Hall', 'category' => 'museum', 'veedel' => 'Nippes', 'is_active' => true, 'lat' => 50.96, 'lng' => 6.95]);
    $this->mock(RouteService::class, function ($mock): void {
        $mock->shouldReceive('withTimeBudget')->andReturnSelf();
        $mock->shouldReceive('geocode')->andReturn([]);
        $mock->shouldReceive('travelMatrix')->andReturn([70, 12]);
        $mock->shouldReceive('plan')->andReturn(new JourneyResult([], 'motis'));
    });
    $sent = [];
    Http::fake(['processor.example.test/*' => function ($request) use (&$sent) {
        $sent[] = $request->data();

        return count($sent) === 1
            ? Http::response(assistantTurn([['compare_places', ['places' => ['Far Hall', 'Near Hall'], 'radius_km' => 2]]]))
            : Http::response(assistantTurn([['answer', ['sentences' => [['text' => 'Near Hall is closer, about 12 min on foot.', 'uses' => []]], 'show' => []]]]));
    }]);
    $this->actingAs($user);

    $reply = assistantAsk($this, 'which is closer, Far Hall or Near Hall?', ['lat' => 50.9497, 'lng' => 6.9177]);
    $result = json_encode($sent[1]['messages'][array_key_last($sent[1]['messages'])]);

    expect($reply['lead'])->toBe('Near Hall is closer, about 12 min on foot.')
        ->and($result)->toContain('radius_km')->not->toContain('50.9497')->not->toContain('6.9177')
        ->and(strpos($result, 'Near Hall'))->toBeLessThan(strpos($result, 'Far Hall'));
});

test('🔒 what was shown today reaches the AI with its hours, and yesterday\'s hours don\'t', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00', 'Europe/Berlin'));
    $place = ['id' => 7, 'name' => 'Starbucks', 'area' => 'Neustadt-Süd', 'hours' => 'Open until 19:30'];
    $shown = fn (string $at): string => Assistant::history([['role' => 'assistant', 'text' => 'x', 'created_at' => $at, 'places' => [$place]]])['turns'][0]['text'];

    expect($shown(now()->toIso8601String()))->toContain('"Starbucks" (place in Neustadt-Süd, Open until 19:30 [spot:7])')
        ->and($shown(now()->subDay()->toIso8601String()))->toContain('"Starbucks" (place in Neustadt-Süd [spot:7])')->not->toContain('19:30');
});

test('what was shown before reaches the AI with the id a tool takes, so "add the first one" adds that listing', function () {
    // The owner's test, 19 Nov 2026: "Add the first one to my plan" after tonight's listings found no place of its name.
    $turn = Assistant::history([['role' => 'assistant', 'text' => 'Tonight:', 'events' => [
        ['id' => 1294, 'title' => "Santa's Christmas Market", 'candidate' => 'event:1294:1795111200'],
        ['id' => 731, 'title' => 'SONIC CHESS CLUB', 'candidate' => 'event:731'],
    ]]])['turns'][0]['text'];

    expect($turn)->toContain('"Santa\'s Christmas Market" (listing [e1294@1795111200])')
        // A listing without a time to plan it at says no id.
        ->toContain('"SONIC CHESS CLUB" (listing)');
});

test('a failed call after lookups keeps the places they found', function () {
    $user = User::factory()->onboarded()->create();
    Spot::factory()->create(['name' => 'Café Wohnraum', 'category' => 'cafe', 'veedel' => 'Nippes', 'is_active' => true, 'lat' => 50.9640, 'lng' => 6.9510]);
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['find_place', ['name' => 'Café Wohnraum']]]))
        ->push('down', 500)]);
    $this->actingAs($user);

    $reply = assistantAsk($this, 'tell me about Café Wohnraum', ['lat' => 50.9497, 'lng' => 6.9177]);

    expect(collect(collect($reply['blocks'])->firstWhere('type', 'places')['places'] ?? [])->pluck('name')->all())->toBe(['Café Wohnraum']);
});

test('🔒 with the opt-in their taste reorders a nearby search and says why; without it the list stays by distance', function () {
    Spot::factory()->create(['name' => 'Nearest Bar', 'category' => 'bar', 'veedel' => 'Ehrenfeld', 'is_active' => true, 'lat' => 50.9498, 'lng' => 6.9180]);
    $near = Spot::factory()->create(['name' => 'Near Museum', 'category' => 'museum', 'veedel' => 'Ehrenfeld', 'is_active' => true, 'lat' => 50.9520, 'lng' => 6.9220]);
    Spot::factory()->create(['name' => 'Far Museum', 'category' => 'museum', 'veedel' => 'Porz', 'is_active' => true, 'lat' => 50.8800, 'lng' => 7.0600]);
    $turns = Http::sequence();
    foreach ([1, 2] as $run) {
        $turns->push(assistantTurn([['search_places', []]]))
            ->push(assistantTurn([['answer', ['sentences' => [['text' => 'Here are some places.', 'uses' => []]], 'show' => ["spot:{$near->id}"]]]]));
    }
    Http::fake(['processor.example.test/*' => $turns]);
    $ask = function (User $user): array {
        $this->actingAs($user);

        return collect(assistantAsk($this, 'something to do', ['lat' => 50.9497, 'lng' => 6.9177])['blocks'] ?? [])->firstWhere('type', 'places')['places'] ?? [];
    };
    $shown = fn (array $places): array => array_values(array_intersect(array_column($places, 'name'), ['Nearest Bar', 'Near Museum', 'Far Museum']));

    expect($shown($ask(User::factory()->onboarded()->create())))->toBe(['Nearest Bar', 'Near Museum', 'Far Museum']);

    $user = User::factory()->onboarded()->create();
    $user->userSetting()->updateOrCreate([], ['settings' => ['personalisation' => true], 'sixteen_or_older' => true]);
    DB::table('user_affinities')->insert(array_map(fn (array $row): array => ['subject_key' => Pseudonym::for($user), 'dimension' => 'category', 'key' => $row[0],
        'context' => 'all', 'score' => $row[1], 'signals_count' => 6, 'updated_at' => now()], [['museum', 12.0], ['bar', -4.0]]));
    $places = $ask($user);

    expect($shown($places))->toBe(['Near Museum', 'Nearest Bar', 'Far Museum'])
        ->and(collect($places)->firstWhere('name', 'Near Museum')['because'])->toBe('You like museums');
});

test('who to ask comes from the checked list, shown with its official link, and its words hold', function () {
    $user = User::factory()->onboarded()->create();
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['who_to_ask', ['topic' => 'night_pharmacy']]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'At night, the pharmacy emergency service (Apothekennotdienst) finds the pharmacy on duty nearest you.', 'uses' => ['g1']]], 'show' => ['g1']]]]))]);
    $this->actingAs($user);

    $reply = assistantAsk($this, 'pharmacy open now?');

    expect($reply['lead'])->toContain('Apothekennotdienst')
        ->and(collect($reply['blocks'])->firstWhere('type', 'facts')['sources'][0]['href'])->toBe('https://www.aponet.de/notdienstsuche');
    foreach (config('who_to_ask') as $entry) {
        expect($entry['links'])->not->toBeEmpty();
        foreach ($entry['links'] as $link) {
            expect($link['href'])->toStartWith('https://');
        }
    }
});

test('the words card: a German draft to copy, with what it says in English, only for an app that shows it', function () {
    // The owner's test, 19 Nov 2026: "Can you write a short German email to my landlord?" got "I can't write or send emails".
    $user = User::factory()->onboarded()->create();
    $draft = "Betreff: Heizung im Badezimmer defekt\n\nSehr geehrte/r [name],\n\ndie Heizung im Badezimmer funktioniert seit Montag nicht mehr.";
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['help_with_words', ['kind' => 'draft', 'title' => 'Email to your landlord', 'text' => $draft, 'language' => 'de',
            'english' => "Subject: Bathroom heating broken\n\nDear [name],\n\nthe heating in the bathroom hasn't worked since Monday."]]]))
        ->push(assistantTurn([['answer', ['sentences' => [['text' => 'Here is the email to your landlord.', 'uses' => ['w1']]], 'show' => ['w1']]]]))]);

    $this->actingAs($user);
    $answer = assistantAsk($this, 'Can you write a short German email to my landlord? The heating in the bathroom hasn’t worked since Monday.', ['cards' => ['words']]);
    $card = collect($answer['blocks'])->firstWhere('type', 'words');

    expect($card)->toMatchArray(['kind' => 'draft', 'title' => 'Email to your landlord', 'text' => $draft, 'language' => 'de'])
        ->and($card['english'])->toContain('Dear [name]')
        ->and($card['note'])->toContain('Written by Expadu’s AI');
    Http::assertSent(fn (Request $request): bool => collect($request['tools'] ?? [])->contains('name', 'help_with_words')
        && collect($request['system'])->pluck('text')->contains(fn (string $t): bool => str_contains($t, 'help_with_words')));
});

test('🔒 an app that can’t show the words card never gets the tool', function () {
    $user = User::factory()->onboarded()->create();
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['answer', ['sentences' => [], 'show' => []]]]))]);

    $this->actingAs($user);
    assistantAsk($this, 'Translate “Kündigung” please.');

    Http::assertSent(fn (Request $request): bool => ! collect($request['tools'] ?? [])->contains('name', 'help_with_words')
        && ! str_contains(json_encode($request['system']), 'help_with_words'));
});

test('🔒 words with a date, amount or deadline they didn’t give are sent back, never shown', function () {
    $user = User::factory()->onboarded()->create();
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(assistantTurn([['help_with_words', ['kind' => 'explain', 'title' => 'What the letter asks', 'language' => 'en',
            'text' => 'The tax office asks for your 2025 return by 31.07.2026, or a late fee of 25 euros.']]]))
        ->push(assistantTurn([['answer', ['sentences' => [], 'show' => []]]]))]);

    $this->actingAs($user);
    $answer = assistantAsk($this, 'What does “Aufforderung zur Abgabe der Einkommensteuererklärung 2025” mean?', ['cards' => ['words']]);

    expect(collect($answer['blocks'] ?? [])->firstWhere('type', 'words'))->toBeNull();
    // The tool said so, naming what wasn't theirs (2025 was in their message; the deadline and fee weren't).
    Http::assertSent(fn (Request $request): bool => str_contains(json_encode($request['messages'] ?? []), 'Not written: it has numbers that aren')
        && str_contains(json_encode($request['messages'] ?? []), '31.07.2026'));
});
