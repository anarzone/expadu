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

test('falls back to the wikipedia page image, surviving redirects and underscored names', function () {
    $spot = Spot::factory()->create([
        'name' => 'Blücherpark',
        'category' => 'park',
        'lat' => 50.962,
        'lng' => 6.930,
        'tags' => ['wikipedia' => 'de:Bluecherpark Koeln'], // redirect-reached title
    ]);

    Http::fake([
        'de.wikipedia.org/*' => Http::response([
            'query' => [
                // The API resolves to the canonical title and returns an
                // underscored pageimage — both must still map back.
                'redirects' => [['from' => 'Bluecherpark Koeln', 'to' => 'Blücherpark']],
                'pages' => [
                    '42' => ['title' => 'Blücherpark', 'pageimage' => 'Bluecherpark_Pavillon.jpg'],
                ],
            ],
        ]),
        'commons.wikimedia.org/*' => Http::response([
            'query' => [
                'pages' => [
                    '7' => [
                        'title' => 'File:Bluecherpark Pavillon.jpg', // Commons answers with spaces
                        'imageinfo' => [['extmetadata' => [
                            'Artist' => ['value' => 'Max'],
                            'LicenseShortName' => ['value' => 'CC BY 4.0'],
                        ]]],
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('spots:fetch-photos')->assertSuccessful();

    $spot->refresh();
    expect($spot->photo_url)->toContain('Special:FilePath/Bluecherpark_Pavillon.jpg');
    expect($spot->photo_attribution)->toBe('Max · CC BY 4.0 · Wikimedia Commons');
});

test('leaves small unlinked spots untouched (no geosearch for point features)', function () {
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
    Http::assertNothingSent(); // a court is not a geosearch category
});

test('geosearch backfills a large outdoor place with the nearest commons photo', function () {
    $park = Spot::factory()->create([
        'name' => 'Stadtwald',
        'category' => 'park',
        'lat' => 50.93,
        'lng' => 6.89,
        'tags' => ['leisure' => 'park'], // no wikidata/wikipedia link
    ]);

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'geosearch')) {
            // Nearest-first; index 1 is an SVG map (skip), 2 is the real photo.
            return Http::response(['query' => ['pages' => [
                '1' => ['title' => 'File:Köln Stadtwald Karte.svg', 'index' => 1, 'imageinfo' => [['mediatype' => 'DRAWING']]],
                '2' => ['title' => 'File:Stadtwald Köln Teich.jpg', 'index' => 2, 'imageinfo' => [['mediatype' => 'BITMAP']]],
                '3' => ['title' => 'File:Nachbarhaus.jpg', 'index' => 3, 'imageinfo' => [['mediatype' => 'BITMAP']]],
            ]]]);
        }

        return Http::response(['query' => ['pages' => [
            '9' => ['title' => 'File:Stadtwald Köln Teich.jpg', 'imageinfo' => [['extmetadata' => [
                'Artist' => ['value' => 'Foto Fan'],
                'LicenseShortName' => ['value' => 'CC BY-SA 3.0'],
            ]]]],
        ]]]);
    });

    $this->artisan('spots:fetch-photos')->assertSuccessful();

    $park->refresh();
    // Picks the nearest BITMAP, never the SVG map.
    expect($park->photo_url)->toContain('Special:FilePath/Stadtwald%20K')
        ->and($park->photo_attribution)->toBe('Foto Fan · CC BY-SA 3.0 · Wikimedia Commons');
});
