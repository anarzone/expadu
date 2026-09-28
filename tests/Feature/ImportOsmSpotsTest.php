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

test('source aliases keep translated names without exposing etymology ids or pronunciation metadata', function () {
    $command = new ImportOsmSpots;
    $aliases = new ReflectionMethod($command, 'sourceAliases');

    expect($aliases->invoke($command, [
        'name' => 'Yitzhak-Rabin-Platz',
        'alt_name' => 'Rabinplatz',
        'name:en' => 'Yitzhak Rabin Square',
        'name:sr-Latn' => 'Trg Jicaka Rabina',
        'name:etymology:wikidata' => 'Q34060',
        'name:pronunciation' => 'test phonetics',
        'name:signed' => 'yes',
    ]))->toBe(['Rabinplatz', 'Yitzhak Rabin Square', 'Trg Jicaka Rabina']);
});

test('practical source facts survive the OSM import tag projection', function () {
    $command = new ImportOsmSpots;
    $method = new ReflectionMethod($command, 'keptTags');
    $facts = [
        'name' => 'Example sports cafe',
        'cuisine' => 'vegetarian',
        'reservation' => 'required',
        'fee' => 'no',
        'fee:conditional' => 'yes @ (Sa-Su)',
        'charge' => '5 EUR',
        'membership' => 'yes',
        'access:conditional' => 'private @ (22:00-06:00)',
        'wheelchair' => 'limited',
        'diet:vegan' => 'yes',
        'takeaway' => 'yes',
        'addr:street' => 'Example street',
        'check_date' => '2026-09-28',
    ];

    $kept = $method->invoke($command, [...$facts, 'invalid_nested' => ['yes'], 'invalid_bool' => true, 'empty' => '   ']);

    expect($kept)->toBe($facts);
});

test('source website links preserve international hostnames and paths through refresh', function (?string $source, ?string $expected) {
    $command = new ImportOsmSpots;
    $method = new ReflectionMethod($command, 'httpUrlTag');

    expect($method->invoke($command, $source))->toBe($expected);
})->with([
    ['https://café.de/straße', 'https://xn--caf-dma.de/stra%C3%9Fe'],
    ['https://example.com/bäckerei?q=köln', 'https://example.com/b%C3%A4ckerei?q=k%C3%B6ln'],
    ['https://example.com/already%20encoded', 'https://example.com/already%20encoded'],
    ['https://name:secret@example.com/', null],
    ['javascript:alert(1)', null],
]);
