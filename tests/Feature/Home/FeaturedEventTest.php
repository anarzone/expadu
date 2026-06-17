<?php

use App\Home\FeaturedEvent;
use App\Models\Event;
use App\Models\Spot;
use App\Models\Venue;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-12 10:00', 'Europe/Berlin')); // Friday
});

function featuredVenue(array $attrs = []): Venue
{
    return Venue::create(array_merge([
        'name' => 'Gilden im Zims',
        'address_text' => 'Heumarkt 77',
        'lat' => 50.9358,
        'lng' => 6.9601,
        'veedel' => 'Altstadt-Nord',
    ], $attrs));
}

it('returns null when nothing is on today', function () {
    Event::factory()->create([ // tomorrow only
        'starts_at' => '2026-06-13 19:00:00', 'ends_at' => '2026-06-13 21:00:00', 'recurrence' => null,
    ]);

    expect(app(FeaturedEvent::class)->forToday())->toBeNull();
});

it('features a catchable event today, shaped like an /api/events occurrence', function () {
    $venue = featuredVenue();
    $event = Event::factory()->create([
        'title' => 'Sprachabend', 'starts_at' => '2026-06-12 19:00:00', 'ends_at' => '2026-06-12 22:00:00',
        'recurrence' => null, 'category' => 'language_exchange', 'venue_id' => $venue->id,
    ]);

    $featured = app(FeaturedEvent::class)->forToday();

    expect($featured)->not->toBeNull()
        ->and($featured['id'])->toBe($event->id)
        ->and($featured)->toHaveKeys(['occurrence_start', 'title', 'venue', 'meta', 'chips'])
        ->and($featured['meta'])->toContain('Gilden im Zims');
});

it('only draws from the curated (visible) set', function () {
    // status active but relevance below threshold and not curated → not visible()
    Event::factory()->create([
        'title' => 'Low-quality scrape', 'starts_at' => '2026-06-12 12:00:00', 'ends_at' => '2026-06-12 13:00:00',
        'recurrence' => null, 'relevance' => 0.1, 'is_curated' => false,
    ]);

    expect(app(FeaturedEvent::class)->forToday())->toBeNull();
});

it('prefers the event with a photo over a sooner one without', function () {
    Event::factory()->create([ // sooner, no photo
        'title' => 'No photo', 'starts_at' => '2026-06-12 18:00:00', 'ends_at' => '2026-06-12 20:00:00', 'recurrence' => null,
    ]);

    $place = Spot::factory()->create([
        'name' => 'Volksgarten', 'category' => 'park', 'lat' => 50.9214, 'lng' => 6.9466,
        'photo_url' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Volksgarten.jpg?width=800',
        'photo_attribution' => 'Jane Doe · CC BY-SA 4.0',
    ]);
    $venue = featuredVenue(['name' => 'Volksgarten', 'place_id' => $place->id]);
    $withPhoto = Event::factory()->create([ // later, but has a photo
        'title' => 'Has photo', 'starts_at' => '2026-06-12 20:00:00', 'ends_at' => '2026-06-12 22:00:00',
        'recurrence' => null, 'venue_id' => $venue->id,
    ]);

    $featured = app(FeaturedEvent::class)->forToday();

    expect($featured['id'])->toBe($withPhoto->id)
        ->and($featured['photo_url'])->toContain('Volksgarten.jpg');
});

it('skips an event that has already ended but keeps one still upcoming', function () {
    Event::factory()->create([ // 08:00–09:00, over by the frozen 10:00
        'title' => 'Over', 'starts_at' => '2026-06-12 08:00:00', 'ends_at' => '2026-06-12 09:00:00', 'recurrence' => null,
    ]);
    $live = Event::factory()->create([
        'title' => 'Upcoming', 'starts_at' => '2026-06-12 19:00:00', 'ends_at' => '2026-06-12 21:00:00', 'recurrence' => null,
    ]);

    expect(app(FeaturedEvent::class)->forToday()['id'])->toBe($live->id);
});

it('keeps an event that is currently ongoing', function () {
    $ongoing = Event::factory()->create([ // 09:00–18:00, in progress at 10:00
        'title' => 'All-day expo', 'starts_at' => '2026-06-12 09:00:00', 'ends_at' => '2026-06-12 18:00:00', 'recurrence' => null,
    ]);

    expect(app(FeaturedEvent::class)->forToday()['id'])->toBe($ongoing->id);
});
