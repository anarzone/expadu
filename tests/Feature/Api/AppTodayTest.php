<?php

use App\Http\Resources\Api\V1\TodayResource;
use App\Models\User;

/* Today for the iPhone: only a confirmed account reads it, and only what's real is in it. */

/** What the resource sends the app, from the services' own shapes. */
function todayShaped(array $day = [], ?array $needs = null, array $events = [], ?array $weather = null): array
{
    return json_decode((new TodayResource([
        'day' => ['date' => '2026-09-30', 'now' => '2026-09-30T13:05:00+02:00', 'items' => [], 'next_trip' => null, ...$day],
        'needs' => $needs,
        'discovery' => ['part' => 'afternoon', 'events' => $events, 'around' => null],
        'coming_up' => ['count' => 0, 'items' => [], 'tomorrow' => ['date' => '2026-10-01', 'plans' => []]],
        'weather' => $weather ?? ['available' => false, 'temperature' => 0, 'condition' => 'Unavailable', 'icon' => 'cloudy'],
    ]))->toJson(), true);
}

test('🔒 Today needs a token and a confirmed address', function () {
    $this->getJson('/api/v1/today')->assertUnauthorized();

    $unconfirmed = User::factory()->unverified()->create();
    $this->withToken($unconfirmed->createToken('iPhone')->plainTextToken)->getJson('/api/v1/today')->assertForbidden();
});

test('a new account’s Today reads the real services and has no plans and no leave card', function () {
    $this->withToken(User::factory()->create()->createToken('iPhone')->plainTextToken)->getJson('/api/v1/today')->assertOk()
        ->assertJsonPath('plans', [])
        ->assertJsonPath('leave', null)
        ->assertJsonStructure(['date', 'now', 'weather', 'needs', 'ideas', 'ideas_part', 'tomorrow' => ['date', 'plans']]);
});

test('the next plan comes with when to leave and the line that takes them, in its colour', function () {
    $today = todayShaped([
        'items' => [['id' => 'a', 'title' => 'EU Blue Card appointment', 'subtitle' => null, 'start_time' => '14:00', 'end_time' => '14:30', 'location' => ['label' => 'Mülheim Wiener Platz'], 'state' => 'upcoming', 'completed' => false]],
        'next_trip' => [
            'target' => ['title' => 'EU Blue Card appointment', 'start_time' => '14:00'],
            'status' => 'fits', 'leave_time' => '13:10', 'leave_in_min' => 5, 'arrive_time' => '13:48', 'spare_min' => 12, 'time_basis' => 'live',
            'journey' => ['legs' => [
                ['mode' => 'walk', 'line' => null, 'from' => ['name' => 'Home']],
                ['mode' => 'tram', 'line' => '4', 'from' => ['name' => 'Köln Neumarkt'], 'depart_time' => '13:14'],
            ]],
        ],
    ], weather: ['available' => true, 'temperature' => 6.4, 'condition' => 'Sunny', 'icon' => 'sunny']);

    expect($today['weather']['temperature'])->toBe(6)
        ->and($today['plans'][0]['place'])->toBe('Mülheim Wiener Platz')
        ->and($today['leave'])->toMatchArray(['leave_time' => '13:10', 'line' => '4', 'color' => '#A01E5A', 'departs' => '13:14', 'from' => 'Neumarkt', 'live' => true, 'spare_min' => 12]);
});

test('a day with nothing real in it says so: no weather, no plans, no leave card', function () {
    expect(todayShaped())->toMatchArray(['weather' => null, 'plans' => [], 'leave' => null, 'needs' => [], 'ideas' => []]);
});

test('ideas are at most six, and the notes that need the person at most three', function () {
    $event = fn (int $id) => ['id' => $id, 'title' => "Event {$id}", 'meta' => 'Tonight 20:00', 'category_label' => 'Music', 'emoji' => '🎵', 'occurrence_start' => '2026-09-30T20:00:00+02:00', 'price_text' => 'Free', 'venue' => ['name' => 'Stadtgarten', 'veedel' => 'Neustadt-Nord']];
    $need = fn (int $id) => ['id' => "n{$id}", 'tier' => 1, 'kind' => 'paper', 'headline' => "Note {$id}", 'detail' => null, 'at' => null, 'severity' => 'attention', 'href' => null];
    $today = todayShaped(needs: ['items' => array_map($need, range(1, 5)), 'checked_at' => 'now'], events: array_map($event, range(1, 9)));

    expect($today['ideas'])->toHaveCount(6)
        ->and($today['ideas'][0]['place'])->toBe('Stadtgarten')
        ->and($today['needs'])->toHaveCount(3);
});
