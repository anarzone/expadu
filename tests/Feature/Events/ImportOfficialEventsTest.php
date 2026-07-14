<?php

use App\Jobs\ProcessEventJob;
use App\Jobs\ValidateMediaAssetJob;
use App\Media\CaptureMediaCandidate;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\CologneServiceArea;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function officialEvent(array $overrides = []): array
{
    return array_replace([
        'link' => 'https://www.stadt-koeln.de/leben-in-koeln/veranstaltungen/daten/40506/index.html',
        'beginndatum' => '2026-07-18',
        'endedatum' => '2026-07-18',
        'title' => 'MINT-Workshop am Sonntag',
        'description' => 'Die Stadtbibliothek bastelt und programmiert mit euch.',
        'uhrzeit' => '14 bis 17 Uhr',
        'veranstaltungsort' => 'Interim Zentralbibliothek',
        'plz' => '50667',
        'ort' => 'Köln',
        'stadtbezirk' => 'Innenstadt',
        'stadtteil' => 'Altstadt/Nord',
        'strasse' => 'Hohe Straße',
        'hausnummer' => '68-82',
        'latitude' => '50.936956',
        'longitude' => ' 6.956424 ',
        'preis' => 'Die Veranstaltung ist kostenlos.',
    ], $overrides);
}

beforeEach(function () {
    User::factory()->create(['email' => 'system@expadu.com']);
    Queue::fake();
});

test('official Cologne events are imported with German source copy and queued for translation', function () {
    $area = Mockery::mock(CologneServiceArea::class);
    $area->shouldReceive('contains')->once()->with(50.936956, 6.956424)->andReturnTrue();
    app()->instance(CologneServiceArea::class, $area);

    Http::fake([
        'www.stadt-koeln.de/externe-dienste/open-data/events-od.php*' => Http::response([
            'success' => true,
            'items' => [officialEvent()],
        ]),
    ]);

    $this->artisan('events:import-official')->assertSuccessful();

    $event = Event::where('source', 'stadt-koeln.de')->where('source_uid', '40506')->sole();

    expect($event->title)->toBe('MINT-Workshop am Sonntag')
        ->and($event->description)->toBe('Die Stadtbibliothek bastelt und programmiert mit euch.')
        ->and($event->source_lang)->toBe('de')
        ->and($event->starts_at->format('Y-m-d H:i'))->toBe('2026-07-18 14:00')
        ->and($event->ends_at?->format('Y-m-d H:i'))->toBe('2026-07-18 17:00')
        ->and($event->address)->toBe('Hohe Straße 68-82, 50667 Köln')
        ->and($event->is_free)->toBeTrue()
        ->and($event->price_text)->toBe('Die Veranstaltung ist kostenlos.')
        ->and($event->lat)->toBe(50.936956)
        ->and($event->lng)->toBe(6.956424);

    Queue::assertPushed(fn (ProcessEventJob $job): bool => $job->event->is($event));
});

test('official event teaser images are captured as rights-pending media candidates', function () {
    app()->instance(CologneServiceArea::class, Mockery::mock(CologneServiceArea::class));
    Http::fake([
        'www.stadt-koeln.de/*' => Http::response([
            'success' => true,
            'items' => [officialEvent([
                'latitude' => null,
                'longitude' => null,
                'teaserbild' => '/mediaasset/bilder/veranstaltungen/mint-workshop.jpg',
            ])],
        ]),
    ]);

    $this->artisan('events:import-official')->assertSuccessful();

    $event = Event::query()->where('source', 'stadt-koeln.de')->sole();
    $asset = MediaAsset::query()->sole();
    $attachment = $event->mediaAttachments()->sole();

    expect($asset->provider)->toBe('stadt-koeln')
        ->and($asset->remote_url)->toBe('https://www.stadt-koeln.de/mediaasset/bilder/veranstaltungen/mint-workshop.jpg')
        ->and($asset->source_page_url)->toBe($event->source_url)
        ->and($asset->rights_status)->toBe('pending')
        ->and($attachment->role)->toBe('poster')
        ->and($attachment->is_primary)->toBeTrue();

    Queue::assertPushed(
        ValidateMediaAssetJob::class,
        fn (ValidateMediaAssetJob $job): bool => $job->asset->is($asset),
    );
});

test('media capture failures never discard an official event', function () {
    app()->instance(CologneServiceArea::class, Mockery::mock(CologneServiceArea::class));
    $capture = Mockery::mock(CaptureMediaCandidate::class);
    $capture->shouldReceive('execute')->once()->andThrow(new RuntimeException('media storage unavailable'));
    app()->instance(CaptureMediaCandidate::class, $capture);
    Http::fake([
        'www.stadt-koeln.de/*' => Http::response([
            'success' => true,
            'items' => [officialEvent([
                'latitude' => null,
                'longitude' => null,
                'teaserbild' => '/mediaasset/poster.jpg',
            ])],
        ]),
    ]);

    $this->artisan('events:import-official')->assertSuccessful();

    expect(Event::query()->where('source_uid', '40506')->exists())->toBeTrue()
        ->and(MediaAsset::query()->count())->toBe(0);
});

test('common single-time syntax gets a conservative default duration', function () {
    app()->instance(CologneServiceArea::class, Mockery::mock(CologneServiceArea::class));

    Http::fake([
        'www.stadt-koeln.de/externe-dienste/open-data/events-od.php*' => Http::response([
            'success' => true,
            'items' => [officialEvent([
                'link' => 'https://www.stadt-koeln.de/leben-in-koeln/veranstaltungen/daten/40570/index.html',
                'uhrzeit' => '11 Uhr',
                'latitude' => null,
                'longitude' => null,
            ])],
        ]),
    ]);

    $this->artisan('events:import-official')->assertSuccessful();

    $event = Event::where('source_uid', '40570')->sole();
    expect($event->starts_at->format('H:i'))->toBe('11:00')
        ->and($event->ends_at?->format('H:i'))->toBe('13:00');
});

test('official records upsert without overwriting another source and changed copy is retranslated', function () {
    $other = Event::factory()->create([
        'source' => 'koeln.de',
        'source_uid' => '40506',
        'title' => 'Keep this source',
    ]);
    app()->instance(CologneServiceArea::class, Mockery::mock(CologneServiceArea::class));

    Http::fakeSequence('www.stadt-koeln.de/externe-dienste/open-data/events-od.php*')
        ->push(['success' => true, 'items' => [officialEvent(['latitude' => null, 'longitude' => null])]])
        ->push(['success' => true, 'items' => [officialEvent([
            'title' => 'Geänderter deutscher Titel',
            'description' => 'Aktualisierte deutsche Beschreibung.',
            'latitude' => null,
            'longitude' => null,
        ])]]);

    $this->artisan('events:import-official')->assertSuccessful();
    $official = Event::where('source', 'stadt-koeln.de')->sole();
    $official->update(['title_en' => 'Old translation', 'description_en' => 'Old description', 'summary_en' => 'Old summary']);
    Queue::fake();

    $this->artisan('events:import-official')->assertSuccessful();

    expect($official->fresh()->title)->toBe('Geänderter deutscher Titel')
        ->and($official->fresh()->title_en)->toBeNull()
        ->and($official->fresh()->description_en)->toBeNull()
        ->and($other->fresh()->title)->toBe('Keep this source');
    Queue::assertPushed(ProcessEventJob::class, 1);
});

test('coordinates outside Cologne are discarded for later address enrichment', function () {
    $area = Mockery::mock(CologneServiceArea::class);
    $area->shouldReceive('contains')->once()->andReturnFalse();
    app()->instance(CologneServiceArea::class, $area);

    Http::fake([
        'www.stadt-koeln.de/externe-dienste/open-data/events-od.php*' => Http::response([
            'success' => true,
            'items' => [officialEvent(['latitude' => '52.5364431', 'longitude' => '13.2015024'])],
        ]),
    ]);

    $this->artisan('events:import-official')->assertSuccessful();

    expect(Event::where('source', 'stadt-koeln.de')->sole()->location)->toBeNull();
});

test('an invalid official payload fails safely without changing existing events', function () {
    $existing = Event::factory()->create(['source' => 'stadt-koeln.de', 'source_uid' => 'existing']);
    Http::fake([
        'www.stadt-koeln.de/externe-dienste/open-data/events-od.php*' => Http::response([
            'success' => true,
            'items' => 'not-an-array',
        ]),
    ]);

    $this->artisan('events:import-official')->assertFailed();

    expect(Event::where('source', 'stadt-koeln.de')->count())->toBe(1)
        ->and($existing->fresh())->not->toBeNull();
    Queue::assertNothingPushed();
});

test('a successful complete feed hides official records no longer returned without touching other sources', function () {
    Event::factory()->create(['source' => 'stadt-koeln.de', 'source_uid' => 'removed', 'status' => 'active']);
    $other = Event::factory()->create(['source' => 'koeln.de', 'source_uid' => 'removed', 'status' => 'active']);
    app()->instance(CologneServiceArea::class, Mockery::mock(CologneServiceArea::class));
    Http::fake(['www.stadt-koeln.de/*' => Http::response(['success' => true, 'items' => [officialEvent(['latitude' => null, 'longitude' => null])]])]);

    $this->artisan('events:import-official')->assertSuccessful();

    expect(Event::where('source', 'stadt-koeln.de')->where('source_uid', 'removed')->value('status'))->toBe('hidden')
        ->and($other->fresh()->status)->toBe('active');
});

test('changed or missing official coordinates clear stale location and preserve enrichment tags', function () {
    $event = Event::factory()->create([
        'source' => 'stadt-koeln.de', 'source_uid' => '40506', 'tags' => ['weekend', 'official-city', 'district:Old'],
        'location_name' => 'Old venue', 'address' => 'Old address', 'venue_id' => null,
    ]);
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(6.95, 50.94), 4326)::geography WHERE id = ?', [$event->id]);
    app()->instance(CologneServiceArea::class, Mockery::mock(CologneServiceArea::class));
    Http::fake(['www.stadt-koeln.de/*' => Http::response(['success' => true, 'items' => [officialEvent(['latitude' => null, 'longitude' => null])]])]);

    $this->artisan('events:import-official')->assertSuccessful();

    $event->refresh();
    expect($event->lat)->toBeNull()
        ->and($event->needs_review)->toBeTrue()
        ->and($event->tags)->toContain('weekend', 'district:Innenstadt')
        ->and($event->tags)->not->toContain('district:Old');
});

test('multi-day rows with daily hours become daily occurrences instead of one fake continuous event', function () {
    app()->instance(CologneServiceArea::class, Mockery::mock(CologneServiceArea::class));
    Http::fake(['www.stadt-koeln.de/*' => Http::response(['success' => true, 'items' => [officialEvent([
        'beginndatum' => '2026-07-18', 'endedatum' => '2026-07-20', 'latitude' => null, 'longitude' => null,
    ])]])]);

    $this->artisan('events:import-official')->assertSuccessful();
    $event = Event::where('source', 'stadt-koeln.de')->sole();

    expect($event->starts_at->format('Y-m-d H:i'))->toBe('2026-07-18 14:00')
        ->and($event->ends_at?->format('Y-m-d H:i'))->toBe('2026-07-18 17:00')
        ->and($event->recurrence)->toBe('FREQ=DAILY')
        ->and($event->recurrence_until?->format('Y-m-d'))->toBe('2026-07-20')
        ->and($event->tags)->toContain('multi-day');
});

test('overflowing calendar dates are rejected rather than normalized', function () {
    Http::fake(['www.stadt-koeln.de/*' => Http::response(['success' => true, 'items' => [officialEvent(['beginndatum' => '2026-02-31'])]])]);
    $this->artisan('events:import-official')->assertSuccessful();
    expect(Event::where('source', 'stadt-koeln.de')->count())->toBe(0);
});

test('multi-day rows without daily hours are marked uncertain for Composer exclusion', function () {
    app()->instance(CologneServiceArea::class, Mockery::mock(CologneServiceArea::class));
    Http::fake(['www.stadt-koeln.de/*' => Http::response(['success' => true, 'items' => [officialEvent([
        'endedatum' => '2026-07-20', 'uhrzeit' => '', 'latitude' => null, 'longitude' => null,
    ])]])]);
    $this->artisan('events:import-official')->assertSuccessful();
    expect(Event::where('source', 'stadt-koeln.de')->sole()->tags)->toContain('multi-day-uncertain');
});
