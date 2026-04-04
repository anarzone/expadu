<?php

use App\Models\Review;
use App\Models\Spot;
use App\Models\User;

it('can list reviews for a spot', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $spot = Spot::factory()->create();
    Review::factory()->count(3)->create(['spot_id' => $spot->id]);

    $this->actingAs($user);

    $this->getJson(route('reviews.index', $spot))
        ->assertSuccessful()
        ->assertJsonCount(3, 'data');
});

it('can submit a review', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $spot = Spot::factory()->create(['rating' => null]);

    $this->actingAs($user);

    $this->postJson(route('reviews.store', $spot), [
        'rating' => 4,
        'body' => 'Great spot for working!',
    ])->assertCreated();

    expect(Review::where('spot_id', $spot->id)->where('user_id', $user->id)->exists())->toBeTrue();
    expect($spot->fresh()->rating)->toBe(4.0);
});

it('updates existing review instead of duplicating', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $spot = Spot::factory()->create();

    $this->actingAs($user);

    $this->postJson(route('reviews.store', $spot), ['rating' => 3])->assertCreated();
    $this->postJson(route('reviews.store', $spot), ['rating' => 5])->assertCreated();

    expect(Review::where('spot_id', $spot->id)->where('user_id', $user->id)->count())->toBe(1);
    expect(Review::where('spot_id', $spot->id)->where('user_id', $user->id)->first()->rating)->toBe(5);
});

it('validates rating is required and between 1-5', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $spot = Spot::factory()->create();

    $this->actingAs($user);

    $this->postJson(route('reviews.store', $spot), [])->assertUnprocessable();
    $this->postJson(route('reviews.store', $spot), ['rating' => 0])->assertUnprocessable();
    $this->postJson(route('reviews.store', $spot), ['rating' => 6])->assertUnprocessable();
});

it('recalculates spot rating after review', function () {
    $user1 = User::factory()->create(['onboarded_at' => now()]);
    $user2 = User::factory()->create(['onboarded_at' => now()]);
    $spot = Spot::factory()->create(['rating' => null]);

    $this->actingAs($user1);
    $this->postJson(route('reviews.store', $spot), ['rating' => 4])->assertCreated();

    $this->actingAs($user2);
    $this->postJson(route('reviews.store', $spot), ['rating' => 2])->assertCreated();

    expect($spot->fresh()->rating)->toBe(3.0);
});
