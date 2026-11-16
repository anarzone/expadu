<?php

use App\Composer\Assistant\AssistantTools;
use App\Composer\Assistant\PlanDay;
use App\Composer\Candidate;
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
 * What the person asked for is kept to in code, not left to the model (track, week 1: the 30 Oct
 * blind grade's most common failure, "ignored what you asked", 83 of 483). The recorded failing
 * cases, replayed with a faked model: the tools' results now say what fits and what isn't known,
 * so an answer can't suggest a tour that began half an hour ago, call a restaurant free, or say
 * nothing of step-free access.
 */

beforeEach(function () {
    // Friday 30 October 2026, 13:30: the grade's own afternoon.
    $this->travelTo(CarbonImmutable::parse('2026-10-30 13:30', 'Europe/Berlin'));
    config()->set('services.llm', [
        'driver' => 'anthropic', 'base_url' => 'https://processor.example.test', 'model' => 'claude-haiku-5-5', 'key' => 'synthetic',
        'processor_name' => 'Synthetic processor', 'processor_privacy_url' => 'https://processor.example.test/privacy', 'prompt_version' => 'test.1',
    ]);
    app()->instance(WeatherService::class, new class extends WeatherService
    {
        public function __construct() {}

        public function getCurrentWeather(float $lat = 50.9375, float $lng = 6.9603): array
        {
            return ['available' => true, 'temperature' => 11, 'condition' => 'Rain'];
        }

        public function getForecast(float $lat = 50.9375, float $lng = 6.9603): array
        {
            return ['available' => true, 'rain_summary' => 'Rain until 18:00'];
        }
    });
});

/** A Messages reply holding these tool calls. */
function constraintTurn(array $uses): array
{
    return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use',
        'content' => array_map(fn (array $use, int $i): array => ['type' => 'tool_use', 'id' => "toolu_{$i}", 'name' => $use[0], 'input' => $use[1]], $uses, array_keys($uses)),
        'usage' => ['input_tokens' => 900, 'output_tokens' => 120]];
}

function constraintEvent(string $title, string $start, string $end, string $category = 'tours'): Event
{
    $event = Event::factory()->create(['title' => $title, 'title_en' => null, 'location_name' => 'Altstadt', 'category' => $category, 'is_curated' => true,
        'starts_at' => CarbonImmutable::parse("2026-10-30 {$start}", 'Europe/Berlin'), 'ends_at' => CarbonImmutable::parse("2026-10-30 {$end}", 'Europe/Berlin')]);
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [6.958, 50.941, $event->id]);

    return $event;
}

/** Asks as the page does and returns Composer's answer. */
function constraintAsk($test, string $text): array
{
    $revision = $test->getJson('/composer/workspace')->assertOk()->json('revision');
    $test->postJson('/composer/workspace', ['revision' => $revision, 'action' => 'message', 'text' => $text])->assertOk();
    $state = $test->postJson(route('composer.ask'), ['processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist)])->assertOk()->json();

    return collect(collect($state['conversations'])->firstWhere('id', $state['active'])['messages'])->last(fn (array $m): bool => ($m['request'] ?? null) === 'ask');
}

test('🔒 a tour that began half an hour ago is never suggested; a market under way says so, one about to start says how soon', function () {
    // couple.rainy_indoors at 13:30 was told "the brewery tour runs 13:00–15:00".
    constraintEvent('Brauereiführung Kreuzblume', '13:00', '15:00');
    $market = constraintEvent('Markt der Manufakturen', '10:00', '20:00', 'festivals');
    constraintEvent('Orgelkonzert am Mittag', '13:45', '14:30', 'music');
    $id = "e{$market->id}@".CarbonImmutable::parse('2026-10-30 10:00', 'Europe/Berlin')->getTimestamp();
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(constraintTurn([['search_events', ['day' => 'today']]]))
        ->push(constraintTurn([['answer', ['sentences' => [['text' => 'Markt der Manufakturen is under way until 20:00.', 'uses' => [$id]]], 'show' => [$id]]]]))]);
    $this->actingAs(User::factory()->onboarded()->create());

    $reply = constraintAsk($this, 'something indoors this afternoon?');

    $results = Http::recorded()[1][0]->body();
    expect($results)->not->toContain('Brauereif')
        ->toContain('under way since 10:00')
        ->toContain('starts in 15 min')
        ->and($reply['lead'])->toBe('Markt der Manufakturen is under way until 20:00.');
});

test('🔒 with their step-free need, events say access isn\'t listed, and an answer saying so is kept', function () {
    // wheelchair.events_tonight: three searches, no word on access.
    $concert = constraintEvent('Orgelkonzert am Mittag', '13:45', '14:30', 'music');
    $id = "e{$concert->id}@".CarbonImmutable::parse('2026-10-30 13:45', 'Europe/Berlin')->getTimestamp();
    $calls = 0;
    Http::fake(['processor.example.test/*' => function (Request $request) use (&$calls, $id) {
        if (++$calls === 1) {
            return Http::response(constraintTurn([['search_events', ['day' => 'today']]]));
        }
        // The answer cites the line its result gave, under whatever id it has.
        preg_match('/\[(n\d+)\] Step-free access isn/', $request->body(), $m);

        return Http::response(constraintTurn([['answer', ['sentences' => [
            ['text' => 'Orgelkonzert am Mittag starts at 13:45.', 'uses' => [$id]],
            ['text' => 'Step-free access isn\'t listed for these events\' venues.', 'uses' => [$m[1] ?? 'n0']],
        ], 'show' => [$id]]]]));
    }]);
    $this->actingAs(User::factory()->onboarded()->create(['needs_step_free' => true]));

    $reply = constraintAsk($this, 'any concerts today?');

    expect(Http::recorded()[1][0]->body())->toContain('Step-free access isn\'t listed for these events\' venues.')
        ->and($reply['lead'])->toBe('Orgelkonzert am Mittag starts at 13:45. Step-free access isn\'t listed for these events\' venues.');
});

test('a plan says in code what it couldn\'t keep to: step-free stops, prices not listed, a kind it has none of, picks widened', function () {
    // couple.cheap_food: restaurant then park, cheap; the answer had no words, the plan one viewpoint 53 min away.
    $plan = ['constraints' => ['needs' => ['step_free'], 'budget' => 'low', 'sequence' => ['restaurant', 'park'], 'categories' => ['restaurant', 'park']],
        'slots' => [
            ['start_at' => '2026-10-30T14:00:00+01:00', 'end_time' => '15:00', 'name' => 'Stadtgarten', 'category' => 'park', 'veedel' => 'Neustadt-Nord', 'cost_tier' => 'unknown', 'is_appointment' => false],
        ]];

    $text = PlanDay::text($plan, ['✨ Few exact matches here — widened the picks so you still have a plan']);

    expect($text)->toContain('"Stadtgarten" (Park) in Neustadt-Nord. Step-free access listed. Price not listed')
        ->toContain('No restaurant fits that time and place, so the plan has none.')
        ->toContain('the planner widened its picks')
        // A plan that kept to everything says nothing more.
        ->and(PlanDay::text(['constraints' => ['sequence' => ['restaurant', 'park']], 'slots' => [
            ['start_at' => '2026-10-30T14:00:00+01:00', 'end_time' => null, 'name' => 'Ox & Klee', 'category' => 'restaurant', 'veedel' => null, 'cost_tier' => 'unknown', 'is_appointment' => false],
            ['start_at' => '2026-10-30T15:00:00+01:00', 'end_time' => null, 'name' => 'Stadtgarten', 'category' => 'park', 'veedel' => null, 'cost_tier' => 'free', 'is_appointment' => false],
        ]]))->not->toContain('fits that time')->not->toContain('Step-free')->not->toContain('Price not listed')->not->toContain('widened');
});

test('🔒 a restaurant or café is free only when its data says so; an open-air place without a fee listed still is', function () {
    // couple.free_only: search_places {free: true} listed paid restaurants.
    $place = fn (string $category, array $use): Candidate => new Candidate(id: 'spot:9', type: 'spot', name: 'A place', lat: 50.94, lng: 6.95, veedel: 'Altstadt-Nord',
        category: $category, outdoor: $category === 'park', typicalDurationMin: 60, costTier: 'unknown', opensAt: null, closesAt: null, placeFacts: ['public_use' => $use]);
    $unknown = ['value' => 'unknown', 'free' => 'unknown', 'basis' => null, 'source_url' => null];

    expect(AssistantTools::freeToUse($place('restaurant', $unknown)))->toBeFalse()
        ->and(AssistantTools::freeToUse($place('cafe', $unknown)))->toBeFalse()
        ->and(AssistantTools::freeToUse($place('museum', ['value' => 'yes', 'free' => 'yes', 'basis' => 'no_fee', 'source_url' => null])))->toBeTrue()
        ->and(AssistantTools::freeToUse($place('park', $unknown)))->toBeTrue()
        ->and(AssistantTools::freeToUse($place('park', ['value' => 'yes', 'free' => 'no', 'basis' => 'fee', 'source_url' => null])))->toBeFalse();
});
