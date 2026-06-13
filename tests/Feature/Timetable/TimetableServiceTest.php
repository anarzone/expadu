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
        $m->shouldReceive('getDepartures')->with('Tram Stop', Mockery::any())->andReturn([
            'source' => 'trias_rt',
            'departures' => [
                ['line' => '1', 'direction' => 'Weiden West', 'type' => 'tram', 'color' => '#1A4CD4', 'departures' => [2, 12, 22], 'delay' => 0],
                ['line' => '142', 'direction' => 'Ehrenfeld', 'type' => 'bus', 'color' => '#1A4CD4', 'departures' => [5], 'delay' => 0],
            ],
        ]);
        $m->shouldReceive('getDepartures')->with('Bus Stop', Mockery::any())->andReturn([
            'source' => 'trias_rt',
            'departures' => [
                ['line' => '142', 'direction' => 'Ehrenfeld', 'type' => 'bus', 'color' => '#1A4CD4', 'departures' => [4, 14], 'delay' => 2],
            ],
        ]);
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
