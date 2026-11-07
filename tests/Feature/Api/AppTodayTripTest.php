<?php

use App\Day\DayItem;
use App\Day\DayProjection;
use App\Models\ActiveTrip;
use App\Models\PlannedTrip;
use App\Models\User;
use Carbon\CarbonImmutable;

/* The Leave card's trip from the iPhone: the journey always comes from the server's own day, never the phone. */

/** A way leaving in `$in` minutes: a walk to the stop, then tram 1. */
function wayIn(int $in, string $line = '1'): array
{
    $at = fn (int $minutes) => CarbonImmutable::now('Europe/Berlin')->addMinutes($minutes);
    $leg = fn (string $mode, ?string $name, int $from, int $to) => ['mode' => $mode, 'line' => $name, 'from' => ['name' => 'Köln Rudolfplatz'], 'to' => ['name' => 'Stadtgarten'],
        'depart_at' => $at($from)->toIso8601String(), 'arrive_at' => $at($to)->toIso8601String(), 'depart_time' => $at($from)->format('H:i'), 'arrive_time' => $at($to)->format('H:i'), 'duration_min' => $to - $from];

    return ['depart_at' => $at($in)->toIso8601String(), 'arrive_at' => $at($in + 20)->toIso8601String(), 'depart_time' => $at($in)->format('H:i'), 'arrive_time' => $at($in + 20)->format('H:i'),
        'duration_min' => 20, 'mode' => 'transit', 'legs' => [$leg('walk', null, $in, $in + 5), $leg('tram', $line, $in + 6, $in + 20)]];
}

/** The day the server works out: Dinner at Stadtgarten, its way and one other. */
function dayWithWays(): array
{
    $mine = wayIn(10);
    $other = wayIn(20, '3');
    $start = CarbonImmutable::now('Europe/Berlin')->addHour();
    test()->mock(DayProjection::class, function ($mock) use ($mine, $other, $start) {
        $mock->shouldReceive('for')->andReturn(['next_trip' => [
            'target' => ['id' => 'manual:dinner', 'title' => 'Dinner'], 'from' => ['label' => 'Your location', 'lat' => 50.9372, 'lng' => 6.944],
            'leave_time' => $mine['depart_time'], 'journey' => $mine,
            'alternatives' => [['leave_time' => $other['depart_time'], 'journey' => $other]],
        ]]);
        $mock->shouldReceive('itemsOn')->andReturn([new DayItem('manual:dinner', 'plan', 'workspace', 'Dinner', $start, $start->addHours(2), lat: 50.9409, lng: 6.9306)]);
    });

    return [$mine, $other];
}

test('🔒 the trip and its ways need a token and a confirmed address', function () {
    $this->postJson('/api/v1/today/trip', ['mode' => 'transit', 'departs' => '20:00'])->assertUnauthorized();
    $this->deleteJson('/api/v1/today/trip')->assertUnauthorized();
    $this->postJson('/api/v1/today/way', ['mode' => 'transit', 'departs' => '20:00'])->assertUnauthorized();

    $token = User::factory()->unverified()->create()->createToken('iPhone')->plainTextToken;
    $this->withToken($token)->postJson('/api/v1/today/trip', ['mode' => 'transit', 'departs' => '20:00'])->assertForbidden();
});

test('I’m on my way starts the trip on the server’s own way to the plan', function () {
    [$mine] = dayWithWays();
    $user = User::factory()->create();

    $this->withToken($user->createToken('iPhone')->plainTextToken)
        ->postJson('/api/v1/today/trip', ['mode' => 'transit', 'departs' => $mine['legs'][1]['depart_time'], 'lat' => 50.9372, 'lng' => 6.944])
        ->assertOk()->assertJsonPath('trip.destination.name', 'Dinner');

    $trip = ActiveTrip::query()->where('user_id', $user->id)->sole();
    expect($trip->journey['legs'][1]['line'])->toBe('1')
        ->and($trip->target['id'])->toBe('manual:dinner');
});

test('a way that changed since Today showed it isn’t taken', function () {
    dayWithWays();

    $this->withToken(User::factory()->create()->createToken('iPhone')->plainTextToken)
        ->postJson('/api/v1/today/trip', ['mode' => 'transit', 'departs' => '03:33'])
        ->assertUnprocessable()->assertJsonValidationErrors('way');
});

test('Use this keeps another way for the plan, and Undo takes it away again', function () {
    [, $other] = dayWithWays();
    $user = User::factory()->create();
    $as = $this->withToken($user->createToken('iPhone')->plainTextToken);

    $undo = $as->postJson('/api/v1/today/way', ['mode' => 'transit', 'departs' => $other['legs'][1]['depart_time']])->assertOk()->json('undo');
    expect(PlannedTrip::query()->where('user_id', $user->id)->sole()->journey['legs'][1]['line'])->toBe('3');

    $as->deleteJson("/api/v1/today/way/{$undo}")->assertOk();
    expect(PlannedTrip::query()->where('user_id', $user->id)->exists())->toBeFalse();
    $as->deleteJson("/api/v1/today/way/{$undo}")->assertStatus(410);
});

test('🔒 someone else’s Undo does nothing to your way', function () {
    [, $other] = dayWithWays();
    $owner = User::factory()->create();
    $undo = $this->withToken($owner->createToken('iPhone')->plainTextToken)
        ->postJson('/api/v1/today/way', ['mode' => 'transit', 'departs' => $other['legs'][1]['depart_time']])->json('undo');

    // A fresh request as the other person, not the guard's remembered owner.
    app('auth')->forgetGuards();
    $this->withToken(User::factory()->create()->createToken('iPhone')->plainTextToken)
        ->deleteJson("/api/v1/today/way/{$undo}")->assertStatus(410);
    expect(PlannedTrip::query()->where('user_id', $owner->id)->exists())->toBeTrue();
});
