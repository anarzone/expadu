<?php

use App\Home\DiscoveryFeed;
use App\Home\PromptSuggestions;
use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use App\Profile\CategoryAffinity;
use App\Profile\ProfileEngine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Events store coordinates in a PostGIS `location` column (lat/lng are
 * accessors), so a test event needs its point set explicitly.
 */
function eventAt(string $title, $startsAt, float $lat, float $lng): Event
{
    $event = Event::factory()->create(['title' => $title, 'starts_at' => $startsAt]);
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [$lng, $lat, $event->id]);

    return $event->refresh();
}

beforeEach(fn () => Cache::flush());

function feedUser(): User
{
    return User::factory()->onboarded()->create([
        'veedel' => 'Ehrenfeld',
        'situation' => 'student',
        'is_eu' => true,
    ]);
}

test('discovery rails rank spots and split home area from new areas', function () {
    $user = feedUser();
    // A deep enough pool that the hero rail's per-category cap leaves distinct
    // picks for the home and new rails (a 1-spot pool would be consumed whole).
    foreach (range(1, 3) as $i) {
        Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
        Spot::factory()->create(['category' => 'playground', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
        Spot::factory()->create(['category' => 'park', 'veedel' => 'Porz', 'lat' => 50.88, 'lng' => 7.05]);
        Spot::factory()->create(['category' => 'playground', 'veedel' => 'Porz', 'lat' => 50.88, 'lng' => 7.05]);
    }

    $rails = collect(app(DiscoveryFeed::class)->for(homeContext($user)));
    expect($rails->pluck('key'))->toContain('made_for_today');

    // around_home shows only home-area spots; try_new shows only the rest.
    $home = $rails->firstWhere('key', 'around_home');
    expect($home)->not->toBeNull();
    expect(collect($home['cards'])->pluck('veedel')->unique()->values()->all())->toBe(['Ehrenfeld']);

    $new = $rails->firstWhere('key', 'try_new');
    expect($new)->not->toBeNull();
    expect(collect($new['cards'])->pluck('veedel'))->not->toContain('Ehrenfeld');
});

test('the tonight rail surfaces today\'s upcoming events', function () {
    $user = feedUser();
    Spot::factory()->create(['category' => 'cafe', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
    $jazz = eventAt('Jazz Night', now()->addHours(3), 50.94, 6.95);

    $rails = app(DiscoveryFeed::class)->for(homeContext($user, ['tonightEvents' => collect([$jazz])]));
    $tonight = collect($rails)->firstWhere('key', 'tonight');

    expect($tonight)->not->toBeNull();
    expect(collect($tonight['cards'])->pluck('name'))->toContain('Jazz Night');
});

test('an empty spot catalogue does not blow up the feed', function () {
    expect(app(DiscoveryFeed::class)->for(homeContext(feedUser())))->toBeArray();
});

test('prompt suggestions surface Anmeldung for a recent arrival, capped at four', function () {
    $user = User::factory()->onboarded()->create([
        'arrival_date' => now()->subDays(5),
        'veedel' => 'Ehrenfeld',
        'situation' => 'student',
        'is_eu' => true,
    ]);

    $chips = app(PromptSuggestions::class)->for(homeContext($user));

    expect(count($chips))->toBeGreaterThan(0)->toBeLessThanOrEqual(4);

    $anmeldung = collect($chips)->first(fn ($c) => str_contains($c['label'], 'Anmeldung'));
    expect($anmeldung)->not->toBeNull();
    // It deep-links to the verified checklist rather than the composer.
    expect($anmeldung['href'] ?? null)->toBe('/bureaucracy');
    // Every chip carries an icon key (the frontend maps it to a Tabler icon) —
    // labels are plain text, no leading emoji glyphs.
    expect($anmeldung['icon'] ?? null)->toBe('checklist');
    expect(collect($chips)->every(fn ($c) => ! empty($c['icon'])))->toBeTrue();
});

test('the global spot catalogue scan is cached as plain rows', function () {
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);

    expect(app(DiscoveryFeed::class)->for(homeContext(feedUser())))->toBeArray();
    expect(Cache::has('discovery:spot-scan'))->toBeTrue();
    // Plain rows, not Eloquent models — so the cache round-trips on any driver
    // (a serialised model collection can come back as __PHP_Incomplete_Class).
    expect(Cache::get('discovery:spot-scan')[0])->toBeArray();
    // A second call hits the cache and still builds the feed.
    expect(app(DiscoveryFeed::class)->for(homeContext(feedUser())))->toBeArray();
});

test('a family sees a with-the-kids rail of family categories, not bars', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'family_reunification',
        'is_eu' => false,
        'veedel' => 'Ehrenfeld',
    ]);
    foreach (range(1, 4) as $i) {
        Spot::factory()->create(['name' => "Playground {$i}", 'category' => 'playground', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
    }
    Spot::factory()->create(['name' => 'Hip Bar', 'category' => 'bar', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);

    $rails = collect(app(DiscoveryFeed::class)->for(homeContext($user)));

    $kids = $rails->firstWhere('key', 'with_kids');
    expect($kids)->not->toBeNull();
    // The rail is family-friendly only — playgrounds, never the bar.
    $kidCategories = collect($kids['cards'])->pluck('category');
    expect($kidCategories)->not->toBeEmpty()
        ->and($kidCategories->every(fn ($c) => in_array($c, CategoryAffinity::KID_FRIENDLY, true)))->toBeTrue();
    expect(collect($kids['cards'])->pluck('name'))->not->toContain('Hip Bar');
});

test('a spot never repeats across rails', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'family_reunification',
        'is_eu' => false,
        'veedel' => 'Ehrenfeld',
    ]);

    // A deep pool of kid-friendly home-area spots: each one qualifies for
    // made_for_today AND with_kids AND around_home at once, so without
    // cross-rail de-dup the same venues would lead all three rails. Several
    // share the generic OSM name "Spielplatz" — distinct rows that would still
    // READ as repeats, so they must collapse on name+Veedel, not just id.
    foreach (range(1, 8) as $i) {
        Spot::factory()->create(['name' => 'Spielplatz', 'category' => 'playground', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
        Spot::factory()->create(['name' => "Park {$i}", 'category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
    }

    $rails = collect(app(DiscoveryFeed::class)->for(homeContext($user)));

    $spotCards = $rails->flatMap(fn ($r) => collect($r['cards'])->filter(fn ($c) => ($c['kind'] ?? null) === 'spot'));

    // No id repeats…
    $ids = $spotCards->pluck('id');
    expect($ids)->not->toBeEmpty()->and($ids->count())->toBe($ids->unique()->count());

    // …and no visible-label repeats (the eight "Spielplatz" rows collapse to one).
    $labels = $spotCards->map(fn ($c) => mb_strtolower($c['name']).'|'.$c['veedel']);
    expect($labels->count())->toBe($labels->unique()->count());

    // De-dup didn't starve the feed: the distinct rails still populated.
    expect($rails->pluck('key'))->toContain('made_for_today', 'with_kids', 'around_home');
});

test('a spot photo and its attribution flow through to the rail card', function () {
    $user = feedUser();
    Spot::factory()->create([
        'name' => 'Rheinpark',
        'category' => 'park',
        'veedel' => 'Ehrenfeld',
        'lat' => 50.95,
        'lng' => 6.92,
        'photo_url' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Test.jpg?width=800',
        'photo_attribution' => 'Jane Doe · CC BY-SA 4.0 · Wikimedia Commons',
    ]);

    $card = collect(app(DiscoveryFeed::class)->for(homeContext($user)))
        ->flatMap(fn ($r) => $r['cards'])
        ->firstWhere('name', 'Rheinpark');

    expect($card)->not->toBeNull()
        ->and($card['photo_url'])->toContain('Special:FilePath')
        ->and($card['photo_attribution'])->toContain('CC BY-SA 4.0');
});

test('a just-arrived user gets a get-oriented rail of landmarks', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'eu_employee',
        'is_eu' => true,
        'veedel' => 'Ehrenfeld',
        'arrival_date' => now()->subDays(10),
    ]);
    foreach (range(1, 4) as $i) {
        Spot::factory()->create(['name' => "Landmark {$i}", 'category' => 'attraction', 'veedel' => 'Altstadt', 'lat' => 50.94, 'lng' => 6.95]);
    }

    $rails = collect(app(DiscoveryFeed::class)->for(homeContext($user)));

    $oriented = $rails->firstWhere('key', 'get_oriented');
    expect($oriented)->not->toBeNull();
    // Only landmark categories surface here, and they're distinct from the hero rail.
    expect(collect($oriented['cards'])->pluck('category'))
        ->not->toBeEmpty()
        ->each->toBeIn(CategoryAffinity::LANDMARK);
});

test('category affinity differs by situation', function () {
    $family = app(ProfileEngine::class)->build(
        User::factory()->onboarded()->create(['situation' => 'family_reunification', 'is_eu' => false]),
    );
    $freelancer = app(ProfileEngine::class)->build(
        User::factory()->onboarded()->create(['situation' => 'freelancer', 'is_eu' => true]),
    );

    $familyMap = CategoryAffinity::map($family);
    $freelancerMap = CategoryAffinity::map($freelancer);

    expect(CategoryAffinity::score('playground', $familyMap))->toBe(1.0)
        ->and(CategoryAffinity::score('bar', $familyMap))->toBe(0.15)
        ->and(CategoryAffinity::score('coworking', $freelancerMap))->toBe(1.0)
        ->and(CategoryAffinity::score('playground', $freelancerMap))->toBe(0.5);
});

test('an explicit interest boosts its categories in the affinity map', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'eu_employee',
        'is_eu' => true,
        'interests' => ['nightlife'],
    ]);

    $map = CategoryAffinity::map(app(ProfileEngine::class)->build($user));

    // A picked interest outranks a merely situation-fit category (1.5 > 1.0).
    expect(CategoryAffinity::score('bar', $map))->toBe(1.5);
});

test('an interest never overrides a kid-safety demotion', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'family_reunification',
        'is_eu' => false,
        'interests' => ['nightlife'],
    ]);

    $map = CategoryAffinity::map(app(ProfileEngine::class)->build($user));

    expect(CategoryAffinity::score('bar', $map))->toBe(0.15); // family keeps bars demoted
});
