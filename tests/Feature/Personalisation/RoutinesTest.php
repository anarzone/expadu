<?php

use App\Models\Spot;
use App\Models\User;
use App\Models\UserPlace;
use App\Personalisation\Pseudonym;
use App\Personalisation\Routines;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * Routines learned from trips (track, week 3): trips kept with personalisation on, for 90 days,
 * never where they started (only whether that was Home or Work); the same place three times in
 * three weeks at about the same time is offered, never assumed; a confirmed one tells them when
 * to leave; all of it theirs to change, turn down and forget, and erased with personalisation.
 */

beforeEach(function () {
    Notification::fake();
    // Monday 16 November 2026, 20:00.
    $this->travelTo(CarbonImmutable::parse('2026-11-16 20:00', 'Europe/Berlin'));
    $this->user = User::factory()->onboarded()->create();
    // Routine learning is its own yes, with personalisation on.
    $this->user->userSetting()->updateOrCreate([], ['settings' => ['personalisation' => true, Routines::SETTING => true], 'sixteen_or_older' => true]);
    $this->user = $this->user->fresh();
    UserPlace::factory()->create(['user_id' => $this->user->id, 'name' => 'Home', 'category' => 'home', 'lat' => 50.9400, 'lng' => 6.9200]);
    UserPlace::factory()->create(['user_id' => $this->user->id, 'name' => 'Work', 'category' => 'work', 'lat' => 50.9600, 'lng' => 6.9800]);
});

/** A trip to Work from Home, started then. */
function routineTrip(User $user, string $at, float $lat = 50.9601, float $lng = 6.9801, string $name = 'Mediapark 5'): void
{
    app(Routines::class)->started($user, ['name' => $name, 'lat' => $lat, 'lng' => $lng], ['lat' => 50.9401, 'lng' => 6.9201], CarbonImmutable::parse($at, 'Europe/Berlin'));
}

test('🔒 a trip is kept only with personalisation on, with nothing of where it started but Home', function () {
    routineTrip($this->user, '2026-11-16 08:10');
    $row = DB::table('trip_history')->where('subject_key', Pseudonym::for($this->user))->first();

    expect($row)->to_key->toBe('place:work')->to_label->toBe('Work')->from_key->toBe('home')
        ->weekday->toBe(1)->minute->toBe(490)
        ->and(array_keys((array) $row))->not->toContain('from_lat')->not->toContain('lat');

    $off = User::factory()->onboarded()->create();
    routineTrip($off, '2026-11-16 08:10');
    expect(DB::table('trip_history')->where('subject_key', Pseudonym::for($off))->count())->toBe(0);
});

test('🔒 personalisation on is not enough: without their yes to routine learning nothing is kept, and that yes needs personalisation', function () {
    $user = User::factory()->onboarded()->create();
    $user->userSetting()->updateOrCreate([], ['settings' => ['personalisation' => true], 'sixteen_or_older' => true]);
    foreach (['2026-11-11 08:05', '2026-11-12 08:15', '2026-11-16 08:10'] as $at) {
        routineTrip($user->fresh(), $at);
    }
    expect(DB::table('trip_history')->where('subject_key', Pseudonym::for($user))->count())->toBe(0)
        ->and(app(Routines::class)->offer($user->fresh(), CarbonImmutable::now()))->toBeNull();

    $none = User::factory()->onboarded()->create();
    $this->actingAs($none)->putJson('/user-settings', ['settings' => [Routines::SETTING => true]])
        ->assertUnprocessable()->assertJsonValidationErrors('settings.'.Routines::SETTING);
});

test('🔒 a trip to a place of worship, a health service or with such words is never kept, so never a routine', function () {
    $mosque = Spot::factory()->create(['name' => 'DITIB-Zentralmoschee', 'category' => 'place_of_worship', 'lat' => 50.9500, 'lng' => 6.9300]);
    Spot::factory()->create(['name' => 'Praxis Dr. Weber', 'category' => 'doctor', 'lat' => 50.9520, 'lng' => 6.9500]);
    foreach (['2026-11-11 12:30', '2026-11-13 12:30', '2026-11-16 12:35'] as $at) {
        routineTrip($this->user, $at, 50.9500, 6.9300, 'DITIB-Zentralmoschee');
        // An address beside a doctor's practice: its door shares the address.
        routineTrip($this->user, $at, 50.9521, 6.9501, 'Venloer Straße 10');
        routineTrip($this->user, $at, 50.9700, 6.9100, 'Church choir rehearsal');
    }

    expect(DB::table('trip_history')->where('subject_key', Pseudonym::for($this->user))->count())->toBe(0)
        ->and(app(Routines::class)->offer($this->user, CarbonImmutable::now()))->toBeNull()
        ->and($mosque->exists)->toBeTrue();
});

test('🔒 turning routine learning off erases the trips and routines, and nothing more is kept', function () {
    foreach (['2026-11-11 08:05', '2026-11-12 08:15', '2026-11-16 08:10'] as $at) {
        routineTrip($this->user, $at);
    }
    $this->actingAs($this->user)->putJson('/user-settings', ['settings' => [Routines::SETTING => false]])->assertOk();

    expect(DB::table('trip_history')->where('subject_key', Pseudonym::for($this->user))->count())->toBe(0)
        ->and($this->user->fresh()->personalises())->toBeTrue();
    routineTrip($this->user->fresh(), '2026-11-16 18:00');
    expect(DB::table('trip_history')->count())->toBe(0);
});

test('🔒 starting a trip on the phone keeps it for routines', function () {
    $now = now()->toImmutable();
    $this->withToken($this->user->createToken('iPhone')->plainTextToken)->postJson('/api/v1/travel/trip', [
        'journey' => ['mode' => 'transit', 'depart_at' => $now->addMinute()->toIso8601String(), 'arrive_at' => $now->addMinutes(20)->toIso8601String(),
            'legs' => [['mode' => 'tram', 'line' => '12', 'depart_at' => $now->addMinute()->toIso8601String(), 'arrive_at' => $now->addMinutes(20)->toIso8601String(), 'to' => ['name' => 'Mediapark']]]],
        'destination' => ['name' => 'Mediapark 5', 'lat' => 50.9601, 'lng' => 6.9801],
        'origin' => ['name' => 'Your location', 'lat' => 50.9401, 'lng' => 6.9201],
    ])->assertSuccessful();

    expect(DB::table('trip_history')->where('subject_key', Pseudonym::for($this->user))->value('to_key'))->toBe('place:work');
});

test('three weekday trips to Work at about 08:10 are offered as a routine; confirmed, it tells them when to leave', function () {
    routineTrip($this->user, '2026-11-11 08:05');
    routineTrip($this->user, '2026-11-12 08:15');
    routineTrip($this->user, '2026-11-16 08:10');
    $as = $this->withToken($this->user->createToken('iPhone')->plainTextToken);

    $offer = $as->getJson('/api/v1/routines')->assertOk()->assertJsonPath('routines', [])->json('offer');
    expect($offer)->toMatchArray(['to_key' => 'place:work', 'to_label' => 'Work', 'from_key' => 'home', 'weekdays' => [1, 3, 4], 'at' => '08:10', 'times' => 3]);

    // Confirmed for every weekday, as they set it.
    $as->postJson('/api/v1/routines', ['to_key' => 'place:work', 'weekdays' => [1, 2, 3, 4, 5], 'at' => '08:10'])->assertCreated();
    $as->getJson('/api/v1/routines')->assertOk()->assertJsonPath('offer', null)
        ->assertJsonPath('routines.0.to_label', 'Work')->assertJsonPath('routines.0.weekdays', [1, 2, 3, 4, 5]);

    // Tuesday's: five minutes before.
    $moment = collect($as->getJson('/api/v1/moments')->assertOk()->json('moments'))->firstWhere('kind', 'routine');
    expect($moment)->toMatchArray(['id' => 'routine:'.DB::table('routines')->value('id').':2026-11-17', 'title' => 'Time to leave for Work', 'body' => 'You usually leave at 08:10.'])
        ->and(CarbonImmutable::parse($moment['at'])->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-11-17 08:05');
});

test('🔒 the offer shows the trips it rests on: theirs only, to that place only, each a day and a time and nothing more', function () {
    // Work at about 08:10 three times, once at lunch (no part of the routine, still a trip there), and one elsewhere.
    // And one late on Sunday: its day is Cologne's, whatever zone the database keeps time in.
    foreach (['2026-11-11 08:05', '2026-11-12 08:15', '2026-11-16 08:10', '2026-11-13 12:40', '2026-11-15 23:40'] as $at) {
        routineTrip($this->user, $at);
    }
    routineTrip($this->user, '2026-11-14 19:00', 50.9300, 6.9500, 'Kletterfabrik');
    // Someone else's trips to their own Work: the same key, another person.
    $other = User::factory()->onboarded()->create();
    $other->userSetting()->updateOrCreate([], ['settings' => ['personalisation' => true, Routines::SETTING => true], 'sixteen_or_older' => true]);
    UserPlace::factory()->create(['user_id' => $other->id, 'name' => 'Work', 'category' => 'work', 'lat' => 50.9600, 'lng' => 6.9800]);
    routineTrip($other->fresh(), '2026-11-10 07:45');
    routineTrip($other->fresh(), '2026-11-12 07:50');

    $offer = $this->withToken($this->user->createToken('iPhone')->plainTextToken)->getJson('/api/v1/routines')->assertOk()->json('offer');

    expect($offer['times'])->toBe(3)
        ->and($offer['trips'])->toBe([
            ['date' => '2026-11-11', 'weekday' => 3, 'at' => '08:05'],
            ['date' => '2026-11-12', 'weekday' => 4, 'at' => '08:15'],
            ['date' => '2026-11-13', 'weekday' => 5, 'at' => '12:40'],
            ['date' => '2026-11-15', 'weekday' => 7, 'at' => '23:40'],
            ['date' => '2026-11-16', 'weekday' => 1, 'at' => '08:10'],
        ]);
});

test('two trips, or three at times far apart, are no routine; Not now isn\'t offered again for a month', function () {
    routineTrip($this->user, '2026-11-12 08:10');
    routineTrip($this->user, '2026-11-16 08:10');
    expect(app(Routines::class)->offer($this->user, CarbonImmutable::now()))->toBeNull();

    routineTrip($this->user, '2026-11-13 17:30');
    expect(app(Routines::class)->offer($this->user, CarbonImmutable::now()))->toBeNull();

    routineTrip($this->user, '2026-11-11 08:00');
    $as = $this->withToken($this->user->createToken('iPhone')->plainTextToken);
    expect($as->getJson('/api/v1/routines')->json('offer.to_key'))->toBe('place:work');
    $as->postJson('/api/v1/routines/dismiss', ['to_key' => 'place:work'])->assertOk();
    expect($as->getJson('/api/v1/routines')->json('offer'))->toBeNull();

    $this->travel(Routines::DISMISS_DAYS + 1)->days();
    foreach (['2026-12-15 08:10', '2026-12-16 08:10', '2026-12-17 08:10'] as $at) {
        routineTrip($this->user, $at);
    }
    expect(app(Routines::class)->offer($this->user, CarbonImmutable::now())['to_key'])->toBe('place:work');
});

test('🔒 a routine is theirs to change and forget, never anyone else\'s; personalisation off erases trips and routines', function () {
    foreach (['2026-11-11 08:05', '2026-11-12 08:15', '2026-11-16 08:10'] as $at) {
        routineTrip($this->user, $at);
    }
    $as = $this->withToken($this->user->createToken('iPhone')->plainTextToken);
    $id = $as->postJson('/api/v1/routines', ['to_key' => 'place:work', 'weekdays' => [1, 2, 3, 4, 5], 'at' => '08:10'])->assertCreated()->json('id');

    // Each real request loads its own account; the test's guard would keep the one it read.
    app('auth')->forgetGuards();
    $other = $this->withToken(User::factory()->onboarded()->create()->createToken('iPhone')->plainTextToken);
    $other->patchJson("/api/v1/routines/{$id}", ['remind' => false])->assertNotFound();
    app('auth')->forgetGuards();
    $other->deleteJson("/api/v1/routines/{$id}")->assertNotFound();
    app('auth')->forgetGuards();

    $as = $this->withToken($this->user->createToken('iPhone')->plainTextToken);
    $as->patchJson("/api/v1/routines/{$id}", ['at' => '08:20', 'remind' => false])->assertOk();
    expect(app(Routines::class)->confirmed($this->user))->sequence(fn ($r) => $r->at->toBe('08:20')->remind->toBeFalse());

    $this->actingAs($this->user)->putJson('/user-settings', ['settings' => ['personalisation' => false]])->assertOk();
    $subject = Pseudonym::for($this->user);
    expect(DB::table('trip_history')->where('subject_key', $subject)->count())->toBe(0)
        ->and(DB::table('routines')->where('subject_key', $subject)->count())->toBe(0);
});

test('🔒 trips are kept 90 days, then pruned', function () {
    routineTrip($this->user, '2026-08-01 08:10');
    routineTrip($this->user, '2026-11-16 08:10');

    expect(Routines::prune(CarbonImmutable::now()))->toBe(1)
        ->and(DB::table('trip_history')->count())->toBe(1);
});
