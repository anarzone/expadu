<?php

use App\Composer\IntentWeights;
use App\Home\HomeFeed;
use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function homeFeedUser(): User
{
    return User::factory()->onboarded()->create([
        'veedel' => 'Ehrenfeld',
        'situation' => 'student',
        'is_eu' => true,
    ]);
}

function eventAtTonight(string $title, $startsAt, float $lat = 50.94, float $lng = 6.95): Event
{
    $event = Event::factory()->create(['title' => $title, 'starts_at' => $startsAt]);
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [$lng, $lat, $event->id]);

    return $event->refresh();
}

test('weather and intent weights resolve once across chips, tiles and rails', function () {
    $user = homeFeedUser();

    $this->mock(WeatherService::class, function ($m) {
        $m->shouldReceive('getForecast')->once()->andReturn(['rain_starts' => null]);
    });
    $this->mock(IntentWeights::class, function ($m) {
        $m->shouldReceive('for')->once()->andReturn([]);
    });

    $feed = app(HomeFeed::class);
    $chips = $feed->chips($user);
    $tiles = $feed->tiles($user);
    $rails = $feed->rails($user);

    expect($chips)->toBeArray()
        ->and($tiles)->toBeArray()
        ->and($rails)->toBeArray();
});

test('an imminent event becomes an urgent tile and is not repeated in the rail', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 14:00', 'Europe/Berlin'));
    $user = homeFeedUser();
    Spot::factory()->create(['category' => 'cafe', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
    eventAtTonight('Imminent Meetup', now()->addMinutes(45));

    $feed = app(HomeFeed::class);
    $tiles = collect($feed->tiles($user));
    $rails = collect($feed->rails($user));

    // Shows as an urgent "Right now" tile…
    expect($tiles->pluck('type'))->toContain('tonight_events');
    // …and is NOT repeated in the tonight rail (no triplication).
    expect($rails->firstWhere('key', 'tonight'))->toBeNull();
});

test('a distant event stays a rail and earns no urgent tile', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 14:00', 'Europe/Berlin'));
    $user = homeFeedUser();
    Spot::factory()->create(['category' => 'cafe', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
    eventAtTonight('Evening Jazz', now()->addHours(5));

    $feed = app(HomeFeed::class);

    expect(collect($feed->tiles($user))->pluck('type'))->not->toContain('tonight_events');

    $tonight = collect($feed->rails($user))->firstWhere('key', 'tonight');
    expect($tonight)->not->toBeNull();
    expect(collect($tonight['cards'])->pluck('name'))->toContain('Evening Jazz');
});

test('the kids chip fires for a user with a child_born_at attribute', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'family_reunification',
        'is_eu' => false,
        'veedel' => 'Ehrenfeld',
        'profile_attributes' => ['child_born_at' => now()->subMonth()->toDateString()],
    ]);

    $chips = collect(app(HomeFeed::class)->chips($user))->pluck('label');

    expect($chips)->toContain('🧸 Something with the kids');
});
