<?php

use App\Models\User;
use App\Models\UserPlace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

test('returns journeys with profile-driven ticket advice', function () {
    Http::fake([
        'api.transitous.org/api/v3/plan*' => Http::response(
            json_decode(file_get_contents(base_path('tests/Fixtures/transitous/plan.json')), true),
        ),
    ]);

    $student = User::factory()->onboarded()->create(['situation' => 'student']);
    UserPlace::factory()->create([
        'user_id' => $student->id,
        'category' => 'home',
        'lat' => 50.9513,
        'lng' => 6.9185,
    ]);

    $this->actingAs($student);
    $response = $this->getJson('/api/journey?to_lat=50.9413&to_lng=6.9583&to_name=B%C3%BCrgeramt');

    $response->assertOk();
    $response->assertJsonPath('source', 'transitous');
    $response->assertJsonPath('ticket.advice', 'semester_ticket');
    $response->assertJsonPath('to.name', 'Bürgeramt');
    expect($response->json('journeys'))->not->toBeEmpty();
});

test('ticket chip changes with situation', function () {
    Http::fake([
        'api.transitous.org/api/v3/plan*' => Http::response(
            json_decode(file_get_contents(base_path('tests/Fixtures/transitous/plan.json')), true),
        ),
    ]);

    $employee = User::factory()->onboarded()->create(['situation' => 'eu_employee']);
    UserPlace::factory()->create([
        'user_id' => $employee->id,
        'category' => 'home',
        'lat' => 50.9513,
        'lng' => 6.9185,
    ]);

    $this->actingAs($employee);
    $response = $this->getJson('/api/journey?to_lat=50.9414&to_lng=6.9584');

    $response->assertOk();
    $response->assertJsonPath('ticket.advice', 'job_ticket_ask');
});

test('validates destination coordinates', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $this->getJson('/api/journey?to_lat=999&to_lng=6.95')
        ->assertUnprocessable();
});
