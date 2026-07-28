<?php

use App\Console\Commands\ImportOsmSpots;

// TODO: Re-enable these tests after fixing the OSM import command structure
// The command was refactored to make separate API calls per category
// Tests need to mock multiple HTTP requests instead of one

test('osm:import command exists', function () {
    $this->artisan('osm:import --city=invalid')
        ->assertFailed();
});

test('the importer maps picnic features to the picnic category', function () {
    $cmd = new ImportOsmSpots;

    $resolve = new ReflectionMethod($cmd, 'resolveCategory');
    $fallback = new ReflectionMethod($cmd, 'fallbackName');

    expect($resolve->invoke($cmd, ['leisure' => 'picnic_table'], 'picnic'))->toBe('picnic');
    expect($resolve->invoke($cmd, ['tourism' => 'picnic_site'], 'picnic'))->toBe('picnic');
    expect($fallback->invoke($cmd, 'picnic', []))->toBe('Picknickplatz');
});

test('the pitch query refines basketball and soccer by sport', function () {
    $cmd = new ImportOsmSpots;
    $resolve = new ReflectionMethod($cmd, 'resolveCategory');

    expect($resolve->invoke($cmd, ['sport' => 'basketball'], 'pitch'))->toBe('basketball');
    expect($resolve->invoke($cmd, ['sport' => 'soccer'], 'pitch'))->toBe('pitch');
    expect($resolve->invoke($cmd, ['sport' => 'multi'], 'pitch'))->toBe('pitch');
    expect($resolve->invoke($cmd, ['sport' => 'tennis'], 'sports_centre'))->toBe('sports_centre');
});
