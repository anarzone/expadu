<?php

use App\Home\DiscoveryFeed;
use App\Home\PromptSuggestions;
use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
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
    Spot::factory()->create(['name' => 'Home Cafe', 'category' => 'cafe', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92, 'rating' => 4.5]);
    Spot::factory()->create(['name' => 'Far Park', 'category' => 'park', 'veedel' => 'Porz', 'lat' => 50.88, 'lng' => 7.05, 'rating' => 4.0]);

    $rails = app(DiscoveryFeed::class)->for($user);
    $keys = collect($rails)->pluck('key');

    expect($keys)->toContain('made_for_today');

    $home = collect($rails)->firstWhere('key', 'around_home');
    expect($home)->not->toBeNull();
    expect(collect($home['cards'])->pluck('name'))
        ->toContain('Home Cafe')
        ->not->toContain('Far Park');
});

test('the tonight rail surfaces today\'s upcoming events', function () {
    $user = feedUser();
    Spot::factory()->create(['category' => 'cafe', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
    eventAt('Jazz Night', now()->addHours(3), 50.94, 6.95);

    $rails = app(DiscoveryFeed::class)->for($user);
    $tonight = collect($rails)->firstWhere('key', 'tonight');

    expect($tonight)->not->toBeNull();
    expect(collect($tonight['cards'])->pluck('name'))->toContain('Jazz Night');
});

test('an empty spot catalogue does not blow up the feed', function () {
    expect(app(DiscoveryFeed::class)->for(feedUser()))->toBeArray();
});

test('prompt suggestions surface Anmeldung for a recent arrival, capped at four', function () {
    $user = User::factory()->onboarded()->create([
        'arrival_date' => now()->subDays(5),
        'veedel' => 'Ehrenfeld',
        'situation' => 'student',
        'is_eu' => true,
    ]);

    $chips = app(PromptSuggestions::class)->for($user);

    expect(count($chips))->toBeGreaterThan(0)->toBeLessThanOrEqual(4);

    $anmeldung = collect($chips)->first(fn ($c) => str_contains($c['label'], 'Anmeldung'));
    expect($anmeldung)->not->toBeNull();
    // It deep-links to the verified checklist rather than the composer.
    expect($anmeldung['href'] ?? null)->toBe('/bureaucracy');
});
