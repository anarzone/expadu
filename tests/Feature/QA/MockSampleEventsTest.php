<?php

use App\Models\ComposerWorkspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\MockSample\EventsSeeder;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

/** What's on's own data, as its panel reads it over Today. */
function qaWhatsOn($test, array $query = []): ?array
{
    return $test->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => Inertia::getVersion(),
        'X-Inertia-Partial-Component' => 'today',
        'X-Inertia-Partial-Data' => 'whatsOn',
    ])->get('/today?'.http_build_query(['whats_on' => 1, ...$query]))->assertOk()->json('props.whatsOn');
}

test('the sample events write no listings or weather outside the local environment', function () {
    User::factory()->onboarded()->create(['email' => 'e2e@expadu.test']);

    $this->seed(EventsSeeder::class);

    $this->assertDatabaseCount('events', 0);
    $this->assertDatabaseCount('venues', 0);
    expect(Cache::get('weather_stale:50.9375_6.9603'))->toBeNull();
});

test('the details read only the signed-in person’s own planned appointment', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 13:10', 'Europe/Berlin'));
    $plan = fn (string $name, array $place = []) => ['plans' => ['2026-09-30' => ['slots' => [[
        'id' => 'appointment-1',
        'type' => 'appointment',
        'name' => $name,
        'start_at' => '2026-09-30T14:00:00+02:00',
        'end_at' => '2026-09-30T14:30:00+02:00',
    ] + $place]]]];
    $me = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    ComposerWorkspace::query()->create(['user_id' => $other->id, 'state' => $plan('Someone else’s appointment'), 'revision' => 1]);

    expect(qaWhatsOn($this->actingAs($me))['appointment'])->toBeNull();

    $mine = ComposerWorkspace::query()->create(['user_id' => $me->id, 'state' => $plan('Blue Card appointment'), 'revision' => 1]);

    expect(qaWhatsOn($this->actingAs($me))['appointment'])->toBe(['title' => 'Blue Card appointment', 'end' => '14:30', 'place' => null]);

    // Its place (Get there's start) only when the person put it on the map.
    $mine->update(['state' => $plan('Blue Card appointment', ['location' => 'Mülheim', 'lat' => 50.96103, 'lng' => 7.0048])]);

    expect(qaWhatsOn($this->actingAs($me))['appointment']['place'])->toBe(['name' => 'Mülheim', 'lat' => 50.96103, 'lng' => 7.0048]);

    // Another day's page carries none.
    expect(qaWhatsOn($this->actingAs($me), ['date' => '2026-10-01'])['appointment'])->toBeNull();
});
