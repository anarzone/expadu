<?php

use App\Media\PublishedMediaSelector;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/** @return array<string, mixed> */
function venueCommonsInfo(string $artist, string $license): array
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

function photoVenue(array $attrs = []): Venue
{
    return Venue::create(array_merge([
        'name' => 'Kölner Philharmonie',
        'address_text' => 'Bischofsgartenstraße 1',
        'lat' => 50.9403,
        'lng' => 6.9613,
        'veedel' => 'Altstadt-Nord',
    ], $attrs));
}

test('wikidata name search resolves a photo only for the coordinate-verified entity', function () {
    $venue = photoVenue();

    Http::fake(function ($request) {
        $url = $request->url();

        // Name search: the BEST label match is the wrong city's hall — the
        // coordinate gate must reject it and accept the Cologne entity.
        if (str_contains($url, 'wbsearchentities')) {
            return Http::response(['search' => [
                ['id' => 'Q170300'],  // Berliner Philharmonie (far away)
                ['id' => 'Q472950'],  // Kölner Philharmonie (matches coords)
            ]]);
        }

        if (str_contains($url, 'wbgetentities')) {
            return Http::response(['entities' => [
                'Q170300' => ['claims' => [
                    'P18' => [['mainsnak' => ['datavalue' => ['value' => 'Berliner Philharmonie.jpg']]]],
                    'P625' => [['mainsnak' => ['datavalue' => ['value' => ['latitude' => 52.51, 'longitude' => 13.37]]]]],
                ]],
                'Q472950' => ['claims' => [
                    'P18' => [['mainsnak' => ['datavalue' => ['value' => 'Kölner Philharmonie Saal.jpg']]]],
                    'P625' => [['mainsnak' => ['datavalue' => ['value' => ['latitude' => 50.9405, 'longitude' => 6.9610]]]]],
                ]],
            ]]);
        }

        return Http::response(['query' => ['pages' => [
            '1' => [
                'title' => 'File:Kölner Philharmonie Saal.jpg',
                'imageinfo' => [venueCommonsInfo('Raimond', 'CC BY-SA 4.0')],
            ],
        ]]]);
    });

    $this->artisan('venues:fetch-photos')->assertSuccessful();

    $media = app(PublishedMediaSelector::class)->select($venue->fresh(), 'hero');
    expect($media?->remote_url)->toContain('Special:FilePath/K')
        ->and($media?->remote_url)->toContain('Philharmonie_Saal.jpg')
        ->and($media?->attribution)->toBe('Raimond · CC BY-SA 4.0 · Wikimedia Commons')
        ->and($media?->rights_status)->toBe('approved');
});

test('falls back to name-matched geosearch when no wikidata entity verifies', function () {
    $venue = photoVenue(['name' => 'Stadtgarten Konzertsaal']);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'wbsearchentities')) {
            return Http::response(['search' => []]); // nothing on wikidata
        }

        if (str_contains($url, 'geosearch')) {
            // Nearest file is the neighbour's — the name gate must skip it.
            return Http::response(['query' => ['pages' => [
                '1' => ['title' => 'File:Nachbarhaus Fassade.jpg', 'index' => 1, 'imageinfo' => [['mediatype' => 'BITMAP']]],
                '2' => ['title' => 'File:Stadtgarten Konzertsaal Bühne.jpg', 'index' => 2, 'imageinfo' => [['mediatype' => 'BITMAP']]],
            ]]]);
        }

        return Http::response(['query' => ['pages' => [
            '9' => [
                'title' => 'File:Stadtgarten Konzertsaal Bühne.jpg',
                'imageinfo' => [venueCommonsInfo('Foto Fan', 'CC BY 4.0')],
            ],
        ]]]);
    });

    $this->artisan('venues:fetch-photos')->assertSuccessful();

    $media = app(PublishedMediaSelector::class)->select($venue->fresh(), 'hero');
    expect($media?->remote_url)->toContain('Konzertsaal_B');
    expect($media?->attribution)->toBe('Foto Fan · CC BY 4.0 · Wikimedia Commons');
});

test('saves nothing when neither wikidata verifies nor geosearch matches the name', function () {
    $venue = photoVenue(['name' => 'Bürgerzentrum Ehrenfeld']);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'wbsearchentities')) {
            // A same-name entity in another town: has a photo, wrong coords.
            return Http::response(['search' => [['id' => 'Q999']]]);
        }

        if (str_contains($url, 'wbgetentities')) {
            return Http::response(['entities' => [
                'Q999' => ['claims' => [
                    'P18' => [['mainsnak' => ['datavalue' => ['value' => 'Anderes Zentrum.jpg']]]],
                    'P625' => [['mainsnak' => ['datavalue' => ['value' => ['latitude' => 51.2, 'longitude' => 7.1]]]]],
                ]],
            ]]);
        }

        // Geosearch: only unrelated neighbours nearby.
        return Http::response(['query' => ['pages' => [
            '1' => ['title' => 'File:Irgendein Haus.jpg', 'index' => 1, 'imageinfo' => [['mediatype' => 'BITMAP']]],
        ]]]);
    });

    $this->artisan('venues:fetch-photos')->assertSuccessful();

    expect($venue->fresh()->mediaAttachments()->count())->toBe(0);
});

test('a locative phrase in the venue name never matches a neighbouring building', function () {
    // Real prod case: VHS Studienhaus am Neumarkt sits NEXT TO Neumarkt 21 —
    // "Neumarkt" is where the venue is, not what it is, so a photo of the
    // neighbour must not pass the name gate.
    $venue = photoVenue(['name' => 'VHS Studienhaus am Neumarkt']);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'wbsearchentities')) {
            return Http::response(['search' => []]);
        }

        return Http::response(['query' => ['pages' => [
            '1' => ['title' => 'File:Wohn- und Geschäftshaus Neumarkt 21-3850.jpg', 'index' => 1, 'imageinfo' => [['mediatype' => 'BITMAP']]],
        ]]]);
    });

    $this->artisan('venues:fetch-photos')->assertSuccessful();

    expect($venue->fresh()->mediaAttachments()->count())->toBe(0);
});

test('stripping the locative phrase keeps matching on the identity part of the name', function () {
    $venue = photoVenue(['name' => 'Gilden im Zims']);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'wbsearchentities')) {
            return Http::response(['search' => []]);
        }

        if (str_contains($url, 'geosearch')) {
            return Http::response(['query' => ['pages' => [
                '1' => ['title' => 'File:Gilden im Zims Heumarkt 77 Köln.jpg', 'index' => 1, 'imageinfo' => [['mediatype' => 'BITMAP']]],
            ]]]);
        }

        return Http::response(['query' => ['pages' => [
            '9' => [
                'title' => 'File:Gilden im Zims Heumarkt 77 Köln.jpg',
                'imageinfo' => [venueCommonsInfo('Foto Fan', 'CC BY 4.0')],
            ],
        ]]]);
    });

    $this->artisan('venues:fetch-photos')->assertSuccessful();

    $media = app(PublishedMediaSelector::class)->select($venue->fresh(), 'hero');
    expect($media?->remote_url)->toContain('Gilden_im_Zims');
});

test('an event with no own media inherits the venue commons photo through the api', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-12 10:00', 'Europe/Berlin'));
    $this->actingAs(User::factory()->onboarded()->create());

    $venue = photoVenue();
    Event::factory()->create([
        'starts_at' => '2026-06-12 20:00:00',
        'recurrence' => null,
        'venue_id' => $venue->id,
    ]);

    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, 'wbsearchentities')) {
            return Http::response(['search' => [['id' => 'Q472950']]]);
        }
        if (str_contains($url, 'wbgetentities')) {
            return Http::response(['entities' => [
                'Q472950' => ['claims' => [
                    'P18' => [['mainsnak' => ['datavalue' => ['value' => 'Kölner Philharmonie Saal.jpg']]]],
                    'P625' => [['mainsnak' => ['datavalue' => ['value' => ['latitude' => 50.9405, 'longitude' => 6.9610]]]]],
                ]],
            ]]);
        }

        return Http::response(['query' => ['pages' => [
            '1' => [
                'title' => 'File:Kölner Philharmonie Saal.jpg',
                'imageinfo' => [venueCommonsInfo('Raimond', 'CC BY-SA 4.0')],
            ],
        ]]]);
    });

    $this->artisan('venues:fetch-photos')->assertSuccessful();

    $data = $this->getJson('/api/events?window=today')->assertOk()->json('data.0');

    expect($data['photo_url'])->toContain('Philharmonie_Saal.jpg')
        ->and($data['photo_attribution'])->toBe('Raimond · CC BY-SA 4.0 · Wikimedia Commons');
});
