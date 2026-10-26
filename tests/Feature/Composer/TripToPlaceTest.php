<?php

use App\Models\Spot;
use App\Models\User;
use App\Privacy\ProcessingPurpose;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\JourneyResult;
use App\Transit\Dto\Leg;
use App\Transit\Dto\Place;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Support\ExternalProcessingFixtures;

/*
 * "show me departures to go Cafe Nova in City Center" (owner's staging test, 22 Oct 2026)
 * got the page's "Choose a place below". Now the assistant's plan_trip finds the place and
 * plans the next way there, from where the person is; "how far is it?" follows it up.
 */

/** The AI's turns for one message: plan_trip with this input, then an answer showing the trip. */
function tripTurns(array $input): array
{
    $turn = fn (string $name, array $in): array => ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use',
        'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => $name, 'input' => $in]], 'usage' => ['input_tokens' => 300, 'output_tokens' => 40]];

    return [$turn('plan_trip', $input), $turn('answer', ['sentences' => [], 'show' => ['r1']])];
}

/** The message, then Composer's answer to it, as the page sends them. */
function tripAsk($test, string $text, array $input): array
{
    foreach (tripTurns($input) as $turn) {
        app('trip.turns')->enqueue($turn);
    }
    $revision = $test->getJson('/composer/workspace')->json('revision');
    $test->postJson('/composer/workspace', ['revision' => $revision, 'action' => 'message', 'text' => $text])->assertOk();

    return collect($test->postJson(route('composer.ask'), ['processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist)])
        ->assertOk()->json('conversations.0.messages'))->last();
}

test('Composer plans the trip to the place named, in the area named, from where you are', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:40', 'Europe/Berlin'));
    config()->set('services.llm', [
        'driver' => 'anthropic', 'base_url' => 'https://processor.example.test', 'model' => 'claude-haiku-5-5', 'key' => 'synthetic',
        'processor_name' => 'Synthetic processor', 'processor_privacy_url' => 'https://processor.example.test/privacy', 'prompt_version' => 'test.1',
    ]);
    // The AI's turns, in the order the messages are asked.
    app()->instance('trip.turns', $turns = new SplQueue);
    Http::fake(['processor.example.test/*' => fn () => Http::response($turns->dequeue())]);
    Spot::factory()->create(['name' => 'Cafe Nova', 'category' => 'cafe', 'veedel' => 'Altstadt-Nord', 'lat' => 50.9385, 'lng' => 6.9530, 'is_active' => true]);
    Spot::factory()->create(['name' => 'Café Nova', 'category' => 'cafe', 'veedel' => 'Deutz', 'lat' => 50.9370, 'lng' => 6.9790, 'is_active' => true]);
    $routes = new class implements RouteService
    {
        public ?GeoPoint $to = null;

        public function withTimeBudget(float $seconds): static
        {
            return $this;
        }

        public function plan(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt = null, int $max = 6, bool $arriveBy = false, bool $variety = false): JourneyResult
        {
            $this->to = $to;
            $at = fn (string $time): CarbonImmutable => CarbonImmutable::parse("2026-10-22 {$time}", 'Europe/Berlin');
            $stop = fn (string $name, float $lat, float $lng): Place => new Place($name, new GeoPoint($lat, $lng));

            return new JourneyResult([new Journey([
                new Leg('walk', $stop('Start', 50.9497, 6.9177), $stop('Köln Venloer Str./Gürtel', 50.9490, 6.9190), $at('12:44'), $at('12:48'), 4),
                new Leg('tram', $stop('Köln Venloer Str./Gürtel', 50.9490, 6.9190), $stop('Köln Dom/Hbf', 50.9430, 6.9580), $at('12:49'), $at('13:01'), 12, '5', realtime: true, delayMin: 0),
                new Leg('walk', $stop('Köln Dom/Hbf', 50.9430, 6.9580), $stop('Cafe Nova', 50.9385, 6.9530), $at('13:01'), $at('13:07'), 6),
            ], $at('12:44'), $at('13:07'), 23, 0)], 'motis');
        }

        public function geocode(string $query, ?GeoPoint $bias = null): array
        {
            return [];
        }

        public function reverseGeocode(GeoPoint $point): ?Place
        {
            return null;
        }

        public function travelMatrix(GeoPoint $origin, array $destinations, string $mode = 'BIKE'): array
        {
            return [];
        }
    };
    app()->instance(RouteService::class, $routes);
    $user = User::factory()->onboarded()->create();
    Redis::set("confirmed_location:{$user->id}", json_encode(['lat' => 50.9497, 'lng' => 6.9177, 'name' => 'Home', 'at' => now()->timestamp]));
    $this->actingAs($user);

    $reply = tripAsk($this, 'show me departures to go Cafe Nova in City Center', ['to' => 'Cafe Nova', 'area' => 'City Center']);

    expect($routes->to?->lng)->toBe(6.9530)
        ->and($reply)->kind->toBe('trip')->request->toBe('ask')
        ->title->toBe('Tram 5 at 12:49 from Venloer Str./Gürtel.')
        ->text->toBe('Leave at 12:44 to be at Cafe Nova in Altstadt-Nord by 13:07. Live times have it on time.')
        ->and($reply['trip'])->link->toBe('plan')->basis->toBe('live')
        ->and($reply['trip']['for']['place'])->toBe('Cafe Nova');

    // A follow-up about the same place: of the two Cafe Novas, the one this conversation went to.
    $routes->to = null;
    $again = tripAsk($this, 'how can i go there?', ['to' => 'Cafe Nova']);
    Redis::del("confirmed_location:{$user->id}");

    expect($routes->to?->lng)->toBe(6.9530)->and($again)->kind->toBe('trip')
        ->and($again['destination'])->toBe(['name' => 'Cafe Nova', 'area' => 'Altstadt-Nord']);

    // "How far is it?": the distance and the time in one line, the way folded under it.
    Redis::set("confirmed_location:{$user->id}", json_encode(['lat' => 50.9497, 'lng' => 6.9177, 'name' => 'Home', 'at' => now()->timestamp]));
    $far = tripAsk($this, 'how far is it?', ['to' => 'Cafe Nova', 'area' => 'Altstadt-Nord', 'how_far' => true]);
    Redis::del("confirmed_location:{$user->id}");

    expect($far)->kind->toBe('trip')->text->toBe('About 2.8 km away in a straight line, 23 min door to door; leave at 12:44.')
        ->and($far)->not->toHaveKey('title')->and($far)->not->toHaveKey('lead')
        ->and($far['trip']['folded'])->toBeTrue();
});
