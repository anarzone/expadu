<?php

use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;

test('events page shows the chosen day, earlier hours included', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 15:00', 'Europe/Berlin'));
    $this->actingAs(User::factory()->onboarded()->create());
    Event::factory()->create(['title' => 'Morning talk', 'starts_at' => '2026-10-08 10:00:00', 'ends_at' => '2026-10-08 11:00:00']);
    Event::factory()->create(['title' => 'Evening concert', 'starts_at' => '2026-10-08 19:00:00', 'ends_at' => null]);
    Event::factory()->create(['title' => 'Next day', 'starts_at' => '2026-10-09 19:00:00']);

    $this->get(route('events'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('events')
            ->where('date', '2026-10-08')
            ->where('today', '2026-10-08')
            ->has('listings', 2)
            ->where('listings.0.title_original', 'Morning talk')
            ->where('listings.1.title_original', 'Evening concert'));

    $this->get(route('events', ['date' => '2026-10-09']))
        ->assertInertia(fn ($page) => $page
            ->where('date', '2026-10-09')
            ->has('listings', 1)
            ->where('listings.0.title_original', 'Next day'));
});

test('the filters come from the URL, and unknown values are dropped', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    $this->get(route('events', ['q' => ' chess ', 'category' => 'culture', 'area' => 'Ehrenfeld', 'free' => 1, 'saved' => 1, 'sort' => 'closest', 'from' => 'Neumarkt']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.q', 'chess')
            ->where('filters.category', 'culture')
            ->where('filters.area', 'Ehrenfeld')
            ->where('filters.free', true)
            ->where('filters.saved', true)
            ->where('filters.sort', 'closest')
            ->where('filters.from', 'Neumarkt'));

    $this->get(route('events', ['category' => 'nonsense', 'sort' => 'random']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.category', null)
            ->where('filters.sort', 'soonest'));
});

test('an invalid date is refused', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    $this->get(route('events', ['date' => '2026-02-31']))->assertSessionHasErrors('date');
});

test('legacy detail and saved URLs redirect to the events page', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    $event = Event::factory()->create();

    $response = $this->get(route('events.show', $event))->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith(route('events').'?event='.$event->id.'&start=');
    $this->get(route('events.saved'))->assertRedirect(route('events', ['saved' => 1]));
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
