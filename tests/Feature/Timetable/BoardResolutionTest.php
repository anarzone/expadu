<?php

use App\Services\GtfsDepartureService;
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

test('an off-hours board surfaces the next departure instead of showing nothing', function () {
    // At night the only departure is hours out (e.g. next tram 03:56 at 01:26 =
    // 150 min). It's beyond the 2h "catch it soon" horizon, but the board must
    // still surface it rather than a dead-end "no live departures".
    $this->mock(GtfsDepartureService::class, function ($m) {
        $far = ['stop_name' => 'Merkenich Mitte', 'source' => 'gtfs', 'departures' => [dep('12', 'tram', [150], 'Neumarkt')]];
        $m->shouldReceive('getDeparturesNearby')->andReturn($far);
        $m->shouldReceive('routeContext')->andReturn(['direction' => null, 'via' => []]);
    });

    $board = app(TimetableService::class)->pointBoard(['name' => 'Merkenich Mitte', 'lat' => 51.0244, 'lng' => 6.9529]);

    expect($board['departures'])->not->toBeEmpty();
    expect($board['departures'][0]['line'])->toBe('12');
});

test('a normal board keeps only the imminent departures, dropping the far ones', function () {
    // The wide-horizon fallback must NOT kick in when something is imminent —
    // a 200-min row is still dropped as long as a soon one exists.
    $this->mock(GtfsDepartureService::class, function ($m) {
        $mixed = ['stop_name' => 'Zülpicher Platz', 'source' => 'trias_rt', 'departures' => [dep('9', 'tram', [4, 200], 'Königsforst')]];
        $m->shouldReceive('getDeparturesNearby')->andReturn($mixed);
        $m->shouldReceive('routeContext')->andReturn(['direction' => null, 'via' => []]);
    });

    $board = app(TimetableService::class)->pointBoard(['name' => 'Zülpicher Platz', 'lat' => 50.9295, 'lng' => 6.9375]);

    expect($board['departures'][0]['minutes'])->toBe([4]);
});
