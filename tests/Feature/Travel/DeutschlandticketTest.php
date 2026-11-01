<?php

use App\Models\User;

// "I have a Deutschlandticket", said where Travel shows the fare: kept with
// the account, which the fare advice then reads.

test('saying you have a Deutschlandticket (or not) is kept with your account', function () {
    $user = User::factory()->create(['has_deutschlandticket' => false]);

    $this->actingAs($user)->putJson('/api/travel/deutschlandticket', ['held' => true])
        ->assertOk()->assertJson(['held' => true]);
    expect($user->fresh()->has_deutschlandticket)->toBeTrue();

    $this->actingAs($user)->putJson('/api/travel/deutschlandticket', ['held' => false])
        ->assertOk()->assertJson(['held' => false]);
    expect($user->fresh()->has_deutschlandticket)->toBeFalse();
});

test('only yours, and only yes or no', function () {
    $this->putJson('/api/travel/deutschlandticket', ['held' => true])->assertUnauthorized();
    $this->actingAs(User::factory()->create())->putJson('/api/travel/deutschlandticket', ['held' => 'maybe'])->assertUnprocessable();
});
