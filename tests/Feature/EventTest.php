<?php

use App\Models\Event;
use App\Models\User;

test('events page renders with upcoming events', function () {
    $user = User::factory()->onboarded()->create();
    Event::factory()->count(3)->create(['starts_at' => now()->addDays(2)]);
    $this->actingAs($user);

    $response = $this->get(route('events'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('events')
        ->missing('dbEvents')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('dbEvents.data', 3)
        )
    );
});

test('events can be filtered by category', function () {
    $user = User::factory()->onboarded()->create();
    Event::factory()->create(['category' => 'language', 'starts_at' => now()->addDay()]);
    Event::factory()->create(['category' => 'sports', 'starts_at' => now()->addDay()]);
    $this->actingAs($user);

    $response = $this->get(route('events', ['category' => 'language']));

    $response->assertInertia(fn ($page) => $page
        ->missing('dbEvents')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('dbEvents.data', 1)
        )
    );
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

test('saved events page shows joined events', function () {
    $user = User::factory()->onboarded()->create();
    $event = Event::factory()->create(['starts_at' => now()->addDays(3)]);
    $user->attendingEvents()->attach($event->id, ['joined_at' => now()]);
    $this->actingAs($user);

    $response = $this->get(route('events.saved'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->missing('dbEvents')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('dbEvents.data', 1)
        )
    );
});
