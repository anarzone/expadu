<?php

use App\Console\Commands\ImportOsmSpots;
use App\Enums\SpotCategory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

test('named lakes woods and recreation grounds import as green destinations', function () {
    $command = new ImportOsmSpots;
    $resolve = new ReflectionMethod($command, 'resolveCategory');

    expect($resolve->invoke($command, ['natural' => 'water', 'water' => 'lake'], 'green'))->toBe('lake')
        ->and($resolve->invoke($command, ['landuse' => 'forest'], 'green'))->toBe('nature')
        ->and($resolve->invoke($command, ['leisure' => 'nature_reserve'], 'green'))->toBe('nature')
        ->and($resolve->invoke($command, ['landuse' => 'recreation_ground'], 'green'))->toBe('park')
        ->and(SpotCategory::Lake->coarse())->toBe('park')
        ->and(SpotCategory::Nature->coarse())->toBe('park')
        ->and(SpotCategory::finesForCoarse('swimming'))->toBe(['swimming']);
});

test('green spaces skip fountains basins tiny ponds and reviewed exclusions', function (array $element, bool $expected) {
    $command = new ImportOsmSpots;
    $qualifies = new ReflectionMethod($command, 'greenSpaceQualifies');

    expect($qualifies->invoke($command, $element))->toBe($expected);
})->with([
    'large lake' => [['type' => 'way', 'id' => 1, 'tags' => ['name' => 'Fühlinger See', 'natural' => 'water', 'water' => 'lake'], 'bounds' => ['minlat' => 51.02, 'maxlat' => 51.04, 'minlon' => 6.88, 'maxlon' => 6.90]], true],
    'fountain' => [['type' => 'way', 'id' => 2, 'tags' => ['name' => 'Heinzelmännchenbrunnen', 'natural' => 'water'], 'bounds' => ['minlat' => 50.9, 'maxlat' => 50.91, 'minlon' => 6.9, 'maxlon' => 6.91]], false],
    'harbour basin' => [['type' => 'way', 'id' => 3, 'tags' => ['name' => 'Hafenbecken I', 'natural' => 'water', 'water' => 'harbour'], 'bounds' => ['minlat' => 50.9, 'maxlat' => 50.91, 'minlon' => 6.9, 'maxlon' => 6.91]], false],
    'tiny pond' => [['type' => 'way', 'id' => 4, 'tags' => ['name' => 'Amphibienteich', 'natural' => 'water', 'water' => 'pond'], 'bounds' => ['minlat' => 50.9, 'maxlat' => 50.9005, 'minlon' => 6.9, 'maxlon' => 6.9005]], false],
    'small wood' => [['type' => 'way', 'id' => 5, 'tags' => ['name' => 'Asien', 'landuse' => 'forest'], 'bounds' => ['minlat' => 50.9, 'maxlat' => 50.901, 'minlon' => 6.9, 'maxlon' => 6.901]], false],
    'reviewed exclusion' => [['type' => 'way', 'id' => 1167638870, 'tags' => ['name' => 'Blackfoot Hochseilgarten', 'landuse' => 'recreation_ground'], 'bounds' => ['minlat' => 50.9, 'maxlat' => 50.91, 'minlon' => 6.9, 'maxlon' => 6.91]], false],
    'missing bounds' => [['type' => 'way', 'id' => 6, 'tags' => ['name' => 'Königsforst', 'landuse' => 'forest']], false],
]);

test('overpass requests identify the importer so the main endpoint accepts them', function () {
    Http::fake(['*' => Http::response(['elements' => []])]);
    $command = new ImportOsmSpots;

    (new ReflectionMethod($command, 'overpass'))->invoke($command, 'https://overpass-api.de/api/interpreter', '[out:json];node(1);out;');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('User-Agent', ImportOsmSpots::USER_AGENT)
        && $request['data'] === '[out:json];node(1);out;');
});

test('an object returned by several category queries is recorded once', function () {
    $command = new ImportOsmSpots;
    $unique = new ReflectionMethod($command, 'uniqueElements');

    $result = $unique->invoke($command, [
        ['type' => 'node', 'id' => 7, '_category' => 'cafe', 'tags' => ['name' => 'Kaffee', 'opening_hours' => 'Mo-Fr 08:00-18:00']],
        ['type' => 'node', 'id' => 8, '_category' => 'cafe', 'tags' => ['name' => 'Other']],
        ['type' => 'node', 'id' => 7, '_category' => 'restaurant', 'tags' => ['name' => 'Kaffee', 'opening_hours' => 'Mo-Sa 08:00-18:00']],
        ['type' => 'way', 'id' => 7, '_category' => 'park', 'tags' => ['name' => 'Same id, different type']],
    ]);

    expect(array_map(fn (array $e): string => $e['type'].'/'.$e['id'].':'.$e['_category'], $result))
        ->toBe(['node/7:cafe', 'node/8:cafe', 'way/7:park']);
});

test('green spaces without a center use their bounds midpoint', function () {
    $command = new ImportOsmSpots;
    $point = new ReflectionMethod($command, 'elementPoint');

    [$lat, $lng] = $point->invoke($command, ['type' => 'way', 'bounds' => ['minlat' => 51.0, 'maxlat' => 51.02, 'minlon' => 6.88, 'maxlon' => 6.9]]);

    expect($lat)->toEqualWithDelta(51.01, 1e-9)
        ->and($lng)->toEqualWithDelta(6.89, 1e-9)
        ->and($point->invoke($command, ['type' => 'node', 'lat' => 50.9, 'lon' => 6.9]))->toBe([50.9, 6.9])
        ->and($point->invoke($command, ['type' => 'way', 'center' => ['lat' => 50.91, 'lon' => 6.91]]))->toBe([50.91, 6.91])
        ->and($point->invoke($command, ['type' => 'way']))->toBe([0.0, 0.0]);
});
