<?php

use App\Models\Spot;
use Illuminate\Support\Facades\Http;

test('resolves photos from wikidata P18 claims with commons attribution', function () {
    $spot = Spot::factory()->create([
        'name' => 'Museum Ludwig',
        'category' => 'museum',
        'lat' => 50.9403,
        'lng' => 6.9602,
        'tags' => ['wikidata' => 'Q703640'],
    ]);

    Http::fake([
        'www.wikidata.org/*' => Http::response([
            'entities' => [
                'Q703640' => [
                    'claims' => [
                        'P18' => [[
                            'mainsnak' => ['datavalue' => ['value' => 'Museum Ludwig Köln.jpg']],
                        ]],
                    ],
                ],
            ],
        ]),
        'commons.wikimedia.org/*' => Http::response([
            'query' => [
                'pages' => [
                    '123' => [
                        'title' => 'File:Museum Ludwig Köln.jpg',
                        'imageinfo' => [[
                            'extmetadata' => [
                                'Artist' => ['value' => '<a href="#">Jane Doe</a>'],
                                'LicenseShortName' => ['value' => 'CC BY-SA 4.0'],
                            ],
                        ]],
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('spots:fetch-photos')->assertSuccessful();

    $spot->refresh();
    expect($spot->photo_url)->toContain('Special:FilePath/Museum%20Ludwig%20K');
    expect($spot->photo_url)->toContain('width=800');
    expect($spot->photo_attribution)->toBe('Jane Doe · CC BY-SA 4.0 · Wikimedia Commons');
});

test('falls back to the wikipedia page image when there is no wikidata tag', function () {
    $spot = Spot::factory()->create([
        'name' => 'Blücherpark',
        'category' => 'park',
        'lat' => 50.962,
        'lng' => 6.930,
        'tags' => ['wikipedia' => 'de:Blücherpark'],
    ]);

    Http::fake([
        'de.wikipedia.org/*' => Http::response([
            'query' => [
                'pages' => [
                    '42' => ['title' => 'Blücherpark', 'pageimage' => 'Bluecherpark Pavillon.jpg'],
                ],
            ],
        ]),
        'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => []]]),
    ]);

    $this->artisan('spots:fetch-photos')->assertSuccessful();

    $spot->refresh();
    expect($spot->photo_url)->toContain('Special:FilePath/Bluecherpark%20Pavillon.jpg');
    expect($spot->photo_attribution)->toBe('Wikimedia Commons');
});

test('leaves unlinked spots untouched', function () {
    Http::fake();
    $spot = Spot::factory()->create([
        'name' => 'Tischtennisplatte',
        'category' => 'table_tennis',
        'lat' => 50.948,
        'lng' => 6.921,
        'tags' => ['surface' => 'concrete'],
    ]);

    $this->artisan('spots:fetch-photos')->assertSuccessful();

    expect($spot->refresh()->photo_url)->toBeNull();
    Http::assertNothingSent();
});
