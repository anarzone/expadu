<?php

use App\Models\ActiveTrip;
use App\Models\ComposerWorkspace;
use App\Models\User;

function datedTripPayload(): array
{
    return [
        'journey' => [
            'mode' => 'transit', 'depart_at' => now()->addMinute()->toIso8601String(),
            'arrive_at' => now()->addMinutes(20)->toIso8601String(),
            'legs' => [[
                'mode' => 'tram', 'line' => '1',
                'depart_at' => now()->addMinute()->toIso8601String(),
                'arrive_at' => now()->addMinutes(20)->toIso8601String(),
                'to' => ['name' => 'Destination'],
            ]],
        ],
        'destination' => ['name' => 'Destination', 'lat' => 50.94, 'lng' => 6.95],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->actingAs($this->user);
});

test('an ended or already departed transit itinerary cannot start', function () {
    $payload = datedTripPayload();
    $this->travel(2)->minutes();
    $this->postJson('/api/trip/start', $payload)->assertUnprocessable();
    expect($this->user->activeTrip()->exists())->toBeFalse();

    $this->travel(25)->minutes();
    $this->postJson('/api/trip/start', $payload)->assertUnprocessable();
    expect($this->user->activeTrip()->exists())->toBeFalse();
});

test('malformed or backwards leg times do not replace an existing active trip', function ($badTime) {
    $payload = datedTripPayload();
    $this->postJson('/api/trip/start', $payload)->assertSuccessful();
    $before = ActiveTrip::where('user_id', $this->user->id)->sole()->toArray();
    $payload['journey']['legs'][0]['arrive_at'] = $badTime;
    $this->postJson('/api/trip/start', $payload)->assertUnprocessable();
    expect(ActiveTrip::where('user_id', $this->user->id)->sole()->toArray())->toBe($before);
})->with(['now', '2026-02-30T12:00:00+01:00', '2000-01-01T00:00:00Z']);

test('reopening the same current trip keeps its original start after boarding', function () {
    $payload = datedTripPayload();
    $this->postJson('/api/trip/start', $payload)->assertSuccessful();
    $startedAt = ActiveTrip::where('user_id', $this->user->id)->sole()->started_at->toIso8601String();
    $this->travel(2)->minutes();
    $this->postJson('/api/trip/start', $payload)->assertSuccessful();
    expect(ActiveTrip::where('user_id', $this->user->id)->sole()->started_at->toIso8601String())->toBe($startedAt);
});

test('a trip keeps the plan it is for only when that plan is on your own day', function () {
    $start = now('Europe/Berlin')->addHour()->startOfMinute();
    $day = fn (User $user, string $id, string $name) => ComposerWorkspace::query()->updateOrCreate(['user_id' => $user->id], [
        'revision' => 1,
        'state' => [
            'approval' => 'ask', 'active' => 'initial', 'focus' => null, 'draft' => null, 'versions' => [],
            'conversations' => [['id' => 'initial', 'title' => 'New conversation', 'created_at' => now()->toIso8601String(), 'messages' => []]],
            'plans' => [$start->toDateString() => ['date' => $start->toDateString(), 'slots' => [[
                'id' => $id, 'type' => 'personal', 'name' => $name, 'category' => 'appointment', 'swappable' => false,
                'start_time' => $start->format('H:i'), 'end_time' => $start->addHour()->format('H:i'),
                'start_at' => $start->toIso8601String(), 'end_at' => $start->addHour()->toIso8601String(),
            ]], 'constraints' => ['window_start' => $start->toDateString().'T00:00:00', 'window_end' => $start->toDateString().'T23:59:00']]],
        ],
    ]);
    $day(User::factory()->onboarded()->create(), 'their-dentist', 'Their dentist');
    $day($this->user, 'my-class', 'Language class');

    $theirs = [...datedTripPayload(), 'plan' => ['date' => $start->toDateString(), 'target_id' => 'their-dentist']];
    $this->postJson('/api/trip/start', $theirs)->assertUnprocessable()->assertJsonValidationErrors('plan');
    expect($this->user->activeTrip()->exists())->toBeFalse();

    $mine = [...datedTripPayload(), 'plan' => ['date' => $start->toDateString(), 'target_id' => 'my-class']];
    $this->postJson('/api/trip/start', $mine)
        ->assertSuccessful()
        ->assertJsonPath('trip.target.id', 'my-class')
        ->assertJsonPath('trip.target.title', 'Language class')
        ->assertJsonPath('trip.target.start_at', $start->toIso8601String());
});
