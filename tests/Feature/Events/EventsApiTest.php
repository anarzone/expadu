<?php

use App\Enums\TransportMode;
use App\Models\Event;
use App\Models\EventReminder;
use App\Models\Spot;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\EventOccurrenceReminder;
use App\Services\UserLocationService;
use App\Transit\TravelTimes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-12 10:00', 'Europe/Berlin')); // a Friday
    $this->actingAs(User::factory()->onboarded()->create());
});

function makeVenue(array $attrs = []): Venue
{
    return Venue::create(array_merge([
        'name' => 'Gilden im Zims',
        'address_text' => 'Heumarkt 77',
        'lat' => 50.9358,
        'lng' => 6.9601,
        'veedel' => 'Altstadt-Nord',
    ], $attrs));
}

test('today window returns chronological occurrences with server-built meta', function () {
    $venue = makeVenue();
    Event::factory()->create([
        'title' => 'Sprachabend', 'title_en' => 'Language night',
        'starts_at' => '2026-06-12 19:00:00', 'ends_at' => '2026-06-12 22:00:00',
        'recurrence' => null,
        'category' => 'language_exchange', 'chips' => ['English-friendly'],
        'is_free' => true, 'venue_id' => $venue->id, 'summary_en' => 'Two short sentences.',
    ]);
    Event::factory()->create([
        'title' => 'Morning run', 'starts_at' => '2026-06-12 11:00:00',
        'recurrence' => null, 'category' => 'sports', 'venue_id' => $venue->id,
    ]);
    Event::factory()->create(['starts_at' => '2026-06-14 11:00:00', 'recurrence' => null]); // outside today

    $data = $this->getJson('/api/events?window=today')->assertOk()->json('data');

    expect($data)->toHaveCount(2);
    expect($data[0]['title'])->toBe('Morning run');
    expect($data[1]['title'])->toBe('Language night');
    expect($data[1]['meta'])->toBe('Tonight 19:00–22:00 · Gilden im Zims · Altstadt-Nord');
    expect($data[1]['chips'])->toBe(['English-friendly', 'free']); // free chip derived
    expect($data[1]['category'])->toBe('language_exchange');
});

test('nearest events are ordered from the explicit live origin and expose distance', function () {
    $near = makeVenue(['name' => 'Niehl venue', 'lat' => 50.9955, 'lng' => 6.9550, 'veedel' => 'Niehl']);
    $far = makeVenue(['name' => 'Sülz venue', 'lat' => 50.9220, 'lng' => 6.9360, 'veedel' => 'Sülz']);

    Event::factory()->create([
        'title' => 'Far but sooner', 'starts_at' => '2026-06-12 11:00:00',
        'recurrence' => null, 'venue_id' => $far->id,
    ]);
    Event::factory()->create([
        'title' => 'Near but later', 'starts_at' => '2026-06-12 12:00:00',
        'recurrence' => null, 'venue_id' => $near->id,
    ]);

    $data = $this->getJson('/api/events?window=today&sort=nearest&lat=50.9950&lng=6.9550')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(2)
        ->and($data[0]['title'])->toBe('Near but later')
        ->and($data[0]['distance_km'])->toBeLessThan(0.1)
        ->and($data[1]['title'])->toBe('Far but sooner')
        ->and($data[1]['distance_km'])->toBeGreaterThan(5.0);
});

test('events use the canonical stored origin and selected mode for travel times', function () {
    $user = auth()->user();
    app(UserLocationService::class)->confirm($user, 50.9950, 6.9550, 'Niehl Nord');

    $near = makeVenue(['name' => 'Niehl venue', 'lat' => 50.9955, 'lng' => 6.9550, 'veedel' => 'Niehl']);
    $far = makeVenue(['name' => 'Sülz venue', 'lat' => 50.9220, 'lng' => 6.9360, 'veedel' => 'Sülz']);
    Event::factory()->create(['title' => 'Near', 'starts_at' => '2026-06-12 12:00:00', 'recurrence' => null, 'venue_id' => $near->id]);
    Event::factory()->create(['title' => 'Far', 'starts_at' => '2026-06-12 11:00:00', 'recurrence' => null, 'venue_id' => $far->id]);

    $this->mock(TravelTimes::class, function ($mock): void {
        $mock->shouldReceive('minutes')
            ->once()
            ->withArgs(fn ($mode, $origin, $destinations): bool => $mode === TransportMode::Bike
                && $origin->lat === 50.995
                && count($destinations) === 2)
            ->andReturn([19, 4]);
    });

    $response = $this->getJson('/api/events?window=today&sort=nearest&mode=bike')->assertOk();

    expect($response->json('needs_location'))->toBeFalse()
        ->and($response->json('origin.label'))->toBe('Niehl Nord')
        ->and($response->json('origin'))->not->toHaveKeys(['lat', 'lng'])
        ->and($response->json('data.0.title'))->toBe('Near')
        ->and($response->json('data.0.travel_min'))->toBe(4)
        ->and($response->json('data.1.title'))->toBe('Far')
        ->and($response->json('data.1.travel_min'))->toBe(19);
});

test('nearest events request reports when an origin is required', function () {
    Event::factory()->create(['starts_at' => '2026-06-12 12:00:00', 'recurrence' => null]);

    $this->getJson('/api/events?window=today&sort=nearest')
        ->assertOk()
        ->assertJsonPath('needs_location', true)
        ->assertJsonPath('origin.source', 'none')
        ->assertJsonPath('data.0.distance_km', null)
        ->assertJsonPath('data.0.travel_min', null);
});

test('recommended sorting prioritises LLM relevance before quality fallback', function () {
    Event::factory()->create([
        'title' => 'LLM relevant', 'starts_at' => '2026-06-12 12:00:00',
        'recurrence' => null, 'relevance' => 0.8, 'quality_score' => 0.5,
    ]);
    Event::factory()->create([
        'title' => 'Only structurally complete', 'starts_at' => '2026-06-12 11:00:00',
        'recurrence' => null, 'relevance' => null, 'quality_score' => 0.95,
    ]);

    $data = $this->getJson('/api/events?window=today&sort=recommended')->assertOk()->json('data');

    expect($data[0]['title'])->toBe('LLM relevant')
        ->and($data[1]['title'])->toBe('Only structurally complete');
});

test('transit cards do not expose cycling matrix times as transit times', function () {
    $venue = makeVenue();
    Event::factory()->create(['starts_at' => '2026-06-12 12:00:00', 'recurrence' => null, 'venue_id' => $venue->id]);

    $this->mock(TravelTimes::class, function ($mock): void {
        $mock->shouldNotReceive('minutes');
    });

    $this->getJson('/api/events?window=today&mode=transit&lat=50.9350&lng=6.9600')
        ->assertOk()
        ->assertJsonPath('data.0.travel_min', null);
});

test('travel matrix destinations are deduplicated', function () {
    $venue = makeVenue();
    Event::factory()->count(3)->create([
        'starts_at' => '2026-06-12 12:00:00', 'recurrence' => null, 'venue_id' => $venue->id,
    ]);

    $this->mock(TravelTimes::class, function ($mock): void {
        $mock->shouldReceive('minutes')
            ->once()
            ->withArgs(fn ($mode, $origin, $destinations): bool => count($destinations) === 1)
            ->andReturn([6]);
    });

    $data = $this->getJson('/api/events?window=today&mode=walk&lat=50.9350&lng=6.9600')
        ->assertOk()
        ->json('data');

    expect(collect($data)->pluck('travel_min')->all())->toBe([6, 6, 6]);
});

test('placeholder venue text is never shown as a venue', function () {
    Event::factory()->create([
        'starts_at' => '2026-06-12 21:00:00', 'recurrence' => null,
        'location_name' => 'Siehe Beschreibung', 'venue_id' => null,
    ]);

    $data = $this->getJson('/api/events?window=today')->assertOk()->json('data');

    expect($data[0]['venue']['name'])->toBeNull();
    expect($data[0]['meta'])->not->toContain('Siehe');
});

test('the venue\'s place photo richens the card', function () {
    $place = Spot::factory()->create([
        'name' => 'Volksgarten', 'category' => 'park', 'lat' => 50.9214, 'lng' => 6.9466,
        'photo_url' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Volksgarten.jpg?width=800',
        'photo_attribution' => 'Jane Doe · CC BY-SA 4.0 · Wikimedia Commons',
    ]);
    $venue = makeVenue(['name' => 'Volksgarten', 'place_id' => $place->id]);
    Event::factory()->create(['starts_at' => '2026-06-12 12:00:00', 'recurrence' => null, 'venue_id' => $venue->id]);

    $data = $this->getJson('/api/events?window=today')->assertOk()->json('data');

    expect($data[0]['photo_url'])->toContain('Volksgarten.jpg');
    expect($data[0]['photo_attribution'])->toContain('CC BY-SA');
});

test('weekend window catches a weekly recurring stammtisch', function () {
    Event::factory()->create([
        'title' => 'Parkrun', 'starts_at' => '2026-06-06 09:00:00', // past Saturday = DTSTART
        'recurrence' => 'FREQ=WEEKLY;BYDAY=SA', 'category' => 'sports',
    ]);

    $data = $this->getJson('/api/events?window=weekend')->assertOk()->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['occurrence_start'])->toContain('2026-06-13T09:00');
    expect($data[0]['is_recurring'])->toBeTrue();
    expect($data[0]['recurrence_text'])->toBe('Every Saturday');
});

test('category, veedel and free filters compose', function () {
    $ehrenfeld = makeVenue(['name' => 'BüZE', 'veedel' => 'Ehrenfeld']);
    $altstadt = makeVenue(['name' => 'Früh', 'veedel' => 'Altstadt-Nord']);

    Event::factory()->create(['title' => 'Match A', 'starts_at' => '2026-06-12 18:00:00', 'recurrence' => null, 'category' => 'language', 'is_free' => true, 'venue_id' => $ehrenfeld->id]);
    Event::factory()->create(['title' => 'Wrong veedel', 'starts_at' => '2026-06-12 18:30:00', 'recurrence' => null, 'category' => 'language_exchange', 'is_free' => true, 'venue_id' => $altstadt->id]);
    Event::factory()->create(['title' => 'Not free', 'starts_at' => '2026-06-12 19:00:00', 'recurrence' => null, 'category' => 'language_exchange', 'is_free' => false, 'price_text' => '€10', 'venue_id' => $ehrenfeld->id]);
    Event::factory()->create(['title' => 'Wrong category', 'starts_at' => '2026-06-12 20:00:00', 'recurrence' => null, 'category' => 'party', 'is_free' => true, 'venue_id' => $ehrenfeld->id]);

    $data = $this->getJson('/api/events?window=today&category=language_exchange&veedel=Ehrenfeld&free=1')
        ->assertOk()->json('data');

    // legacy 'language' rows match the language_exchange filter
    expect($data)->toHaveCount(1);
    expect($data[0]['title'])->toBe('Match A');
});

test('the read path makes zero AI calls', function () {
    Http::fake();
    Event::factory()->create(['starts_at' => '2026-06-12 18:00:00', 'recurrence' => null]);

    $this->getJson('/api/events?window=today')->assertOk();

    Http::assertNothingSent();
});

test('a place lists its events for the strip', function () {
    $place = Spot::factory()->create(['name' => 'Stadtgarten', 'category' => 'park', 'lat' => 50.9421, 'lng' => 6.9358]);
    $venue = makeVenue(['name' => 'Stadtgarten', 'place_id' => $place->id]);
    Event::factory()->create(['title' => 'Concert', 'starts_at' => '2026-06-14 20:00:00', 'recurrence' => null, 'venue_id' => $venue->id]);
    Event::factory()->create(['title' => 'Elsewhere', 'starts_at' => '2026-06-14 20:00:00', 'recurrence' => null]);

    $response = $this->getJson("/api/places/{$place->id}/events")->assertOk();

    expect($response->json('count'))->toBe(1);
    expect($response->json('data.0.title'))->toBe('Concert');
    expect($response->json('data.0.venue.place_id'))->toBe($place->id);
});

test('reminders can be set, listed and removed per occurrence', function () {
    $event = Event::factory()->create(['starts_at' => '2026-06-12 19:00:00', 'recurrence' => null]);

    $this->postJson('/api/reminders', [
        'event_id' => $event->id,
        'occurrence_start' => '2026-06-12T19:00:00+02:00',
        'offset' => '1h',
    ])->assertOk();

    expect(EventReminder::count())->toBe(1);
    expect(EventReminder::first()->remind_at->toIso8601String())->toContain('18:00');

    $list = $this->getJson('/api/reminders')->assertOk()->json('data');
    expect($list)->toHaveCount(1);
    expect($list[0]['event_id'])->toBe($event->id);

    $this->deleteJson("/api/reminders/{$event->id}", [
        'occurrence_start' => '2026-06-12T19:00:00+02:00',
    ])->assertOk();

    expect(EventReminder::count())->toBe(0);
});

test('a morning reminder for a pre-8:00 event falls back to one hour before', function () {
    $event = Event::factory()->create(['starts_at' => '2026-06-13 07:00:00', 'recurrence' => null]);

    $this->postJson('/api/reminders', [
        'event_id' => $event->id,
        'occurrence_start' => '2026-06-13T07:00:00+02:00',
        'offset' => 'morning',
    ])->assertOk();

    // 08:00 would be AFTER the start and never fire — clamped to 06:00
    expect(EventReminder::first()->remind_at->format('H:i'))->toBe('06:00');
});

test('due reminders are delivered once through the notification channel', function () {
    Notification::fake();

    $event = Event::factory()->create(['starts_at' => '2026-06-12 11:30:00', 'recurrence' => null]);
    $reminder = EventReminder::create([
        'user_id' => auth()->id(),
        'event_id' => $event->id,
        'occurrence_start' => '2026-06-12 11:30:00',
        'offset_minutes' => 60,
        'remind_at' => '2026-06-12 09:55:00', // already due at the frozen 10:00
    ]);

    $this->artisan('events:send-occurrence-reminders')->assertSuccessful();
    $this->artisan('events:send-occurrence-reminders')->assertSuccessful(); // idempotent

    Notification::assertSentToTimes(
        auth()->user(),
        EventOccurrenceReminder::class,
        1,
    );
    expect($reminder->refresh()->sent_at)->not->toBeNull();
});
