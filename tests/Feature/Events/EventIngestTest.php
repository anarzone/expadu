<?php

use App\Jobs\ProcessEventJob;
use App\Models\Event;
use App\Models\Spot;
use App\Models\Venue;
use App\Services\ClassifiesEvents;
use App\Services\VenueResolver;
use Illuminate\Support\Facades\DB;

function fakeClassifier(array $result): object
{
    $fake = new class implements ClassifiesEvents
    {
        public array $result = [];

        public int $calls = 0;

        public function classify(Event $event): array
        {
            $this->calls++;

            if (($this->result['__throw'] ?? false) === true) {
                throw new RuntimeException('Malformed classifier output');
            }

            return $this->result;
        }
    };
    $fake->result = $result;
    app()->instance(ClassifiesEvents::class, $fake);

    return $fake;
}

function classification(array $overrides = []): array
{
    return array_merge([
        'relevance' => 0.9,
        'confidence' => 0.95,
        'category' => 'language_exchange',
        'language' => 'mixed',
        'chips' => ['English-friendly', 'free'],
        'title_en' => 'Language night',
        'description_en' => 'A complete English translation of the source description.',
        'summary_en' => 'Our own short words. Two sentences max.',
        'tip_en' => 'Come early.',
    ], $overrides);
}

test('a confident classification stores every AI field once', function () {
    fakeClassifier(classification());
    $event = Event::factory()->create(['summary_en' => null, 'location_name' => null]);

    (new ProcessEventJob($event))->handle(app(ClassifiesEvents::class));

    $event->refresh();
    expect($event->title_en)->toBe('Language night');
    expect($event->description_en)->toBe('A complete English translation of the source description.');
    expect($event->summary_en)->toBe('Our own short words. Two sentences max.');
    expect($event->category)->toBe('language_exchange');
    expect($event->language)->toBe('mixed');
    expect($event->chips)->toBe(['English-friendly', 'free']);
    expect($event->relevance)->toBe(0.9);
    expect($event->needs_review)->toBeFalse();
});

test('low confidence drops the chips and flags review', function () {
    fakeClassifier(classification(['confidence' => 0.5, 'chips' => ['English-friendly']]));
    $event = Event::factory()->create(['summary_en' => null, 'location_name' => null]);

    (new ProcessEventJob($event))->handle(app(ClassifiesEvents::class));

    $event->refresh();
    expect($event->needs_review)->toBeTrue();
    expect($event->chips)->toBe([]); // unsure chip dropped, never guessed
    expect($event->summary_en)->not->toBeNull(); // translation still kept
});

test('an already-classified event is skipped (idempotent)', function () {
    $fake = fakeClassifier(classification());
    $event = Event::factory()->create([
        'title_en' => 'Done',
        'description_en' => 'Translated already',
        'summary_en' => 'done already',
    ]);

    (new ProcessEventJob($event))->handle(app(ClassifiesEvents::class));

    expect($fake->calls)->toBe(0);
});

test('an older classification missing the full description translation is reprocessed', function () {
    $fake = fakeClassifier(classification());
    $event = Event::factory()->create([
        'title_en' => 'Old title translation',
        'description_en' => null,
        'summary_en' => 'Old summary',
        'location_name' => null,
    ]);

    (new ProcessEventJob($event))->handle(app(ClassifiesEvents::class));

    expect($fake->calls)->toBe(1)
        ->and($event->fresh()->description_en)->toBe('A complete English translation of the source description.');
});

test('an event without a source description stores no invented description translation', function () {
    fakeClassifier(classification(['description_en' => null]));
    $event = Event::factory()->create([
        'description' => null,
        'description_en' => null,
        'summary_en' => null,
        'location_name' => null,
    ]);

    (new ProcessEventJob($event))->handle(app(ClassifiesEvents::class));

    expect($event->fresh()->description)->toBeNull()
        ->and($event->fresh()->description_en)->toBeNull()
        ->and($event->fresh()->summary_en)->toBe('Our own short words. Two sentences max.');
});

test('classification job timeout scales for chunked full-description translation', function () {
    $event = Event::factory()->create(['description' => str_repeat('Langer deutscher Abschnitt. ', 600)]);

    expect((new ProcessEventJob($event))->timeout)->toBeGreaterThan(45);
});

test('configured default queue visibility exceeds a long translation job timeout', function () {
    $event = Event::factory()->create(['description' => str_repeat('Langer deutscher Abschnitt. ', 2000)]);
    $job = new ProcessEventJob($event);

    expect($job->connection)->toBeNull()
        ->and(config('queue.connections.database.retry_after'))->toBeGreaterThan($job->timeout)
        ->and(config('queue.connections.redis.retry_after'))->toBeGreaterThan($job->timeout);
});

test('terminal classification failure flags needs_review', function () {
    fakeClassifier(classification(['__throw' => true]));
    $event = Event::factory()->create(['summary_en' => null]);

    $job = new ProcessEventJob($event);

    expect(fn () => $job->handle(app(ClassifiesEvents::class)))->toThrow(RuntimeException::class);

    // queue retries; after the final attempt failed() runs:
    $job->failed(new RuntimeException('Malformed classifier output'));
    expect($event->refresh()->needs_review)->toBeTrue();
});

test('venue resolution links a place within 50m', function () {
    fakeClassifier(classification());
    $place = Spot::factory()->create(['name' => 'Stadtgarten', 'category' => 'park', 'veedel' => 'Neustadt-Nord', 'lat' => 50.9421, 'lng' => 6.9358]);

    $event = Event::factory()->create([
        'summary_en' => null,
        'location_name' => 'Stadtgarten Biergarten',
        'address' => 'Venloer Str. 40',
    ]);
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [6.93582, 50.94212, $event->id]);

    (new ProcessEventJob($event))->handle(app(ClassifiesEvents::class));

    $venue = $event->refresh()->venue;
    expect($venue)->not->toBeNull();
    expect($venue->place_id)->toBe($place->id);
    expect($venue->veedel)->toBe('Neustadt-Nord');
});

test('the same scraped event processed twice stays one record', function () {
    fakeClassifier(classification());
    $event = Event::factory()->create(['summary_en' => null, 'location_name' => 'Café X', 'source' => 'koeln.de', 'source_uid' => '42']);

    (new ProcessEventJob($event))->handle(app(ClassifiesEvents::class));
    (new ProcessEventJob($event))->handle(app(ClassifiesEvents::class));

    expect(Event::where('source', 'koeln.de')->where('source_uid', '42')->count())->toBe(1);
    expect(Venue::where('name', 'Café X')->count())->toBe(1);
});

test('scheduled enrichment keeps same-title events from different sources', function () {
    Event::factory()->create(['source' => 'koeln.de', 'source_uid' => 'one', 'title' => 'Shared title', 'starts_at' => now()->addDay()]);
    Event::factory()->create(['source' => 'stadt-koeln.de', 'source_uid' => 'two', 'title' => 'Shared title', 'starts_at' => now()->addDay()]);

    $this->artisan('events:enrich')->assertSuccessful();

    expect(Event::where('title', 'Shared title')->count())->toBe(2);
});

test('venue resolver refreshes coordinates and place link for an existing venue', function () {
    $old = Spot::factory()->create(['name' => 'Shared Hall Old', 'lat' => 50.94, 'lng' => 6.95, 'veedel' => 'Old']);
    $new = Spot::factory()->create(['name' => 'Shared Hall New', 'lat' => 50.96, 'lng' => 6.97, 'veedel' => 'New']);
    $venue = app(VenueResolver::class)->resolve('Shared Hall', 'Same address', 50.94, 6.95);
    expect($venue->place_id)->toBe($old->id);
    $venue = app(VenueResolver::class)->resolve('Shared Hall', 'Same address', 50.96, 6.97);
    expect($venue->lat)->toBe(50.96)->and($venue->lng)->toBe(6.97)
        ->and($venue->place_id)->toBe($new->id)->and($venue->veedel)->toBe('New');
});

test('events:import-manual upserts the curated catalogue with recurring rules', function () {
    // A legacy scraper row (source NULL) duplicating a catalogue title
    $legacy = Event::factory()->create(['title' => 'Expat Stammtisch Cologne', 'source' => null]);

    $this->artisan('events:import-manual')->assertSuccessful();
    $this->artisan('events:import-manual')->assertSuccessful(); // idempotent

    // NULL-source duplicates get hidden so the catalogue is canonical
    expect($legacy->refresh()->status)->toBe('hidden');

    // Re-imports must not re-anchor recurring DTSTARTs — an INTERVAL=4
    // series' phase depends on it.
    $stammtisch = Event::where('source', 'manual')->where('source_uid', 'expat-stammtisch-frueh')->first();
    $anchor = $stammtisch->starts_at;
    $this->travelTo(now()->addDays(9));
    $this->artisan('events:import-manual')->assertSuccessful();
    expect($stammtisch->refresh()->starts_at->equalTo($anchor))->toBeTrue();
    $this->travelBack();

    $exchange = Event::where('source', 'manual')->where('source_uid', 'cologne-language-exchange')->first();
    expect($exchange)->not->toBeNull();
    expect(Event::where('source', 'manual')->count())->toBe(10);
    expect($exchange->recurrence)->toBe('FREQ=WEEKLY;BYDAY=TH');
    expect($exchange->verified_at)->not->toBeNull();
    expect($exchange->relevance)->toBe(1.0);
    expect($exchange->venue->name)->toBe('Gilden im Zims');
    expect(Event::where('source', 'manual')->whereNotNull('recurrence')->count())->toBeGreaterThanOrEqual(2);
});
