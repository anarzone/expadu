<?php

use App\Composer\Answers\AroundCheck;
use App\Composer\Answers\RequestRouter;
use App\Composer\Answers\RouterContext;
use App\Composer\WorkspaceUpgrade;
use App\Models\ComposerWorkspace;
use App\Models\Event;
use App\Models\User;
use App\Privacy\ProcessingPurpose;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExternalProcessingFixtures;

/*
 * "What's up?" (owner, 22 Oct 2026): what's around you now, from real data only, and
 * never a dead end. The AI may write the lead from what's on and the weather; the
 * person's own day never reaches it, and a sentence its listings don't back is dropped.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 19:05', 'Europe/Berlin'));
    app()->instance(WeatherService::class, new class extends WeatherService
    {
        public function __construct() {}

        public function getCurrentWeather(float $lat = 50.9375, float $lng = 6.9603): array
        {
            return ['available' => true, 'temperature' => 9, 'condition' => 'Overcast', 'precipitation' => 0, 'icon' => 'cloudy'];
        }

        public function getForecast(float $lat = 50.9375, float $lng = 6.9603): array
        {
            return ['available' => true, 'rain_soon' => true, 'rain_summary' => 'Rain from 22:00 (2h+)'];
        }
    });
});

function aroundEvent(string $title, string $venue, string $start, string $end): Event
{
    $event = Event::factory()->create(['title' => $title, 'title_en' => null, 'location_name' => $venue, 'category' => 'music', 'is_curated' => true,
        'starts_at' => CarbonImmutable::parse($start, 'Europe/Berlin'), 'ends_at' => CarbonImmutable::parse($end, 'Europe/Berlin')]);
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [6.93, 50.94, $event->id]);

    return $event;
}

/** The person's own plan for tonight, as the workspace keeps it. */
function aroundPlan(User $user, string $name): void
{
    ComposerWorkspace::query()->create(['user_id' => $user->id, 'revision' => 0, 'state' => [
        'schema' => WorkspaceUpgrade::SCHEMA, 'active' => 'initial', 'focus' => null, 'changes' => [],
        'conversations' => [['id' => 'initial', 'title' => 'New conversation', 'created_at' => now()->toIso8601String(), 'messages' => []]],
        'plans' => ['2026-11-05' => ['date' => '2026-11-05', 'slots' => [['id' => 'custom:private', 'name' => $name, 'category' => 'custom',
            'start_at' => '2026-11-05T21:30:00+01:00', 'end_at' => '2026-11-05T22:30:00+01:00']]]],
    ]]);
}

/** Asks as the page does: the message, then the answer to it. */
function aroundAsk($test, string $text): array
{
    $revision = $test->getJson('/composer/workspace')->json('revision');
    $test->postJson('/composer/workspace', ['revision' => $revision, 'action' => 'message', 'text' => $text])->assertOk();

    return collect($test->postJson('/composer/workspace', ['revision' => $revision + 1, 'action' => 'answer'])->assertOk()
        ->json('conversations.0.messages'))->last();
}

test('🔒 "What’s up?" is what’s around you now, with something to do next', function () {
    $user = User::factory()->onboarded()->create();
    aroundEvent('Jazz im Stadtgarten', 'Stadtgarten', '2026-11-05 20:00', '2026-11-05 23:00');
    aroundPlan($user, 'Late swim');
    $this->actingAs($user);

    $reply = aroundAsk($this, 'What’s up?');

    expect($reply)->kind->toBe('around')->request->toBe('around')->written->toBe('listings')
        ->text->toBe('Here’s what’s still on in Cologne tonight.')
        ->and(collect($reply['events'])->pluck('title')->all())->toBe(['Jazz im Stadtgarten'])
        ->and(collect($reply['facts'])->map(fn (array $fact): string => "{$fact['label']}: {$fact['value']}")->all())
        ->toBe(['Weather: 9° · Overcast · Rain from 22:00 (2h+)', 'Today 21:30: Late swim'])
        ->and(collect($reply['suggestions'])->pluck('label')->all())->toContain('What’s on tonight?', 'Something indoors');
});

test('🔒 what Composer can’t answer says so and offers what’s around, never “isn’t something Composer knows”', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    $reply = aroundAsk($this, 'tell me a joke');

    expect($reply)->kind->toBe('around')->request->toBe('clarify')
        ->text->toBe('I’m not sure I can help with that yet. Nothing more is listed in Cologne tonight, but I can plan something for you.')
        ->and($reply['suggestions'])->not->toBeEmpty()
        ->and($reply['text'])->not->toContain('something Composer knows');
});

test('🔒 the AI writes the lead from what’s on and the weather only; a sentence they don’t back is dropped', function () {
    config()->set('services.llm', [
        'driver' => 'anthropic', 'base_url' => 'https://processor.example.test', 'model' => 'claude-haiku-5-5', 'key' => 'synthetic',
        'processor_name' => 'Synthetic processor', 'processor_privacy_url' => 'https://processor.example.test/privacy', 'prompt_version' => 'test.1',
    ]);
    Http::fake(['processor.example.test/*' => Http::response([
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use',
        'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'route_prompt', 'input' => ['intent' => 'unknown', 'reply' => [
            ['text' => 'Jazz im Stadtgarten starts at 20:00 at Stadtgarten.', 'uses' => ['e1']],
            ['text' => 'There’s also a boat party on the Rhine at 21:00.', 'uses' => ['e1']],
            ['text' => 'Rain is due from 22:00, so bring a jacket.', 'uses' => ['weather']],
        ]]]],
        'usage' => ['input_tokens' => 300, 'output_tokens' => 80],
    ])]);
    $user = User::factory()->onboarded()->create();
    aroundEvent('Jazz im Stadtgarten', 'Stadtgarten', '2026-11-05 20:00', '2026-11-05 23:00');
    aroundPlan($user, 'PRIVATE-PLAN-7781');
    $this->actingAs($user);

    $this->postJson('/composer/workspace', ['revision' => 0, 'action' => 'message', 'text' => 'whats up'])->assertOk();
    $this->postJson(route('composer.parse'), ['text' => 'whats up', 'processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerParse)])
        ->assertOk()->assertJsonPath('source', 'llm')->assertJsonPath('kind', 'around')->assertJsonPath('answer', true)
        ->assertJsonMissingPath('reply');
    $reply = collect($this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'answer'])->assertOk()
        ->json('conversations.0.messages'))->last();

    // Sent: the listing and the weather; never the person's own plan.
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Jazz im Stadtgarten')
        && str_contains($request->body(), 'Rain from 22:00') && ! str_contains($request->body(), 'PRIVATE-PLAN-7781'));
    expect($reply)->written->toBe('ai')
        ->text->toBe('Jazz im Stadtgarten starts at 20:00 at Stadtgarten. Rain is due from 22:00, so bring a jacket.')
        ->and(collect($reply['facts'])->pluck('value')->all())->toContain('PRIVATE-PLAN-7781');
});

test('🔒 a sentence keeps only the names, numbers and claims its items hold', function (string $sentence, bool $holds) {
    $source = "Thursday 5 November, 19:05, Cologne\n\"Jazz im Stadtgarten\" (music) at Stadtgarten, Neustadt-Nord. today 20:00–23:00.\nCologne now: 9°, Overcast, Rain from 22:00 (2h+)";

    expect(app(AroundCheck::class)->holds($sentence, $source))->toBe($holds);
})->with([
    'grounded' => ['Jazz im Stadtgarten starts at 20:00 in Neustadt-Nord.', true],
    'weather' => ['It’s 9° and overcast, with rain from 22:00.', true],
    'what it can do' => ['I can’t book tables, but I can plan your evening.', true],
    'an invented place' => ['Or try the Rhine promenade.', false],
    'an invented time' => ['Jazz im Stadtgarten starts at 19:30.', false],
    'an invented price' => ['Jazz im Stadtgarten is free.', false],
    'invented weather' => ['It’s sunny out.', false],
    'a count' => ['There are three concerts tonight.', false],
    'a hedge' => ['Jazz im Stadtgarten is probably busy.', false],
    'the person’s own day' => ['You have a plan at 20:00.', false],
    'a guess at the person’s day' => ['Your swim is later, so go early.', false],
    'their evening' => ['Make the most of your evening.', true],
    'what it can plan' => ['Sorry, I can’t tell jokes. It’s 9° though, and I can plan your day.', true],
    'a name after a full stop' => ['It’s 9°. Rhine Week starts today.', false],
    'a link' => ['See https://example.com for more.', false],
]);

test('casual and open messages are about now; about another day, they’re that day’s plan', function (string $text, string $kind) {
    $context = new RouterContext(today: '2026-11-05', date: '2026-11-05');

    expect(app(RequestRouter::class)->route($text, $context)['kind'])->toBe($kind);
})->with([
    ['What’s up?', 'around'], ['whats up', 'around'], ['wassup', 'around'], ['hi', 'around'], ['Hello!', 'around'],
    ['I’m bored', 'around'], ['anything fun?', 'around'], ['what should I do', 'around'], ['Was ist los?', 'around'],
    ['I’m bored, anything fun tomorrow?', 'proposal'], ['what can you do?', 'help'], ['hi, how do I get to the Dom?', 'journey'],
    ['What’s on tonight?', 'event'], ['asdfgh', 'clarify'],
]);
