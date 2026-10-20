<?php

use App\Models\User;
use App\Services\GtfsDepartureService;
use App\Services\TimetableService;
use App\Transit\NearbyStation;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

test('a point board colours the lines and keeps their minutes', function () {
    $this->mock(GtfsDepartureService::class, function ($m) {
        $m->shouldReceive('getDeparturesNearby')->andReturn([
            'source' => 'trias_rt',
            'departures' => [['line' => '1', 'direction' => 'Weiden West', 'type' => 'tram', 'color' => '#1A4CD4', 'departures' => [2, 12, 22], 'delay' => 0]],
        ]);
        $m->shouldReceive('routeContext')->andReturn(['direction' => null, 'via' => []]);
    });

    $board = app(TimetableService::class)->pointBoard(['name' => 'Tram Stop', 'lat' => 50.9485, 'lng' => 6.9230]);

    // Line 1 is coloured from the KVB map, not the feed's default blue.
    $line1 = collect($board['departures'])->firstWhere('line', '1');
    expect($board['stop_name'])->toBe('Tram Stop')
        ->and($line1['color'])->toBe('#E2001A')
        ->and($line1['minutes'])->toBe([2, 12, 22]);
});

test('the Travel page renders and defers Nearby\'s station', function () {
    $this->mock(NearbyStation::class, fn ($m) => $m->shouldReceive('closest')->andReturn(null));

    $this->actingAs(User::factory()->onboarded()->create());

    $this->get('/timetable')->assertInertia(fn ($page) => $page
        ->component('timetable')
        ->missing('station')
        ->loadDeferredProps(fn ($reload) => $reload->has('station'))
    );
});

test('board departures carry the GTFS direction lane and via stops', function () {
    $this->mock(GtfsDepartureService::class, function ($m) {
        $m->shouldReceive('getDeparturesNearby')->andReturn([
            'source' => 'trias_rt',
            'departures' => [
                ['line' => '1', 'direction' => 'Bensberg', 'type' => 'tram', 'color' => '#E2001A', 'departures' => [3, 13], 'delay' => 0],
            ],
        ]);
        $m->shouldReceive('routeContext')->with('Neumarkt', '1', 'Bensberg')
            ->andReturn(['direction' => 0, 'via' => ['Heumarkt', 'Deutz/Messe']]);
    });

    $departure = app(TimetableService::class)->pointBoard(['name' => 'Neumarkt', 'lat' => 50.9364, 'lng' => 6.9470])['departures'][0];

    expect($departure['direction'])->toBe(0);
    expect($departure['via'])->toBe(['Heumarkt', 'Deutz/Messe']);
});
