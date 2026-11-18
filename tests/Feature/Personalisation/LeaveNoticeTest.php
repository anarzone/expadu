<?php

use App\Models\User;
use App\Models\UserPlace;
use App\Personalisation\Pseudonym;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\JourneyResult;
use App\Transit\Dto\Leg;
use App\Transit\Dto\Place;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Noticing when they leave Home or Work: the phone says only which place and when. On a routine's
 * day and time, leaving gets the next way in plain words; a trip between the two is kept for
 * routines, marked as noticed. Its own yes, with routine learning on; off erases what it noticed.
 */

beforeEach(function () {
    // Wednesday 18 November 2026, 08:09 in Cologne.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 08:09', 'Europe/Berlin'));
});

function commuter(bool $noticing = true): User
{
    $user = User::factory()->onboarded()->create();
    test()->actingAs($user)->putJson('/user-settings', ['settings' => ['personalisation' => true], 'sixteen_or_older' => true])->assertOk();
    test()->actingAs($user)->putJson('/user-settings', ['settings' => ['routine_learning' => true]])->assertOk();
    if ($noticing) {
        test()->actingAs($user)->putJson('/user-settings', ['settings' => ['leave_notice' => true]])->assertOk();
    }
    UserPlace::factory()->create(['user_id' => $user->id, 'category' => 'home', 'name' => 'Home', 'lat' => 50.931, 'lng' => 6.938]);
    UserPlace::factory()->create(['user_id' => $user->id, 'category' => 'work', 'name' => 'Work', 'lat' => 50.949, 'lng' => 6.944]);
    DB::table('routines')->insert(['subject_key' => Pseudonym::for($user), 'to_key' => 'place:work', 'to_label' => 'Work', 'from_key' => 'home',
        'weekdays' => json_encode([1, 3, 4]), 'at' => '08:10', 'remind' => true, 'created_at' => now(), 'updated_at' => now()]);

    return $user->fresh();
}

function tramIn(int $minutes): void
{
    $now = CarbonImmutable::now('Europe/Berlin');
    $at = fn (int $m) => $now->addMinutes($m);
    $stop = new Place('Zülpicher Platz', new GeoPoint(50.932, 6.939));
    $legs = [
        new Leg('walk', new Place('Home', new GeoPoint(50.931, 6.938)), $stop, $at(0), $at(2), 2),
        new Leg('tram', $stop, new Place('Mediapark', new GeoPoint(50.948, 6.943)), $at($minutes), $at(22), 22 - $minutes, '12'),
    ];
    test()->mock(RouteService::class, function ($mock) use ($legs, $at) {
        $mock->shouldReceive('withTimeBudget')->andReturnSelf();
        $mock->shouldReceive('plan')->andReturn(new JourneyResult([new Journey($legs, $at(0), $at(22), 22, 0)], 'transitous'));
    });
}

function onThePhone(User $user)
{
    // Each phone request reads the person afresh, as a real one does.
    app('auth')->forgetGuards();

    return test()->withToken($user->createToken('iPhone')->plainTextToken);
}

test('leaving home on a routine morning tells them, in plain words, when their tram goes', function () {
    $user = commuter();
    tramIn(4);

    onThePhone($user)->getJson('/api/v1/routines/leaving?from=home')->assertOk()
        ->assertJsonPath('notice.title', 'Heading to work?')
        ->assertJsonPath('notice.body', 'Your tram, line 12, leaves Zülpicher Platz in 4 minutes. You’ll be at work by 08:31.');
});

test('no routine runs now, or it isn’t one of its days: nothing to say', function () {
    $user = commuter();
    tramIn(4);

    // Leaving work in the morning: no routine from there now.
    onThePhone($user)->getJson('/api/v1/routines/leaving?from=work')->assertNoContent();
    // Tuesday isn't one of its days.
    $this->travelTo(CarbonImmutable::parse('2026-11-17 08:09', 'Europe/Berlin'));
    onThePhone($user)->getJson('/api/v1/routines/leaving?from=home')->assertNoContent();
    // Too long after the usual time.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 11:30', 'Europe/Berlin'));
    onThePhone($user)->getJson('/api/v1/routines/leaving?from=home')->assertNoContent();
});

test('a noticed trip from home to work is kept for routines, and only from their own two places', function () {
    $user = commuter();

    onThePhone($user)->postJson('/api/v1/routines/noticed', ['from' => 'home', 'to' => 'work', 'left_at' => '2026-11-18T08:09:00+01:00'])->assertCreated();
    onThePhone($user)->postJson('/api/v1/routines/noticed', ['from' => 'home', 'to' => 'home', 'left_at' => '2026-11-18T08:09:00+01:00'])->assertUnprocessable();
    onThePhone($user)->postJson('/api/v1/routines/noticed', ['from' => 'gym', 'to' => 'work', 'left_at' => '2026-11-18T08:09:00+01:00'])->assertUnprocessable();

    $row = DB::table('trip_history')->where('subject_key', Pseudonym::for($user))->sole();
    expect($row->to_key)->toBe('place:work')->and($row->from_key)->toBe('home')->and($row->source)->toBe('noticed')
        ->and($row->weekday)->toBe(3)->and($row->minute)->toBe(8 * 60 + 9);
});

test('🔒 without its own yes nothing is said or kept, and turning it off erases only what it noticed', function () {
    $user = commuter(noticing: false);
    tramIn(4);

    onThePhone($user)->getJson('/api/v1/routines/leaving?from=home')->assertNoContent();
    onThePhone($user)->postJson('/api/v1/routines/noticed', ['from' => 'home', 'to' => 'work', 'left_at' => '2026-11-18T08:09:00+01:00'])->assertUnprocessable();
    expect(DB::table('trip_history')->count())->toBe(0);

    test()->actingAs($user)->putJson('/user-settings', ['settings' => ['leave_notice' => true]])->assertOk();
    onThePhone($user->fresh())->postJson('/api/v1/routines/noticed', ['from' => 'home', 'to' => 'work', 'left_at' => '2026-11-18T08:09:00+01:00'])->assertCreated();
    DB::table('trip_history')->insert(['subject_key' => Pseudonym::for($user), 'to_key' => 'place:work', 'to_label' => 'Work', 'from_key' => 'home',
        'started_at' => '2026-11-17 08:05:00+01:00', 'weekday' => 2, 'minute' => 485]);

    test()->actingAs($user)->putJson('/user-settings', ['settings' => ['leave_notice' => false]])->assertOk();
    expect(DB::table('trip_history')->pluck('source')->all())->toBe(['app']);
});

test('🔒 it needs learning from trips on, and goes off with it', function () {
    $user = User::factory()->onboarded()->create();
    test()->actingAs($user)->putJson('/user-settings', ['settings' => ['personalisation' => true], 'sixteen_or_older' => true])->assertOk();
    test()->actingAs($user)->putJson('/user-settings', ['settings' => ['leave_notice' => true]])->assertUnprocessable();

    $user = commuter();
    test()->actingAs($user)->putJson('/user-settings', ['settings' => ['routine_learning' => false]])->assertOk();
    expect($user->fresh()->setting('leave_notice'))->toBeFalse();
});
