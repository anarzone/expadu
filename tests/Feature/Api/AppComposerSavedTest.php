<?php

use App\Enums\SpotFeedbackState;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Models\UserPlace;

/* Composer's Saved and Starting point on the iPhone: the web's own controllers, with the app's token. */

test('🔒 Saved and Starting point need a token', function () {
    $this->getJson('/api/v1/events/saved')->assertUnauthorized();
    $this->postJson('/api/v1/places/1/feedback', ['action' => 'saved'])->assertUnauthorized();
    $this->getJson('/api/v1/geocode?q=Neumarkt')->assertUnauthorized();
    $this->getJson('/api/v1/composer/places')->assertUnauthorized();
    $this->getJson('/api/v1/places/1/feedback')->assertUnauthorized();
    $this->putJson('/api/v1/places/1/been')->assertUnauthorized();
    $this->putJson('/api/v1/places/1/work-answer', ['laptop' => 'yes'])->assertUnauthorized();
});

test('🔒 a place saved from the app is saved for that person only, and unsaving clears it', function () {
    $person = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $spot = Spot::factory()->create(['name' => 'Café Fuchs', 'category' => 'cafe', 'lat' => 50.94, 'lng' => 6.95]);
    $token = $person->createToken('iPhone')->plainTextToken;

    $this->withToken($token)->postJson("/api/v1/places/{$spot->id}/feedback", ['action' => 'saved'])->assertOk();

    expect(SpotFeedback::effectiveForUser($person->id)->get($spot->id)?->state)->toBe(SpotFeedbackState::Saved)
        ->and(SpotFeedback::effectiveForUser($other->id)->has($spot->id))->toBeFalse();

    $this->withToken($token)->postJson("/api/v1/places/{$spot->id}/feedback", ['action' => 'clear'])->assertOk();

    expect(SpotFeedback::effectiveForUser($person->id)->get($spot->id)?->state)->not->toBe(SpotFeedbackState::Saved);
});

test('🔒 Starting point lists only the person\'s own places', function () {
    $person = User::factory()->onboarded()->create();
    UserPlace::factory()->for($person)->create(['name' => 'Office', 'category' => 'work', 'sort_order' => 0]);
    UserPlace::factory()->for($person)->create(['name' => 'Flat', 'category' => 'home', 'sort_order' => 1]);
    UserPlace::factory()->create(['name' => 'Someone else\'s home', 'category' => 'home']);

    $places = $this->withToken($person->createToken('iPhone')->plainTextToken)->getJson('/api/v1/composer/places')->assertOk()->json('places');

    expect(collect($places)->pluck('name')->sort()->values()->all())->toBe(['Flat', 'Office']);
});

test('🔒 the saved events list is the person\'s own', function () {
    $person = User::factory()->onboarded()->create();
    $token = $person->createToken('iPhone')->plainTextToken;

    expect($this->withToken($token)->getJson('/api/v1/events/saved')->assertOk()->json('data'))->toBe([]);
});

test('🔒 Been there from the app is the person\'s own, and the sheet reads it back', function () {
    $person = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $spot = Spot::factory()->create(['name' => 'Café Fuchs', 'category' => 'cafe', 'lat' => 50.94, 'lng' => 6.95]);
    $token = $person->createToken('iPhone')->plainTextToken;

    $this->withToken($token)->putJson("/api/v1/places/{$spot->id}/been")->assertOk()->assertJsonPath('been', true);
    $this->withToken($token)->getJson("/api/v1/places/{$spot->id}/feedback")->assertOk()->assertJsonPath('been', true);
    app('auth')->forgetGuards();
    $this->withToken($other->createToken('iPhone')->plainTextToken)->getJson("/api/v1/places/{$spot->id}/feedback")->assertOk()->assertJsonPath('been', false);
});
