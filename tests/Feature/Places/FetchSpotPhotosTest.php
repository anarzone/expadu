<?php

use App\Media\PublishedMediaSelector;
use App\Models\MediaAsset;
use App\Models\Spot;
use Illuminate\Support\Facades\Http;

/** @return array<string, mixed> */
function commonsPhotoInfo(string $artist, string $license): array
{
    return [
        'mime' => 'image/jpeg',
        'width' => 1600,
        'height' => 1000,
        'sha1' => sha1($artist.$license),
        'extmetadata' => [
            'Artist' => ['value' => $artist],
            'LicenseShortName' => ['value' => $license],
            'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0/'],
        ],
    ];
}

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
                        'imageinfo' => [commonsPhotoInfo('<a href="#">Jane Doe</a>', 'CC BY-SA 4.0')],
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('spots:fetch-photos')->assertSuccessful();

    $spot->refresh();
    $media = app(PublishedMediaSelector::class)->select($spot, 'hero');
    expect($spot->photo_url)->toBeNull();
    expect($media?->remote_url)->toContain('Special:FilePath/Museum_Ludwig_K');
    expect($media?->remote_url)->toContain('width=1200');
    expect($media?->attribution)->toBe('Jane Doe · CC BY-SA 4.0 · Wikimedia Commons');
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
                        'imageinfo' => [commonsPhotoInfo('Max', 'CC BY 4.0')],
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('spots:fetch-photos')->assertSuccessful();

    $spot->refresh();
    $media = app(PublishedMediaSelector::class)->select($spot, 'hero');
    expect($spot->photo_url)->toBeNull();
    expect($media?->remote_url)->toContain('Special:FilePath/Bluecherpark_Pavillon.jpg');
    expect($media?->attribution)->toBe('Max · CC BY 4.0 · Wikimedia Commons');
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

test('an exact Commons tag is retained but not published when license metadata is incomplete', function () {
    $spot = Spot::factory()->create([
        'name' => 'Exact source park',
        'category' => 'park',
        'lat' => 50.93,
        'lng' => 6.89,
        'tags' => ['wikimedia_commons' => 'File:Exact source park.jpg'],
    ]);

    Http::fake([
        'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => [
            '12' => [
                'title' => 'File:Exact source park.jpg',
                'imageinfo' => [[
                    'thumburl' => 'https://upload.wikimedia.org/exact-source-park.jpg',
                    'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Exact_source_park.jpg',
                    'mime' => 'image/jpeg',
                    'width' => 1200,
                    'height' => 800,
                    'extmetadata' => [],
                ]],
            ],
        ]]]),
    ]);

    $this->artisan('spots:fetch-photos --geo=0')->assertSuccessful();

    $asset = MediaAsset::query()->sole();
    expect($asset->provider_asset_id)->toBe('File:Exact_source_park.jpg')
        ->and($asset->rights_status)->toBe('pending')
        ->and($asset->health_status)->toBe('active')
        ->and($spot->fresh()->photo_url)->toBeNull()
        ->and($spot->mediaAttachments()->count())->toBe(1);
});

test('noncommercial Commons licenses are never auto-published', function () {
    $spot = Spot::factory()->create([
        'name' => 'Restricted source park',
        'category' => 'park',
        'lat' => 50.93,
        'lng' => 6.89,
        'tags' => ['wikimedia_commons' => 'File:Restricted.jpg'],
    ]);
    $restricted = commonsPhotoInfo('Jane Doe', 'CC BY-NC 4.0');

    Http::fake([
        'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => [
            '13' => ['title' => 'File:Restricted.jpg', 'imageinfo' => [$restricted]],
        ]]]),
    ]);

    $this->artisan('spots:fetch-photos --geo=0')->assertSuccessful();

    expect(MediaAsset::query()->sole()->rights_status)->toBe('pending')
        ->and($spot->fresh()->photo_url)->toBeNull();
});

test('unsupported Commons image formats are not marked healthy or published', function () {
    $spot = Spot::factory()->create([
        'name' => 'Vector park',
        'category' => 'park',
        'lat' => 50.93,
        'lng' => 6.89,
        'tags' => ['wikimedia_commons' => 'File:Vector.svg'],
    ]);
    $vector = commonsPhotoInfo('Jane Doe', 'CC BY-SA 4.0');
    $vector['mime'] = 'image/svg+xml';

    Http::fake([
        'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => [
            '15' => ['title' => 'File:Vector.svg', 'imageinfo' => [$vector]],
        ]]]),
    ]);

    $this->artisan('spots:fetch-photos --geo=0')->assertSuccessful();

    expect(MediaAsset::query()->sole()->health_status)->toBe('pending')
        ->and(app(PublishedMediaSelector::class)->select($spot->fresh(), 'hero'))->toBeNull();
});

test('an authoritative Commons refresh revokes publishing when rights become restricted', function () {
    $spot = Spot::factory()->create([
        'name' => 'Changing rights park',
        'category' => 'park',
        'lat' => 50.93,
        'lng' => 6.89,
        'photo_url' => 'https://legacy.example/should-not-bypass.jpg',
        'photo_attribution' => 'Legacy credit',
        'tags' => ['wikimedia_commons' => 'File:Changing.jpg'],
    ]);
    $metadataCalls = 0;
    Http::fake(function () use (&$metadataCalls) {
        $metadataCalls++;
        $info = $metadataCalls === 1
            ? commonsPhotoInfo('Jane Doe', 'CC BY-SA 4.0')
            : commonsPhotoInfo('Jane Doe', 'CC BY-NC 4.0');

        return Http::response(['query' => ['pages' => [
            '14' => ['title' => 'File:Changing.jpg', 'imageinfo' => [$info]],
        ]]]);
    });

    $this->artisan('spots:fetch-photos --geo=0')->assertSuccessful();
    expect(app(PublishedMediaSelector::class)->select($spot->fresh(), 'hero'))->not->toBeNull();

    $this->artisan('spots:fetch-photos --force --geo=0')->assertSuccessful();

    expect(MediaAsset::query()->sole()->rights_status)->toBe('pending')
        ->and(app(PublishedMediaSelector::class)->select($spot->fresh(), 'hero'))->toBeNull()
        ->and($spot->fresh()->photo_url)->toBeNull();
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
            '9' => ['title' => 'File:Stadtwald Köln Teich.jpg', 'imageinfo' => [commonsPhotoInfo('Foto Fan', 'CC BY-SA 3.0')]],
        ]]]);
    });

    $this->artisan('spots:fetch-photos')->assertSuccessful();

    $park->refresh();
    // Picks the nearest BITMAP, never the SVG map.
    $media = app(PublishedMediaSelector::class)->select($park, 'hero');
    expect($park->photo_url)->toBeNull()
        ->and($media?->remote_url)->toContain('Special:FilePath/Stadtwald_K')
        ->and($media?->attribution)->toBe('Foto Fan · CC BY-SA 3.0 · Wikimedia Commons');
});
