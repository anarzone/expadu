<?php

use App\Models\User;
use App\Services\GtfsDepartureService;
use App\Services\KvbApiService;
use App\Services\TimetableService;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

test('boards resolve the nearest stop per mode and colour the lines', function () {
    $this->mock(KvbApiService::class, function ($m) {
        $m->shouldReceive('getStops')->andReturn([
            ['name' => 'Tram Stop', 'area' => 'STRAB', 'lat' => 50.9485, 'lng' => 6.9230, 'lines' => ['1']],
            ['name' => 'Bus Stop', 'area' => 'BUS', 'lat' => 50.9460, 'lng' => 6.9250, 'lines' => ['142']],
        ]);
    });
    $this->mock(GtfsDepartureService::class, function ($m) {
        // "all"/"rail" resolve by NAME (the hub name → the combined station).
        $m->shouldReceive('getDepartures')->with('Tram Stop', Mockery::any())->andReturn([
            'source' => 'trias_rt',
            'departures' => [
                ['line' => '1', 'direction' => 'Weiden West', 'type' => 'tram', 'color' => '#1A4CD4', 'departures' => [2, 12, 22], 'delay' => 0],
                ['line' => '142', 'direction' => 'Ehrenfeld', 'type' => 'bus', 'color' => '#1A4CD4', 'departures' => [5], 'delay' => 0],
            ],
        ]);
        // Tram + bus boards resolve by COORDINATES — the correct StopPlace per
        // mode (the name is ambiguous at interchanges).
        $m->shouldReceive('getDeparturesNearby')->andReturnUsing(fn (float $lat) => abs($lat - 50.9485) < 0.001
            ? ['source' => 'trias_rt', 'departures' => [['line' => '1', 'direction' => 'Weiden West', 'type' => 'tram', 'color' => '#1A4CD4', 'departures' => [2, 12, 22], 'delay' => 0]]]
            : ['source' => 'trias_rt', 'departures' => [['line' => '142', 'direction' => 'Ehrenfeld', 'type' => 'bus', 'color' => '#1A4CD4', 'departures' => [4, 14], 'delay' => 2]]]);
    });

    $boards = app(TimetableService::class)->boards(50.9485, 6.9230);

    // All = nearest overall stop (Tram Stop), both modes present.
    expect($boards['all']['stop_name'])->toBe('Tram Stop');
    expect(collect($boards['all']['departures'])->pluck('line'))->toContain('1')->toContain('142');

    // Tram board = nearest tram stop, trams only.
    expect($boards['tram']['stop_name'])->toBe('Tram Stop');
    expect(collect($boards['tram']['departures'])->pluck('type')->unique()->all())->toBe(['tram']);

    // Bus board = the *different* nearest bus stop, buses only — the tab's whole point.
    expect($boards['bus']['stop_name'])->toBe('Bus Stop');
    expect(collect($boards['bus']['departures'])->pluck('type')->unique()->all())->toBe(['bus']);

    // Line 1 is coloured from the KVB map, not the default blue.
    $line1 = collect($boards['all']['departures'])->firstWhere('line', '1');
    expect($line1['color'])->toBe('#E2001A');
    expect($line1['minutes'])->toBe([2, 12, 22]);
});

test('the departures page renders and defers the boards', function () {
    $this->mock(TimetableService::class, function ($m) {
        $m->shouldReceive('boards')->andReturn(['all' => null, 'tram' => null, 'bus' => null]);
    });

    $this->actingAs(User::factory()->onboarded()->create());

    $this->get('/timetable')->assertInertia(fn ($page) => $page
        ->component('timetable')
        ->loadDeferredProps(fn ($reload) => $reload->has('boards'))
    );
});

test('the tram board keeps underground Stadtbahn and a rail board surfaces trains', function () {
    $this->mock(KvbApiService::class, function ($m) {
        // Only a Stadtbahn stop nearby — no bus stop, so the Bus board is null.
        $m->shouldReceive('getStops')->andReturn([
            ['name' => 'Dom/Hbf', 'area' => 'STRAB', 'lat' => 50.9430, 'lng' => 6.9585, 'lines' => ['5', '16', '18']],
        ]);
    });
    $this->mock(GtfsDepartureService::class, function ($m) {
        // "all"/"rail" by NAME — the hub name resolves to the combined station.
        $m->shouldReceive('getDepartures')->andReturn([
            'source' => 'trias_rt',
            'departures' => [
                ['line' => '5', 'direction' => 'Ossendorf', 'type' => 'tram', 'color' => '#1A4CD4', 'departures' => [2], 'delay' => 0],
                // VRS can report the underground stretch as "subway" — it's still a Stadtbahn.
                ['line' => '16', 'direction' => 'Niehl', 'type' => 'subway', 'color' => '#1A4CD4', 'departures' => [4], 'delay' => 0],
                ['line' => 'S12', 'direction' => 'Hennef', 'type' => 'rail', 'color' => '#1A4CD4', 'departures' => [6], 'delay' => 0],
            ],
        ]);
        // Tram board by COORDINATES — the Stadtbahn platform (surface + underground).
        $m->shouldReceive('getDeparturesNearby')->andReturn([
            'source' => 'trias_rt',
            'departures' => [
                ['line' => '5', 'direction' => 'Ossendorf', 'type' => 'tram', 'color' => '#1A4CD4', 'departures' => [2], 'delay' => 0],
                ['line' => '16', 'direction' => 'Niehl', 'type' => 'subway', 'color' => '#1A4CD4', 'departures' => [4], 'delay' => 0],
            ],
        ]);
        $m->shouldReceive('routeContext')->andReturn(['direction' => null, 'via' => []]);
    });

    $boards = app(TimetableService::class)->boards(50.9430, 6.9585);

    // Tram tab keeps the underground Stadtbahn (type "subway") next to surface trams.
    expect(collect($boards['tram']['departures'])->pluck('line')->all())->toBe(['5', '16']);

    // Rail rides the "all" pick and shows only trains.
    expect(collect($boards['rail']['departures'])->pluck('line')->all())->toBe(['S12']);
    expect(collect($boards['rail']['departures'])->pluck('type')->unique()->all())->toBe(['rail']);

    // No nearby bus stop → null board → the frontend hides the Bus tab.
    expect($boards['bus'])->toBeNull();
});

test('board departures carry the GTFS direction lane and via stops', function () {
    $this->mock(KvbApiService::class, function ($m) {
        $m->shouldReceive('getStops')->andReturn([
            ['name' => 'Neumarkt', 'area' => 'STRAB', 'lat' => 50.9364, 'lng' => 6.9470, 'lines' => ['1']],
        ]);
    });
    $this->mock(GtfsDepartureService::class, function ($m) {
        // Direction lanes live on the *mode* boards (resolved by coordinates);
        // the "All" tab merges platforms and drops the lane, so assert here on
        // the tram board where the lane is the point.
        $m->shouldReceive('getDeparturesNearby')->andReturn([
            'source' => 'trias_rt',
            'departures' => [
                ['line' => '1', 'direction' => 'Bensberg', 'type' => 'tram', 'color' => '#E2001A', 'departures' => [3, 13], 'delay' => 0],
            ],
        ]);
        $m->shouldReceive('getDepartures')->andReturn(['source' => 'trias_rt', 'departures' => []]);
        $m->shouldReceive('routeContext')->with('Neumarkt', '1', 'Bensberg')
            ->andReturn(['direction' => 0, 'via' => ['Heumarkt', 'Deutz/Messe']]);
    });

    $boards = app(TimetableService::class)->boards(50.9364, 6.9470);
    $departure = $boards['tram']['departures'][0];

    expect($departure['direction'])->toBe(0);
    expect($departure['via'])->toBe(['Heumarkt', 'Deutz/Messe']);
});
