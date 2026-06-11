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

test('events page defaults to the curated subset when one exists', function () {
    $user = User::factory()->onboarded()->create();
    Event::factory()->create([
        'title' => 'International Meetup',
        'is_curated' => true,
        'starts_at' => now()->addDay(),
    ]);
    Event::factory()->create([
        'title' => 'Random Concert',
        'is_curated' => false,
        'starts_at' => now()->addDay(),
    ]);

    $this->actingAs($user);

    $response = $this->get(route('events'));
    $response->assertInertia(fn ($page) => $page
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('dbEvents.data', 1)
        )
    );

    $all = $this->get(route('events', ['all' => 'true']));
    $all->assertInertia(fn ($page) => $page
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('dbEvents.data', 2)
        )
    );
});

test('translated events prefer the English title', function () {
    $user = User::factory()->onboarded()->create();
    Event::factory()->create([
        'title' => 'Sommerfest im Stadtgarten',
        'title_en' => 'Summer festival in the Stadtgarten',
        'source_lang' => 'de',
        'is_curated' => true,
        'starts_at' => now()->addDay(),
    ]);

    $this->actingAs($user);

    $response = $this->get(route('events'));
    $response->assertInertia(fn ($page) => $page
        ->loadDeferredProps(fn ($reload) => $reload
            ->where('dbEvents.data.0.title', 'Summer festival in the Stadtgarten')
            ->where('dbEvents.data.0.title_original', 'Sommerfest im Stadtgarten')
        )
    );
});
