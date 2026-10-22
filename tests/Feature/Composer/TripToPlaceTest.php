<?php

use App\Composer\Answers\RequestRouter;
use App\Composer\Answers\RouterContext;
use App\Composer\HeuristicPromptParser;
use App\Composer\PromptIntent;
use App\Models\Spot;
use App\Models\User;
use App\Profile\ProfileEngine;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\JourneyResult;
use App\Transit\Dto\Leg;
use App\Transit\Dto\Place;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

/*
 * "show me departures to go Cafe Nova in City Center" (owner's staging test, 22 Oct 2026)
 * got the page's "Choose a place below": Composer read "cafe" as a nearby search and had
 * no trip to a named place. Now it finds the place and plans the next way there.
 */

test('a trip to a named place is read with its area and time; an outing stays an outing', function (string $text, ?array $trip) {
    $routed = app(RequestRouter::class)->route($text, new RouterContext(today: '2026-10-22', date: '2026-10-22'));

    expect($trip === null ? $routed['kind'] !== 'journey' || (! isset($routed['place']) && ! ($routed['again'] ?? false))
        : $routed['kind'] === 'journey' && array_intersect_key($routed, $trip) === $trip)->toBeTrue();
})->with([
    ['show me departures to go Cafe Nova in City Center', ['place' => 'Cafe Nova', 'area' => 'City Center', 'at' => null]],
    ['take me to cafe nova', ['place' => 'cafe nova', 'area' => null]],
    ['How do I get to the Dom?', ['place' => 'Dom', 'area' => null]],
    ['I want to go to Cafe Nova at 18:30', ['place' => 'Cafe Nova', 'at' => '18:30']],
    ['I want to go to the park', null],
    ['take me to Cafe Nova tomorrow', null],
    ['how can i go to the cafe nova?', ['place' => 'cafe nova', 'area' => null]],
    ['hw cna i get to cafe nova', ['place' => 'cafe nova']],
    ['whats the way to cafe nova', ['place' => 'cafe nova']],
    ['directions to neumarkt please', ['place' => 'neumarkt']],
    ['how far is it to the zoo?', ['place' => 'zoo']],
    ['how do I reach Kölner Dom', ['place' => 'Kölner Dom']],
    ['how do i get there?', ['again' => true]],
    ['how far is it?', ['again' => true, 'ask' => 'distance']],
    ['how many minutes to cafe nova', ['place' => 'cafe nova', 'ask' => 'distance']],
    ['what is the best way to spend the afternoon', null],
    ['I am on my way to work', null],
]);

test('the keyword reader knows a trip as the router does, to the place named', function () {
    $parsed = app(HeuristicPromptParser::class)->parse('how can i go to the cafe nova?', app(ProfileEngine::class)->build(User::factory()->onboarded()->create()),
        CarbonImmutable::parse('2026-10-22 13:50', 'Europe/Berlin'));

    expect($parsed->intent)->toBe(PromptIntent::TakeMeThere)->and($parsed->query)->toBe('cafe nova');
});

test('Composer plans the trip to the place named, in the area named, from where you are', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:40', 'Europe/Berlin'));
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

    $this->postJson('/composer/workspace', ['revision' => 0, 'action' => 'message', 'text' => 'show me departures to go Cafe Nova in City Center'])->assertOk();
    $reply = collect($this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'answer'])->assertOk()
        ->json('conversations.0.messages'))->last();

    expect($routes->to?->lng)->toBe(6.9530)
        ->and($reply)->kind->toBe('trip')->request->toBe('journey')
        ->title->toBe('Tram 5 at 12:49 from Venloer Str./Gürtel.')
        ->text->toBe('Leave at 12:44 to be at Cafe Nova in Altstadt-Nord by 13:07. Live times have it on time.')
        ->and($reply['trip'])->link->toBe('plan')->basis->toBe('live')
        ->and($reply['trip']['for']['place'])->toBe('Cafe Nova');

    // A follow-up about the same place plans the trip there again.
    $this->postJson('/composer/workspace', ['revision' => 2, 'action' => 'message', 'text' => 'how can i go there?'])->assertOk();
    $routes->to = null;
    $again = collect($this->postJson('/composer/workspace', ['revision' => 3, 'action' => 'answer'])->assertOk()
        ->json('conversations.0.messages'))->last();
    Redis::del("confirmed_location:{$user->id}");

    expect($routes->to?->lng)->toBe(6.9530)->and($again)->kind->toBe('trip')
        ->and($again['destination'])->toBe(['name' => 'Cafe Nova', 'area' => 'Altstadt-Nord']);

    // "How far is it?": the distance and the time in one line, the way folded under it.
    Redis::set("confirmed_location:{$user->id}", json_encode(['lat' => 50.9497, 'lng' => 6.9177, 'name' => 'Home', 'at' => now()->timestamp]));
    $this->postJson('/composer/workspace', ['revision' => 4, 'action' => 'message', 'text' => 'how far is it?'])->assertOk();
    $far = collect($this->postJson('/composer/workspace', ['revision' => 5, 'action' => 'answer'])->assertOk()
        ->json('conversations.0.messages'))->last();
    Redis::del("confirmed_location:{$user->id}");

    expect($far)->kind->toBe('trip')->text->toBe('About 2.8 km away in a straight line, 23 min door to door; leave at 12:44.')
        ->and($far)->not->toHaveKey('title')
        ->and($far['trip']['folded'])->toBeTrue();
});
