<?php

use App\Models\Event;
use App\Models\User;

test('events:curate marks meet-people events', function () {
    $stammtisch = Event::factory()->create([
        'title' => 'Expat Stammtisch Ehrenfeld',
        'category' => 'food',
        'starts_at' => now()->addDays(2),
    ]);
    $concert = Event::factory()->create([
        'title' => 'Symphonic Night',
        'category' => 'music',
        'starts_at' => now()->addDays(2),
    ]);
    $language = Event::factory()->create([
        'title' => 'Tuesday Drinks',
        'category' => 'language',
        'starts_at' => now()->addDays(3),
    ]);

    $this->artisan('events:curate')->assertSuccessful();

    expect($stammtisch->fresh()->is_curated)->toBeTrue();
    expect($language->fresh()->is_curated)->toBeTrue();
    expect($concert->fresh()->is_curated)->toBeFalse();
});

test('the events feed prefers the English title', function () {
    $user = User::factory()->onboarded()->create();
    Event::factory()->create([
        'title' => 'Sommerfest im Stadtgarten',
        'title_en' => 'Summer festival in the Stadtgarten',
        'source_lang' => 'de',
        'starts_at' => now()->addHours(3),
        'recurrence' => null,
    ]);

    $this->actingAs($user);

    $this->getJson('/api/events?window=today')
        ->assertJsonPath('data.0.title', 'Summer festival in the Stadtgarten');
});
