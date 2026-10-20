<?php

use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00', 'Europe/Berlin')));

test('a search that matches nothing today points to the nearest day it does', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    Event::factory()->create(['title' => 'Schachabend', 'starts_at' => '2026-10-15 18:00:00']);
    Event::factory()->create(['title' => 'Schach für Kinder', 'starts_at' => '2026-10-11 16:00:00']);
    Event::factory()->create(['title' => 'Quiltingtreff', 'starts_at' => '2026-10-08 15:00:00']);

    $this->getJson(route('api.events.other-day', ['date' => '2026-10-08', 'q' => 'schach']))
        ->assertOk()
        ->assertJson(['date' => '2026-10-11', 'count' => 1]);
});

test('the saved filter only counts the person\'s own bookmarks', function () {
    $me = User::factory()->onboarded()->create();
    $someone = User::factory()->onboarded()->create();
    $mine = Event::factory()->create(['starts_at' => '2026-10-20 19:00:00']);
    $theirs = Event::factory()->create(['starts_at' => '2026-10-10 19:00:00']);
    EventBookmark::create(['user_id' => $me->id, 'event_id' => $mine->id, 'occurrence_start' => $mine->starts_at, 'title' => $mine->title]);
    EventBookmark::create(['user_id' => $someone->id, 'event_id' => $theirs->id, 'occurrence_start' => $theirs->starts_at, 'title' => $theirs->title]);

    $this->actingAs($me)
        ->getJson(route('api.events.other-day', ['date' => '2026-10-08', 'saved' => 1]))
        ->assertOk()
        ->assertJson(['date' => '2026-10-20', 'count' => 1]);
});

test('nothing to point to reads as no date', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    $this->getJson(route('api.events.other-day', ['date' => '2026-10-08', 'q' => 'nothing like this']))
        ->assertOk()
        ->assertJson(['date' => null, 'count' => 0]);
});
