<?php

use App\Jobs\ValidateMediaAssetJob;
use App\Media\CaptureMediaCandidate;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** Build one koeln.de Tribe-API event payload. */
function koelnEvent(string $id, string $title, string $startsAt): array
{
    return [
        'id' => $id,
        'title' => $title,
        'start_date' => $startsAt,
        'url' => 'https://www.koeln.de/event/'.$id,
        'description' => 'A genuinely descriptive blurb about this event, long enough to score.',
        'venue' => ['venue' => 'Bürgerhaus Stollwerck', 'address' => 'Dreikönigenstr. 23'],
        'categories' => [['name' => 'Konzerte']],
    ];
}

test('the koeln.de scraper pages through the whole window, not just the first 50', function () {
    User::factory()->create(['email' => 'system@expadu.com']);
    Queue::fake(); // don't run enrichment/geocode jobs — we only assert ingest

    Http::fake([
        'www.koeln.de/*' => function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return match ((int) ($q['page'] ?? 1)) {
                1 => Http::response(['total_pages' => 2, 'events' => [
                    koelnEvent('p1', 'Tonight Concert', now()->addHours(4)->toDateTimeString()),
                ]]),
                // Page 2 holds the events further out — the weekend that the old
                // single-page fetch never reached.
                2 => Http::response(['total_pages' => 2, 'events' => [
                    koelnEvent('p2', 'Weekend Market', now()->addDays(5)->setTime(11, 0)->toDateTimeString()),
                ]]),
                default => Http::response(['total_pages' => 2, 'events' => []]),
            };
        },
    ]);

    $this->artisan('events:scrape')->assertSuccessful();

    expect(Event::where('source_uid', 'p1')->exists())->toBeTrue()
        ->and(Event::where('source_uid', 'p2')->exists())->toBeTrue();
});

test('the scraper stops at the last page and does not loop forever', function () {
    User::factory()->create(['email' => 'system@expadu.com']);
    Queue::fake();

    $calls = 0;
    Http::fake([
        'www.koeln.de/*' => function ($request) use (&$calls) {
            $calls++;

            // A single page — total_pages = 1 must end the loop immediately.
            return Http::response(['total_pages' => 1, 'events' => [
                koelnEvent('only', 'Solo Event', now()->addHours(3)->toDateTimeString()),
            ]]);
        },
    ]);

    $this->artisan('events:scrape')->assertSuccessful();

    expect($calls)->toBe(1)
        ->and(Event::where('source_uid', 'only')->exists())->toBeTrue();
});

test('the scraper preserves the complete German source description for translation', function () {
    User::factory()->create(['email' => 'system@expadu.com']);
    Queue::fake();
    $description = str_repeat('Vollständiger deutscher Absatz. ', 100);
    $payload = koelnEvent('full-description', 'Langer Quelltext', now()->addHours(3)->toDateTimeString());
    $payload['description'] = $description;

    Http::fake(['www.koeln.de/*' => Http::response(['total_pages' => 1, 'events' => [$payload]])]);

    $this->artisan('events:scrape')->assertSuccessful();

    expect(Event::where('source_uid', 'full-description')->value('description'))->toBe(trim($description));
});

test('the scraper captures koeln.de event images as rights-pending media candidates', function () {
    User::factory()->create(['email' => 'system@expadu.com']);
    Queue::fake();
    $payload = koelnEvent('with-image', 'Event with a poster', now()->addHours(3)->toDateTimeString());
    $payload['image'] = [
        'id' => 4242,
        'url' => 'https://www.koeln.de/wp-content/uploads/event-poster.jpg',
        'width' => 1200,
        'height' => 800,
    ];

    Http::fake(['www.koeln.de/*' => Http::response(['total_pages' => 1, 'events' => [$payload]])]);

    $this->artisan('events:scrape')->assertSuccessful();

    $event = Event::query()->where('source_uid', 'with-image')->sole();
    $asset = MediaAsset::query()->sole();
    $attachment = $event->mediaAttachments()->sole();

    expect($asset->provider)->toBe('koeln-de')
        ->and($asset->provider_asset_id)->toBe('4242')
        ->and($asset->remote_url)->toBe('https://www.koeln.de/wp-content/uploads/event-poster.jpg')
        ->and($asset->source_page_url)->toBe($event->source_url)
        ->and($asset->rights_status)->toBe('pending')
        ->and($asset->width)->toBe(1200)
        ->and($asset->height)->toBe(800)
        ->and($attachment->role)->toBe('poster')
        ->and($attachment->is_primary)->toBeTrue();

    Queue::assertPushed(
        ValidateMediaAssetJob::class,
        fn (ValidateMediaAssetJob $job): bool => $job->asset->is($asset),
    );
});

test('media capture failures never discard a koeln.de event', function () {
    User::factory()->create(['email' => 'system@expadu.com']);
    Queue::fake();
    $capture = Mockery::mock(CaptureMediaCandidate::class);
    $capture->shouldReceive('execute')->once()->andThrow(new RuntimeException('media storage unavailable'));
    app()->instance(CaptureMediaCandidate::class, $capture);
    $payload = koelnEvent('media-failure', 'Still import me', now()->addHours(3)->toDateTimeString());
    $payload['image'] = ['url' => 'https://www.koeln.de/wp-content/uploads/event.jpg'];
    Http::fake(['www.koeln.de/*' => Http::response(['total_pages' => 1, 'events' => [$payload]])]);

    $this->artisan('events:scrape')->assertSuccessful();

    expect(Event::query()->where('source_uid', 'media-failure')->exists())->toBeTrue()
        ->and(MediaAsset::query()->count())->toBe(0);
});

test('distinct source ids with the same title and date are not globally deduplicated', function () {
    User::factory()->create(['email' => 'system@expadu.com']);
    Queue::fake();
    $start = now()->addDay()->toDateTimeString();
    Http::fake(['www.koeln.de/*' => Http::response(['total_pages' => 1, 'events' => [
        koelnEvent('distinct-1', 'Shared title', $start), koelnEvent('distinct-2', 'Shared title', $start),
    ]])]);
    $this->artisan('events:scrape')->assertSuccessful();
    expect(Event::where('title', 'Shared title')->count())->toBe(2);
});
