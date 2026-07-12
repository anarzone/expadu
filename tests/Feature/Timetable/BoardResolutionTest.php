<?php

use App\Services\GtfsDepartureService;
use App\Services\KvbApiService;
use App\Services\TimetableService;
use Illuminate\Support\Facades\Cache;

/**
 * Departure helper — one line's board row in the shape GtfsDepartureService emits.
 *
 * @param  list<int>  $minutes
 */
function dep(string $line, string $type, array $minutes, string $dir = 'Somewhere'): array
{
    return [
        'line' => $line,
        'direction' => $dir,
        'type' => $type,
        'color' => '#e2001a',
        'departures' => $minutes,
        'delay' => 0,
        'cancelled' => false,
        'disrupted' => false,
    ];
}

beforeEach(function () {
    Cache::flush();
});

test('an interchange surfaces tram + bus + rail, not just the rail station', function () {
    // At Köln Hbf the KVB tram + bus platforms share the name "Dom/Hbf", which
    // TRIAS resolves to the rail station — so a NAME lookup returns only rail and
    // the Tram/Bus tabs came back empty. The fix resolves tram + bus by their
    // precise COORDINATES instead.
    $this->mock(KvbApiService::class, function ($m) {
        $m->shouldReceive('getStops')->andReturn([
            ['id' => 1, 'name' => 'Dom/Hbf', 'area' => 'STRAB', 'lat' => 50.9418, 'lng' => 6.9576, 'lines' => ['5', '16', '18']],
            ['id' => 2, 'name' => 'Breslauer Platz/Hbf', 'area' => 'BUS', 'lat' => 50.9445, 'lng' => 6.9586, 'lines' => ['132']],
        ]);
    });

    $this->mock(GtfsDepartureService::class, function ($m) {
        // The ambiguous hub NAME resolves to the rail station.
        $m->shouldReceive('getDepartures')->andReturn([
            'stop_name' => 'Köln Hbf', 'source' => 'trias_rt',
            'departures' => [dep('S12', 'rail', [3, 20], 'Hennef')],
        ]);
        // Precise COORDINATES resolve to the correct StopPlace per mode.
        $m->shouldReceive('getDeparturesNearby')->andReturnUsing(function (float $lat) {
            return abs($lat - 50.9418) < 0.001
                ? ['stop_name' => 'Dom/Hbf', 'source' => 'trias_rt', 'departures' => [dep('5', 'tram', [2, 12], 'Heumarkt')]]
                : ['stop_name' => 'Breslauer Platz/Hbf', 'source' => 'trias_rt', 'departures' => [dep('132', 'bus', [5], 'Zoo')]];
        });
        $m->shouldReceive('routeContext')->andReturn(['direction' => null, 'via' => []]);
    });

    $boards = app(TimetableService::class)->boards(50.9418, 6.9576);

    expect($boards['tram']['departures'])->not->toBeEmpty();
    expect($boards['bus']['departures'])->not->toBeEmpty();
    expect($boards['rail']['departures'])->not->toBeEmpty();
    expect($boards['tram']['departures'][0]['type'])->toBe('tram');
    expect($boards['bus']['departures'][0]['type'])->toBe('bus');
    expect($boards['rail']['departures'][0]['type'])->toBe('rail');
});

test('an off-hours board surfaces the next departure instead of showing nothing', function () {
    // At night the only departure is hours out (e.g. next tram 03:56 at 01:26 =
    // 150 min). It's beyond the 2h "catch it soon" horizon, but the board must
    // still surface it rather than a dead-end "no live departures".
    $this->mock(KvbApiService::class, function ($m) {
        $m->shouldReceive('getStops')->andReturn([
            ['id' => 1, 'name' => 'Merkenich Mitte', 'area' => 'STRAB', 'lat' => 51.0244, 'lng' => 6.9529, 'lines' => ['12']],
        ]);
    });

    $this->mock(GtfsDepartureService::class, function ($m) {
        $far = ['stop_name' => 'Merkenich Mitte', 'source' => 'gtfs', 'departures' => [dep('12', 'tram', [150], 'Neumarkt')]];
        $m->shouldReceive('getDepartures')->andReturn($far);
        $m->shouldReceive('getDeparturesNearby')->andReturn($far);
        $m->shouldReceive('routeContext')->andReturn(['direction' => null, 'via' => []]);
    });

    $boards = app(TimetableService::class)->boards(51.0244, 6.9529);

    expect($boards['all']['departures'])->not->toBeEmpty();
    expect($boards['all']['departures'][0]['line'])->toBe('12');
    expect($boards['tram']['departures'])->not->toBeEmpty();
});

test('a normal board keeps only the imminent departures, dropping the far ones', function () {
    // The wide-horizon fallback must NOT kick in when something is imminent —
    // a 200-min row is still dropped as long as a soon one exists.
    $this->mock(KvbApiService::class, function ($m) {
        $m->shouldReceive('getStops')->andReturn([
            ['id' => 1, 'name' => 'Zülpicher Platz', 'area' => 'STRAB', 'lat' => 50.9295, 'lng' => 6.9375, 'lines' => ['9']],
        ]);
    });

    $this->mock(GtfsDepartureService::class, function ($m) {
        $mixed = ['stop_name' => 'Zülpicher Platz', 'source' => 'trias_rt', 'departures' => [dep('9', 'tram', [4, 200], 'Königsforst')]];
        $m->shouldReceive('getDepartures')->andReturn($mixed);
        $m->shouldReceive('getDeparturesNearby')->andReturn($mixed);
        $m->shouldReceive('routeContext')->andReturn(['direction' => null, 'via' => []]);
    });

    $boards = app(TimetableService::class)->boards(50.9295, 6.9375);

    expect($boards['tram']['departures'][0]['minutes'])->toBe([4]);
});
