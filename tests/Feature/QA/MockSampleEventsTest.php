<?php

use App\Models\ComposerWorkspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

test('the sample events refuse to write listings or weather outside the local environment', function () {
    User::factory()->onboarded()->create(['email' => 'e2e@expadu.test']);

    $this->artisan('mock:sample-events')->assertFailed();
    $this->artisan('mock:sample-events', ['--remove' => true])->assertFailed();

    $this->assertDatabaseCount('events', 0);
    $this->assertDatabaseCount('venues', 0);
    expect(Cache::get('weather_stale:50.9375_6.9603'))->toBeNull();
});

test('the details read only the signed-in person’s own planned appointment', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 13:10', 'Europe/Berlin'));
    $plan = fn (string $name) => ['plans' => ['2026-09-30' => ['slots' => [[
        'id' => 'appointment-1',
        'type' => 'appointment',
        'name' => $name,
        'start_at' => '2026-09-30T14:00:00+02:00',
        'end_at' => '2026-09-30T14:30:00+02:00',
    ]]]]];
    $me = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    ComposerWorkspace::query()->create(['user_id' => $other->id, 'state' => $plan('Someone else’s appointment'), 'revision' => 1]);

    $this->actingAs($me)->get(route('events'))
        ->assertInertia(fn ($page) => $page->where('appointment', null));

    ComposerWorkspace::query()->create(['user_id' => $me->id, 'state' => $plan('Blue Card appointment'), 'revision' => 1]);

    $this->actingAs($me)->get(route('events'))
        ->assertInertia(fn ($page) => $page->where('appointment', ['title' => 'Blue Card appointment', 'end' => '14:30']));

    // Another day's page carries none.
    $this->actingAs($me)->get(route('events', ['date' => '2026-10-01']))
        ->assertInertia(fn ($page) => $page->where('appointment', null));
});
