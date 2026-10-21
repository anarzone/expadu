<?php

use App\Models\Event;
use App\Support\EventOccurrencePresenter;
use Carbon\CarbonImmutable;

function window(string $from, string $to): array
{
    return [
        CarbonImmutable::parse($from, 'Europe/Berlin'),
        CarbonImmutable::parse($to, 'Europe/Berlin'),
    ];
}

test('expands a weekly RRULE into one occurrence per week', function () {
    // Thursday 19:00 stammtisch, weekly
    $event = Event::factory()->create([
        'starts_at' => '2026-06-04 19:00:00', // a Thursday
        'ends_at' => '2026-06-04 21:00:00',
        'recurrence' => 'FREQ=WEEKLY;BYDAY=TH',
    ]);

    [$from, $to] = window('2026-06-08 00:00', '2026-06-21 23:59');
    $occurrences = Event::occurringBetween($from, $to);

    expect($occurrences)->toHaveCount(2);
    expect($occurrences[0]['starts_at']->format('Y-m-d H:i'))->toBe('2026-06-11 19:00');
    expect($occurrences[1]['starts_at']->format('Y-m-d H:i'))->toBe('2026-06-18 19:00');
    // duration carried onto each occurrence
    expect($occurrences[0]['ends_at']->format('H:i'))->toBe('21:00');
    expect($occurrences[0]['event']->id)->toBe($event->id);
});

test('respects INTERVAL and recurrence_until', function () {
    Event::factory()->create([
        'title' => 'Biweekly meetup',
        'starts_at' => '2026-06-01 18:00:00', // a Monday
        'recurrence' => 'FREQ=WEEKLY;BYDAY=MO;INTERVAL=2',
        'recurrence_until' => '2026-06-16 00:00:00',
    ]);

    [$from, $to] = window('2026-06-01 00:00', '2026-06-30 23:59');
    $occurrences = Event::occurringBetween($from, $to);

    // June 1, June 15 — June 29 is past recurrence_until
    expect($occurrences->pluck('starts_at')->map->format('Y-m-d')->all())
        ->toBe(['2026-06-01', '2026-06-15']);
});

test('handles the late-night timezone edge near midnight', function () {
    // 23:30 event on Fridays — must not bleed into the next day
    Event::factory()->create([
        'starts_at' => '2026-06-05 23:30:00', // a Friday
        'recurrence' => 'FREQ=WEEKLY;BYDAY=FR',
    ]);

    // Window covering exactly Friday June 12 in Berlin time
    [$from, $to] = window('2026-06-12 00:00', '2026-06-12 23:59');
    $occurrences = Event::occurringBetween($from, $to);

    expect($occurrences)->toHaveCount(1);
    expect($occurrences[0]['starts_at']->format('Y-m-d H:i'))->toBe('2026-06-12 23:30');

    // The day after contains nothing
    [$from, $to] = window('2026-06-13 00:00', '2026-06-13 23:59');
    expect(Event::occurringBetween($from, $to))->toHaveCount(0);
});

test('monthly series on day 31 stay deterministic across window starts', function () {
    Event::factory()->create([
        'starts_at' => '2026-01-31 18:00:00',
        'recurrence' => 'FREQ=MONTHLY',
    ]);

    // Occurrence n is derived from DTSTART with no-overflow months —
    // Feb 28, Mar 31, Apr 30 — regardless of where the window begins.
    [$from, $to] = window('2026-02-01 00:00', '2026-04-30 23:59');
    $dates = Event::occurringBetween($from, $to)->pluck('starts_at')->map->format('Y-m-d')->all();
    expect($dates)->toBe(['2026-02-28', '2026-03-31', '2026-04-30']);

    // Same series asked later must agree
    [$from, $to] = window('2026-04-01 00:00', '2026-04-30 23:59');
    $dates = Event::occurringBetween($from, $to)->pluck('starts_at')->map->format('Y-m-d')->all();
    expect($dates)->toBe(['2026-04-30']);
});

test('one-off events appear once and only inside their window', function () {
    Event::factory()->create(['starts_at' => '2026-06-10 11:00:00', 'recurrence' => null]);

    [$from, $to] = window('2026-06-10 00:00', '2026-06-10 23:59');
    expect(Event::occurringBetween($from, $to))->toHaveCount(1);

    [$from, $to] = window('2026-06-11 00:00', '2026-06-12 23:59');
    expect(Event::occurringBetween($from, $to))->toHaveCount(0);
});

test('visibility is the quality bar or a curate; AI relevance never hides an event', function () {
    $base = ['starts_at' => '2026-06-10 11:00:00', 'recurrence' => null];

    Event::factory()->create([...$base, 'status' => 'expired']);
    Event::factory()->create([...$base, 'status' => 'hidden']);
    // Unscored, low quality, uncurated (a raw scrape not yet enriched) — hidden.
    Event::factory()->create([...$base, 'relevance' => null, 'quality_score' => 0.1, 'is_curated' => false]);

    // A low AI relevance only ranks it lower; it still shows.
    $lowRelevance = Event::factory()->create([...$base, 'relevance' => 0.2, 'quality_score' => 0.9, 'is_curated' => false]);
    $byQuality = Event::factory()->create([...$base, 'relevance' => null, 'quality_score' => 0.8, 'is_curated' => false]);
    $byCurate = Event::factory()->create([...$base, 'relevance' => null, 'quality_score' => 0.0, 'is_curated' => true]);
    $highRelevance = Event::factory()->create([...$base, 'relevance' => 0.9, 'quality_score' => 0.9]);

    [$from, $to] = window('2026-06-10 00:00', '2026-06-10 23:59');
    $ids = Event::occurringBetween($from, $to)->pluck('event.id')->all();

    expect($ids)->toContain($lowRelevance->id)
        ->and($ids)->toContain($byQuality->id)
        ->and($ids)->toContain($byCurate->id)
        ->and($ids)->toContain($highRelevance->id)
        ->and($ids)->toHaveCount(4);
});

test('events:expire retires past one-offs and finished series, keeps open-ended ones', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-12 12:00', 'Europe/Berlin'));

    $pastOneOff = Event::factory()->create(['starts_at' => '2026-06-10 11:00:00', 'ends_at' => '2026-06-10 13:00:00', 'recurrence' => null]);
    $futureOneOff = Event::factory()->create(['starts_at' => '2026-06-14 11:00:00', 'recurrence' => null]);
    $finishedSeries = Event::factory()->create(['starts_at' => '2026-05-01 19:00:00', 'recurrence' => 'FREQ=WEEKLY;BYDAY=FR', 'recurrence_until' => '2026-06-01 00:00:00']);
    $openSeries = Event::factory()->create(['starts_at' => '2026-05-01 19:00:00', 'recurrence' => 'FREQ=WEEKLY;BYDAY=FR', 'recurrence_until' => null]);

    $this->artisan('events:expire')->assertSuccessful();

    expect($pastOneOff->refresh()->status)->toBe('expired');
    expect($futureOneOff->refresh()->status)->toBe('active');
    expect($finishedSeries->refresh()->status)->toBe('expired');
    expect($openSeries->refresh()->status)->toBe('active');
});

test('occurrences come back chronological across events', function () {
    Event::factory()->create(['title' => 'B', 'starts_at' => '2026-06-10 20:00:00', 'recurrence' => null]);
    Event::factory()->create(['title' => 'A', 'starts_at' => '2026-06-10 09:00:00', 'recurrence' => null]);

    [$from, $to] = window('2026-06-10 00:00', '2026-06-10 23:59');
    $titles = Event::occurringBetween($from, $to)->pluck('event.title')->all();

    expect($titles)->toBe(['A', 'B']);
});

test('a stored tip that claims a language the listing never mentions is not shown', function () {
    $event = Event::factory()->create([
        'starts_at' => '2026-06-10 08:50:00', 'recurrence' => null, 'quality_score' => 0.9,
        'title' => 'Rheinpark parkrun', 'description' => '5 km laufen, jeden Samstag.', 'price_text' => null,
        'tip_en' => 'No German needed — just turn up by 8:50 with your parkrun barcode.',
    ]);
    $grounded = Event::factory()->create([
        'starts_at' => '2026-06-10 10:00:00', 'recurrence' => null, 'quality_score' => 0.9,
        'title' => 'Book club', 'description' => 'Wir lesen auf Englisch.', 'tip_en' => 'Bring the book; the talk is in English.',
    ]);
    $presenter = app(EventOccurrencePresenter::class);
    $start = CarbonImmutable::parse('2026-06-10 08:50', 'Europe/Berlin');

    expect($presenter->present($event, $start, null)['tip'])->toBeNull()
        ->and($presenter->present($grounded, $start, null)['tip'])->toBe('Bring the book; the talk is in English.');
});
