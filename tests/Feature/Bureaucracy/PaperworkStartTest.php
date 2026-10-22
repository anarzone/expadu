<?php

use App\Models\BureaucracyPerson;
use App\Models\User;

// Paperwork starts itself (it replaces the old setup wizard): "Get started"
// asks where you live, since the city decides which office's tasks apply,
// then makes your record, and the plan is ready. Nothing else is asked first.

test('a new account is asked where it lives, from the cities Paperwork covers', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/bureaucracy')->assertOk()->assertInertia(fn ($page) => $page
        ->where('entry.state', 'setup_required')
        ->where('coveredCities', ['Cologne']));
});

test('where you live sets the account\'s city and its coverage, then your record gives a ready plan', function () {
    $user = User::factory()->create(['city' => null]);
    $this->actingAs($user);

    $this->putJson('/bureaucracy/v2/people/self/city', ['city' => ' Cologne '])->assertOk()
        ->assertJson(['city' => 'Cologne', 'jurisdiction' => 'de-nrw-cologne']);
    expect($user->fresh()->city)->toBe('Cologne');

    $this->postJson('/bureaucracy/v2/people/self')->assertSuccessful();
    $this->getJson('/bureaucracy/v2/plan')->assertOk()->assertJsonPath('state', 'ready')
        ->assertJsonPath('plan.person_id', BureaucracyPerson::query()->where('account_user_id', $user->id)->value('id'));

    // Somewhere we don't cover yet: said as such, never guessed as Cologne.
    $this->putJson('/bureaucracy/v2/people/self/city', ['city' => 'Leipzig'])->assertOk()
        ->assertJson(['city' => 'Leipzig', 'jurisdiction' => 'outside_coverage']);
});

test('a city must be given, and only by the account itself', function () {
    $this->putJson('/bureaucracy/v2/people/self/city', ['city' => 'Cologne'])->assertUnauthorized();

    $this->actingAs(User::factory()->create());
    $this->putJson('/bureaucracy/v2/people/self/city', ['city' => ''])->assertUnprocessable();
    $this->putJson('/bureaucracy/v2/people/self/city', ['city' => str_repeat('x', 81)])->assertUnprocessable();
});
