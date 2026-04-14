<?php

uses()->group('slow');
use App\Models\CityNews;
use App\Services\DisruptionService;
use App\Services\KvbApiService;

test('kvb api returns stops with line assignments', function () {
    $kvb = app(KvbApiService::class);
    $stops = $kvb->getStops();

    expect($stops)->toBeArray();

    if (count($stops) > 0) {
        $stop = $stops[0];
        expect($stop)->toHaveKeys(['id', 'name', 'lines', 'lat', 'lng']);
        expect($stop['name'])->toBeString();
        expect($stop['lines'])->toBeArray();
    }
});

test('kvb api can search stops by name', function () {
    $kvb = app(KvbApiService::class);
    $results = $kvb->searchStops('Neumarkt');

    expect($results)->toBeArray();
    // May be empty if KVB API is down, that's OK
});

test('kvb api returns elevator disruptions', function () {
    $kvb = app(KvbApiService::class);
    $disruptions = $kvb->getElevatorDisruptions();

    expect($disruptions)->toBeArray();

    if (count($disruptions) > 0) {
        expect($disruptions[0])->toHaveKeys(['id', 'label', 'stop_area', 'lat', 'lng']);
    }
});

test('disruption service combines line and accessibility disruptions', function () {
    // Seed a line disruption
    CityNews::create([
        'title' => 'Trennung der Linie 1',
        'source' => 'kvb',
        'source_url' => 'https://test/'.uniqid(),
        'category' => 'transit',
        'relevance' => 'transit',
        'affected_lines' => ['1'],
        'severity' => 'major',
        'published_at' => now(),
    ]);

    $service = app(DisruptionService::class);
    $disruptions = $service->getActiveDisruptions();

    expect($disruptions)->toBeArray();

    // Should have at least the line disruption we created
    $lineDisruptions = collect($disruptions)->where('type', 'line');
    expect($lineDisruptions)->not->toBeEmpty();
});

test('disruption service reports disrupted lines', function () {
    CityNews::create([
        'title' => 'Linie 9 gesperrt',
        'source' => 'kvb',
        'source_url' => 'https://test/'.uniqid(),
        'category' => 'transit',
        'relevance' => 'transit',
        'affected_lines' => ['9'],
        'severity' => 'critical',
        'published_at' => now(),
    ]);

    $service = app(DisruptionService::class);
    $lines = $service->getDisruptedLines();

    expect($lines)->toHaveKey('9');
    expect($lines['9'])->toBe('critical');
});
