<?php

use App\Models\ActiveTrip;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

function tripTo(string $name, int $leaveIn = 2, int $ride = 20): array
{
    $depart = now()->addMinutes($leaveIn)->toIso8601String();
    $arrive = now()->addMinutes($leaveIn + $ride)->toIso8601String();

    return [
        'journey' => [
            'mode' => 'transit', 'depart_at' => $depart, 'arrive_at' => $arrive, 'arrive_time' => now()->addMinutes($leaveIn + $ride)->format('H:i'),
            'legs' => [[
                'mode' => 'tram', 'line' => '4', 'depart_at' => $depart, 'arrive_at' => $arrive,
                'from' => ['name' => 'Neumarkt', 'lat' => 50.936, 'lng' => 6.948], 'to' => ['name' => $name],
            ]],
        ],
        'destination' => ['name' => $name, 'lat' => 50.961, 'lng' => 7.004],
        'origin' => ['name' => 'Home street', 'lat' => 50.951, 'lng' => 6.918],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->actingAs($this->user);
});

test('a running trip is never replaced without the person saying so', function () {
    $this->postJson('/api/trip/start', tripTo('Wiener Platz'))->assertSuccessful();

    $this->postJson('/api/trip/start', tripTo('Rheinauhafen', 5))
        ->assertStatus(409)
        ->assertJsonPath('code', 'trip_in_progress')
        ->assertJsonPath('trip.destination', 'Wiener Platz');
    expect($this->user->activeTrip()->sole()->destination['name'])->toBe('Wiener Platz');

    $this->postJson('/api/trip/start', [...tripTo('Rheinauhafen', 5), 'replace' => true])->assertSuccessful();
    expect($this->user->activeTrip()->sole()->destination['name'])->toBe('Rheinauhafen');
});

test('the same trip opened again resumes without asking, and a finished trip needs no confirmation', function () {
    $trip = tripTo('Wiener Platz');
    $this->postJson('/api/trip/start', $trip)->assertSuccessful();
    $this->postJson('/api/trip/start', $trip)->assertSuccessful();

    // Long after it arrived, the old trip is over: a new one starts directly.
    $this->travel(3)->hours();
    $this->postJson('/api/trip/start', tripTo('Rheinauhafen'))->assertSuccessful();
    expect($this->user->activeTrip()->sole()->destination['name'])->toBe('Rheinauhafen');
});

test('another account\'s running trip never blocks or changes mine', function () {
    $other = User::factory()->onboarded()->create();
    $this->actingAs($other)->postJson('/api/trip/start', tripTo('Somewhere else'))->assertSuccessful();

    $this->actingAs($this->user)->postJson('/api/trip/start', tripTo('Wiener Platz'))->assertSuccessful();
    $this->actingAs($this->user)->postJson('/api/trip/end')->assertOk();

    expect(ActiveTrip::query()->where('user_id', $other->id)->sole()->destination['name'])->toBe('Somewhere else');
});

test('only a journey-shaped payload is stored', function (callable $break) {
    $payload = tripTo('Wiener Platz');
    $break($payload);

    $this->postJson('/api/trip/start', $payload)->assertUnprocessable();
    expect($this->user->activeTrip()->exists())->toBeFalse();
})->with([
    'unknown mode' => [function (array &$p) {
        $p['journey']['legs'][0]['mode'] = 'teleport';
    }],
    'too many stops on a leg' => [function (array &$p) {
        $p['journey']['legs'][0]['intermediate_stops'] = array_fill(0, 201, ['name' => 'x']);
    }],
    'an oversized shape' => [function (array &$p) {
        $p['journey']['legs'][0]['polyline'] = str_repeat('a', 100001);
    }],
    'an oversized journey' => [function (array &$p) {
        $p['journey']['notes'] = str_repeat('x', 262145);
    }],
]);

test('where a trip started is kept only while the trip runs', function () {
    $this->postJson('/api/trip/start', tripTo('Wiener Platz'))->assertSuccessful();
    $this->followingRedirects()->get('/settings/profile')->assertInertia(fn ($page) => $page->where('activeTrip.origin.name', 'Home street'));

    // Two hours after arrival the trip is over: not shown, and the row goes.
    $this->travel(2 * 60 + 23)->minutes();
    $this->followingRedirects()->get('/settings/profile')->assertInertia(fn ($page) => $page->where('activeTrip', null));
    expect(ActiveTrip::query()->count())->toBe(0);
});

test('trips nobody came back to are pruned after a day', function () {
    $this->postJson('/api/trip/start', tripTo('Wiener Platz'))->assertSuccessful();
    $fresh = User::factory()->onboarded()->create();

    $this->travel(25)->hours();
    $this->actingAs($fresh)->postJson('/api/trip/start', tripTo('Rheinauhafen'))->assertSuccessful();

    Artisan::call('model:prune', ['--model' => [ActiveTrip::class]]);

    expect(ActiveTrip::query()->pluck('user_id')->all())->toBe([$fresh->id]);
});
