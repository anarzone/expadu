<?php

use App\Jobs\ProcessEventJob;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\CologneServiceArea;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function datenHubItem(array $overrides = []): array
{
    return array_replace([
        'global_id' => 'e_101058716',
        'title' => 'Grusel im Alten Köln',
        'categories' => ['Führung/Besichtigung'],
        'city' => 'Köln',
        'zip' => '50667',
        'street' => 'Alter Markt 36-42',
        'name' => 'TimeRide Köln',
        'web' => 'https://timeride.de/koeln/grusel-im-alten-koeln/',
        'geo' => ['main' => ['latitude' => 50.9385252, 'longitude' => 6.9603862]],
        'texts' => [['rel' => 'details', 'type' => 'text/html', 'value' => '<p>Kölns düstere Geschichte als Zeitreise.</p>']],
        'numbers' => [['type' => 'PRICE_ADULT', 'value' => 19.0]],
        'features' => ['Deutsch'],
        'timeIntervals' => [
            ['weekdays' => ['Friday', 'Saturday'], 'start' => '2026-10-06T18:40:00+02:00', 'end' => '2026-10-06T22:30:00+02:00', 'repeatUntil' => '2026-11-29T22:30:00+01:00', 'tz' => 'Europe/Berlin', 'freq' => 'Weekly', 'interval' => 1],
            ['weekdays' => [], 'start' => '2026-11-13T18:00:00+01:00', 'end' => '2026-11-13T21:30:00+01:00', 'tz' => 'Europe/Berlin'],
            ['weekdays' => [], 'start' => '2025-11-23T18:00:00+01:00', 'end' => '2025-11-23T21:30:00+01:00', 'tz' => 'Europe/Berlin'],
            ['weekdays' => [], 'start' => '2026-11-28T12:00:00+01:00', 'end' => '2026-11-28T13:30:00+01:00', 'repeatUntil' => '2027-01-31T13:30:00+01:00', 'tz' => 'Europe/Berlin', 'freq' => 'Monthly', 'dayOrdinal' => 4, 'weekday' => 'Saturday', 'interval' => 1],
        ],
        'media_objects' => [
            ['rel' => 'booking', 'url' => 'https://timeride.de/koeln/tickets/'],
            ['rel' => 'default', 'type' => 'image/jpeg', 'url' => 'https://dam.destination.one/3327578/abc/timeride.jpg', 'source' => 'KölnTourismus, Foto: Christoph Seelbach', 'license' => 'CC-BY-SA', 'width' => 2560, 'height' => 1707, 'id' => 'm_3327578'],
        ],
    ], $overrides);
}

/** One feed response per run, in order. */
function fakeDatenHub(array ...$runs): void
{
    $sequence = Http::fakeSequence('meta.et4.de/*');
    foreach ($runs as $items) {
        $sequence->push(['status' => 'OK', 'count' => count($items), 'items' => $items]);
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00', 'Europe/Berlin'));
    User::factory()->create(['email' => 'system@expadu.com']);
    Queue::fake();
    $area = Mockery::mock(CologneServiceArea::class);
    $area->shouldReceive('contains')->andReturnTrue();
    app()->instance(CologneServiceArea::class, $area);
});

test('a listing becomes its series and dates, with its open-licensed photo approved and credited', function () {
    fakeDatenHub([datenHubItem()]);

    $this->artisan('events:import-datenhub')->assertSuccessful();

    $events = Event::where('source', 'datenhub-nrw')->orderBy('source_uid')->get()->keyBy('source_uid');
    // The weekly run, the coming date and the 4th Saturdays inside 60 days; the past date is left out.
    expect($events->keys()->all())->toBe(['e_101058716#0', 'e_101058716#1', 'e_101058716#3@2026-11-28', 'e_101058716#3@2026-12-26']);

    $series = $events['e_101058716#0'];
    expect($series->recurrence)->toBe('FREQ=WEEKLY;BYDAY=FR,SA')
        ->and($series->recurrence_until->toDateString())->toBe('2026-11-29')
        ->and($series->description)->toBe('Kölns düstere Geschichte als Zeitreise.')
        ->and($series->location_name)->toBe('TimeRide Köln')
        ->and($series->address)->toBe('Alter Markt 36-42, 50667 Köln')
        ->and((float) $series->price)->toBe(19.0)
        ->and($series->price_text)->toBe('19,00 €')
        ->and($series->category)->toBe('culture')
        ->and($series->source_url)->toBe('https://timeride.de/koeln/grusel-im-alten-koeln/');

    $photo = MediaAsset::where('provider', 'koeln-tourismus')->sole();
    expect($photo->rights_status)->toBe('approved')
        ->and($photo->attribution)->toBe('KölnTourismus, Foto: Christoph Seelbach · CC BY-SA 4.0')
        ->and($photo->author)->toBe('Christoph Seelbach')
        ->and($photo->license_url)->toBe('https://creativecommons.org/licenses/by-sa/4.0/');

    Queue::assertPushed(ProcessEventJob::class, 4);
});

test('a listing koeln.de already has is not added twice; that listing gets the photo', function () {
    $koeln = Event::factory()->create([
        'source' => 'koeln.de', 'title' => 'Grusel im alten Köln!', 'status' => 'active',
        'starts_at' => CarbonImmutable::parse('2026-11-13 18:00', 'Europe/Berlin'),
    ]);
    fakeDatenHub([datenHubItem(['timeIntervals' => [datenHubItem()['timeIntervals'][1]]])]);

    $this->artisan('events:import-datenhub')->assertSuccessful();

    expect(Event::where('source', 'datenhub-nrw')->count())->toBe(0)
        ->and($koeln->mediaAttachments()->sole()->mediaAsset->provider)->toBe('koeln-tourismus');
});

test('a photo without an open licence is left out, and a listing the feed drops is hidden', function () {
    $item = datenHubItem();
    $item['media_objects'][1]['license'] = 'All rights reserved';
    fakeDatenHub([$item], [datenHubItem(['global_id' => 'e_other', 'timeIntervals' => [datenHubItem()['timeIntervals'][1]]])]);
    $this->artisan('events:import-datenhub')->assertSuccessful();
    expect(MediaAsset::count())->toBe(0);

    $this->artisan('events:import-datenhub')->assertSuccessful();

    expect(Event::where('source_uid', 'like', 'e_101058716#%')->pluck('status')->unique()->all())->toBe(['hidden'])
        ->and(Event::where('source_uid', 'e_other#0')->value('status'))->toBe('active');
});

test('an invalid payload changes nothing', function () {
    Event::factory()->create(['source' => 'datenhub-nrw', 'source_uid' => 'e_1#0', 'status' => 'active']);
    Http::fake(['meta.et4.de/*' => Http::response(['status' => 'ERROR'])]);

    $this->artisan('events:import-datenhub')->assertFailed();

    expect(Event::where('source_uid', 'e_1#0')->value('status'))->toBe('active');
});

test('attractions stay out: exhibitions, markets, all-year opening hours and whole days; Christmas, festivals and winter runs stay', function () {
    $daily = fn (string $start, string $end, string $until) => ['weekdays' => [], 'start' => $start, 'end' => $end, 'repeatUntil' => $until, 'tz' => 'Europe/Berlin', 'freq' => 'Daily', 'interval' => 1];
    fakeDatenHub([
        datenHubItem(['global_id' => 'e_exhibition', 'title' => 'Faszination Schmuck', 'categories' => ['Ausstellung'], 'timeIntervals' => [datenHubItem()['timeIntervals'][1]]]),
        datenHubItem(['global_id' => 'e_market', 'title' => 'Wochenmarkt auf dem Neptunplatz', 'categories' => [''], 'timeIntervals' => [datenHubItem()['timeIntervals'][1]]]),
        datenHubItem(['global_id' => 'e_allyear', 'title' => 'Discover Deutz', 'categories' => ['Führung/Besichtigung'], 'timeIntervals' => [$daily('2026-01-01T10:00:00+01:00', '2026-01-01T18:00:00+01:00', '2027-01-03T18:00:00+01:00')]]),
        datenHubItem(['global_id' => 'e_wholeday', 'title' => 'Design Season Cologne', 'categories' => [''], 'timeIntervals' => [['weekdays' => [], 'start' => '2026-10-28T00:00:00+01:00', 'end' => '2026-10-29T00:00:00+01:00', 'tz' => 'Europe/Berlin']]]),
        datenHubItem(['global_id' => 'e_xmas', 'title' => 'Weihnachtsmarkt am Kölner Dom', 'categories' => ['Weihnachtsmarkt'], 'timeIntervals' => [$daily('2026-11-23T11:00:00+01:00', '2026-11-23T21:00:00+01:00', '2026-12-23T21:00:00+01:00')]]),
        // A festival keeps its days with no time; a winter light trail keeps its season, exhibition or not.
        datenHubItem(['global_id' => 'e_festival', 'title' => 'Kurzfilmfestival Köln', 'categories' => ['Film'], 'timeIntervals' => [$daily('2026-11-23T00:00:00+01:00', '2026-11-24T00:00:00+01:00', '2026-11-29T00:00:00+01:00')]]),
        datenHubItem(['global_id' => 'e_lights', 'title' => 'China Lights', 'categories' => ['Ausstellung'], 'timeIntervals' => [$daily('2026-12-11T17:30:00+01:00', '2026-12-11T21:00:00+01:00', '2027-01-31T21:00:00+01:00')]]),
    ]);

    $this->artisan('events:import-datenhub')->expectsOutputToContain('"not_events":2')->assertSuccessful();

    expect(Event::where('source', 'datenhub-nrw')->orderBy('source_uid')->pluck('source_uid')->all())->toBe(['e_festival#0', 'e_lights#0', 'e_xmas#0'])
        ->and(Event::where('source_uid', 'e_festival#0')->value('ends_at')->format('H:i:s'))->toBe('23:59:59');
});
