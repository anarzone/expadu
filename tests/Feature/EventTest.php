<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;

test('events page renders the shell with filters from the URL', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('events', ['window' => 'weekend', 'category' => 'sports', 'free' => 1]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('events')
        ->where('filters.window', 'weekend')
        ->where('filters.category', 'sports')
        ->where('filters.free', true)
    );
});

test('an invalid window falls back to today', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    $this->get(route('events', ['window' => 'someday']))
        ->assertInertia(fn ($page) => $page->where('filters.window', 'today'));
});

test('veedel options list only veedels with upcoming events', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    $active = Venue::create(['name' => 'A', 'veedel' => 'Ehrenfeld']);
    $stale = Venue::create(['name' => 'B', 'veedel' => 'Porz']);
    Event::factory()->create(['venue_id' => $active->id, 'starts_at' => now()->addDay()]);
    Event::factory()->create(['venue_id' => $stale->id, 'starts_at' => now()->subDays(30), 'status' => 'expired']);

    $this->get(route('events'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'events',
        'X-Inertia-Partial-Data' => 'veedelOptions',
    ])->assertOk()->assertJsonPath('props.veedelOptions', ['Ehrenfeld']);
});

test('legacy detail and saved URLs redirect to the events page', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    $event = Event::factory()->create();

    $this->get(route('events.show', $event))->assertRedirect(route('events'));
    $this->get(route('events.saved'))->assertRedirect(route('events'));
});

test('user can join an event', function () {
    $user = User::factory()->onboarded()->create();
    $event = Event::factory()->create();
    $this->actingAs($user);

    $this->post(route('events.join', $event));

    expect($user->attendingEvents()->where('events.id', $event->id)->exists())->toBeTrue();
});

test('user can leave an event', function () {
    $user = User::factory()->onboarded()->create();
    $event = Event::factory()->create();
    $user->attendingEvents()->attach($event->id, ['joined_at' => now()]);
    $this->actingAs($user);

    $this->delete(route('events.leave', $event));

    expect($user->attendingEvents()->where('events.id', $event->id)->exists())->toBeFalse();
});
