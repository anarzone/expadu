<?php

use App\Models\ActiveTrip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/* Trip sync on the iPhone: the tram a rider is really on, switching the trip to it, and Undo. */

beforeEach(function () {
    Cache::flush();
    config(['services.motis.url' => 'http://motis.test', 'services.transitous.url' => null]);
    $this->travelTo(CarbonImmutable::parse('2026-11-16T17:45:00+01:00'));
    $this->at = fn (string $hm) => CarbonImmutable::parse("2026-11-16T{$hm}:00+01:00")->toIso8601String();
    $place = fn (string $name, float $lat, float $lng, ?string $id = null) => ['name' => $name, 'lat' => $lat, 'lng' => $lng, 'stop_id' => $id];
    $this->ebertplatz = $place('Köln Ebertplatz', 50.9497, 6.9573, 'vrs-gtfs_35');
    $this->appellhofplatz = $place('Köln Appellhofplatz', 50.9395, 6.9531, 'vrs-gtfs_7');
    $leg = fn (string $mode, ?string $line, array $from, array $to, string $dep, string $arr, ?string $trip = null) => [
        'mode' => $mode, 'line' => $line, 'from' => $from, 'to' => $to,
        'depart_at' => ($this->at)($dep), 'arrive_at' => ($this->at)($arr), 'depart_time' => $dep, 'arrive_time' => $arr,
        'trip_id' => $trip, 'polyline' => null, 'intermediate_stops' => [],
    ];
    $home = $place('', 51.0227, 6.9491);
    $this->journey = [
        'depart_at' => ($this->at)('17:25'), 'arrive_at' => ($this->at)('17:46'), 'depart_time' => '17:25', 'arrive_time' => '17:46',
        'duration_min' => 21, 'transfers' => 0, 'mode' => 'transit', 'lines' => ['16'],
        'legs' => [
            $leg('walk', null, $home, $this->ebertplatz, '17:25', '17:36'),
            $leg('tram', '16', $this->ebertplatz, $this->appellhofplatz, '17:36', '17:42', 'run-16-1736'),
            $leg('walk', null, $this->appellhofplatz, $place('', 50.9390, 6.9490), '17:42', '17:46'),
        ],
    ];
    $this->person = User::factory()->onboarded()->create();
    $this->person->activeTrip()->create([
        'journey' => $this->journey, 'destination' => ['name' => 'Cafe Nova', 'lat' => 50.9390, 'lng' => 6.9490], 'started_at' => now(),
    ]);
    $this->token = $this->person->createToken('iPhone')->plainTextToken;
});

/** MOTIS answering the stretch's runs, and a way on from Ebertplatz on each. */
function fakeRuns(array $runs): void
{
    $itinerary = fn (string $line, string $dep, string $arr, string $trip) => [
        'startTime' => $dep, 'endTime' => CarbonImmutable::parse($arr)->addMinutes(4)->toIso8601ZuluString(), 'duration' => 600, 'transfers' => 0,
        'legs' => [
            ['mode' => 'TRAM', 'routeShortName' => $line, 'tripId' => $trip, 'startTime' => $dep, 'endTime' => $arr, 'duration' => 360,
                'from' => ['name' => 'Köln Ebertplatz', 'stopId' => 'vrs-gtfs_35', 'lat' => 50.9497, 'lon' => 6.9573],
                'to' => ['name' => 'Köln Appellhofplatz', 'stopId' => 'vrs-gtfs_7', 'lat' => 50.9395, 'lon' => 6.9531]],
            ['mode' => 'WALK', 'startTime' => $arr, 'endTime' => CarbonImmutable::parse($arr)->addMinutes(4)->toIso8601ZuluString(), 'duration' => 240,
                'from' => ['name' => 'Köln Appellhofplatz', 'lat' => 50.9395, 'lon' => 6.9531], 'to' => ['name' => 'END', 'lat' => 50.9390, 'lon' => 6.9490]],
        ],
    ];
    $all = array_map(fn (array $r) => $itinerary(...$r), $runs);
    // A fresh fake each time: a second one would only queue behind the first.
    Http::swap(new Factory);
    Http::fake(['motis.test/*' => Http::response(['itineraries' => $all])]);
}

test('🔒 trip sync needs a token and works only on your own running trip', function () {
    $this->postJson('/api/v1/travel/trip/ride/check', ['leg' => 1, 'left_at' => ($this->at)('17:36')])->assertUnauthorized();
    $this->postJson('/api/v1/travel/trip/ride', [])->assertUnauthorized();
    $this->postJson('/api/v1/travel/trip/ride/undo', [])->assertUnauthorized();

    $someone = User::factory()->onboarded()->create()->createToken('iPhone')->plainTextToken;
    $this->withToken($someone)->postJson('/api/v1/travel/trip/ride/check', ['leg' => 1, 'left_at' => ($this->at)('17:36')])->assertNotFound();
    $this->withToken($someone)->postJson('/api/v1/travel/trip/ride', ['leg' => 1, 'line' => '18', 'depart_at' => ($this->at)('17:32')])->assertNotFound();
});

test('the planned tram, a minute late, is no change', function () {
    fakeRuns([['16', '2026-11-16T16:37:00Z', '2026-11-16T16:43:00Z', 'run-16-1736'], ['18', '2026-11-16T16:42:00Z', '2026-11-16T16:48:00Z', 'run-18-1742']]);

    $this->withToken($this->token)->postJson('/api/v1/travel/trip/ride/check', ['leg' => 1, 'left_at' => ($this->at)('17:37')])
        ->assertOk()->assertJson(['planned' => true, 'sure' => null]);
});

test('the ride is found by its stop and time, as the phone names them, whatever its index', function () {
    fakeRuns([['16', '2026-11-16T16:37:00Z', '2026-11-16T16:43:00Z', 'run-16-1736']]);

    // Index 0 is the walk; "Ebertplatz" without the city, at the planned 17:36: the 16, as planned.
    $this->withToken($this->token)->postJson('/api/v1/travel/trip/ride/check', ['leg' => 0, 'from' => 'Ebertplatz', 'at' => ($this->at)('17:36'), 'left_at' => ($this->at)('17:37')])
        ->assertOk()->assertJson(['planned' => true]);
    // A ride not on this trip: said so.
    $this->withToken($this->token)->postJson('/api/v1/travel/trip/ride/check', ['leg' => 0, 'from' => 'Neumarkt', 'at' => ($this->at)('17:36'), 'left_at' => ($this->at)('17:37')])
        ->assertUnprocessable();
});

test('another line leaving alone is sure; two a minute apart are a choice', function () {
    fakeRuns([['16', '2026-11-16T16:36:00Z', '2026-11-16T16:42:00Z', 'run-16-1736'], ['18', '2026-11-16T16:42:00Z', '2026-11-16T16:48:00Z', 'run-18-1742']]);
    $this->withToken($this->token)->postJson('/api/v1/travel/trip/ride/check', ['leg' => 1, 'left_at' => ($this->at)('17:42')])
        ->assertOk()->assertJson(['planned' => false, 'sure' => ['line' => '18', 'depart_time' => '17:42']]);

    Cache::flush();
    fakeRuns([['16', '2026-11-16T16:36:00Z', '2026-11-16T16:42:00Z', 'run-16-1736'], ['18', '2026-11-16T16:37:00Z', '2026-11-16T16:43:00Z', 'run-18-1737']]);
    $choice = $this->withToken($this->token)->postJson('/api/v1/travel/trip/ride/check', ['leg' => 1, 'left_at' => ($this->at)('17:37')])->assertOk();
    expect($choice->json('sure'))->toBeNull()
        ->and(collect($choice->json('runs'))->pluck('line')->all())->toBe(['18', '16']);
});

test('switching rides the rest from that tram, moves the walk before it, and Undo puts it back once', function () {
    fakeRuns([['18', '2026-11-16T16:42:00Z', '2026-11-16T16:48:00Z', 'run-18-1742']]);

    $switched = $this->withToken($this->token)
        ->postJson('/api/v1/travel/trip/ride', ['leg' => 1, 'line' => '18', 'depart_at' => ($this->at)('17:42'), 'trip' => 'run-18-1742'])
        ->assertOk()->assertJson(['line' => '18', 'depart_time' => '17:42', 'arrive_time' => '17:52']);

    $journey = ActiveTrip::query()->where('user_id', $this->person->id)->value('journey');
    expect(collect($journey['legs'])->map(fn ($l) => [$l['mode'], $l['line'], $l['depart_time'], $l['arrive_time']])->all())->toBe([
        ['walk', null, '17:31', '17:42'],
        ['tram', '18', '17:42', '17:48'],
        ['walk', null, '17:48', '17:52'],
    ])->and($journey['depart_time'])->toBe('17:31')->and($journey['arrive_time'])->toBe('17:52')->and($journey['lines'])->toBe(['18']);

    // Someone else's Undo never touches it (signed in as them: the guard forgets the first token).
    app('auth')->forgetGuards();
    $someone = User::factory()->onboarded()->create();
    $someone->activeTrip()->create(['journey' => $this->journey, 'destination' => ['name' => 'X', 'lat' => 50.9, 'lng' => 6.9], 'started_at' => now()]);
    $this->withToken($someone->createToken('iPhone')->plainTextToken)
        ->postJson('/api/v1/travel/trip/ride/undo', ['undo' => $switched->json('undo')])->assertUnprocessable();

    app('auth')->forgetGuards();
    $this->withToken($this->token)->postJson('/api/v1/travel/trip/ride/undo', ['undo' => $switched->json('undo')])->assertOk();
    expect(ActiveTrip::query()->where('user_id', $this->person->id)->value('journey')['legs'][1]['line'])->toBe('16');
    $this->withToken($this->token)->postJson('/api/v1/travel/trip/ride/undo', ['undo' => $switched->json('undo')])->assertUnprocessable();
});

test('a change a delay broke takes the next tram there, the earliest to arrive', function () {
    fakeRuns([['16', '2026-11-16T16:36:00Z', '2026-11-16T16:42:00Z', 'run-16-1736'], ['18', '2026-11-16T16:42:00Z', '2026-11-16T16:48:00Z', 'run-18-1742'], ['16', '2026-11-16T16:46:00Z', '2026-11-16T16:52:00Z', 'run-16-1746']]);

    // Ready at Ebertplatz 17:38: the 16 at 17:36 has gone; the 18 at 17:42 is next there.
    $this->withToken($this->token)->postJson('/api/v1/travel/trip/ride/next', ['leg' => 1, 'ready_at' => ($this->at)('17:38')])
        ->assertOk()->assertJson(['line' => '18', 'depart_time' => '17:42', 'arrive_time' => '17:52']);
    expect(ActiveTrip::query()->where('user_id', $this->person->id)->value('journey')['legs'][1]['line'])->toBe('18');
});

test('a tram the router has no way on from is said so, and the trip stays', function () {
    fakeRuns([['16', '2026-11-16T16:36:00Z', '2026-11-16T16:42:00Z', 'run-16-1736']]);

    $this->withToken($this->token)
        ->postJson('/api/v1/travel/trip/ride', ['leg' => 1, 'line' => '18', 'depart_at' => ($this->at)('17:42')])->assertUnprocessable();
    expect(ActiveTrip::query()->where('user_id', $this->person->id)->value('journey')['legs'][1]['line'])->toBe('16');
});
