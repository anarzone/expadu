<?php

use App\Models\Event;
use App\Models\User;
use App\Personalisation\Pseudonym;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Adding an event to their plan from Today's + is their own choice, learned like a save
 * (added_to_plan); Undo takes it back. Only with personalisation on, never a sensitive event.
 */

beforeEach(function () {
    config(['events.geocoding.expected_polygon_count' => 1]);
    DB::table('veedels')->insert(['name' => 'Workspace Boundary', 'bezirk' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
    DB::statement("UPDATE veedels SET boundary = ST_Multi(ST_GeomFromText('POLYGON((6.90 50.90, 7.00 50.90, 7.00 51.00, 6.90 51.00, 6.90 50.90))', 4326)) WHERE name = 'Workspace Boundary'");
    $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00', 'Europe/Berlin'));
    $this->day = CarbonImmutable::now('Europe/Berlin')->startOfDay();
});

function plannableEvent(CarbonImmutable $day, array $attributes = []): Event
{
    $event = Event::factory()->create(['starts_at' => $day->setTime(15, 0), 'ends_at' => $day->setTime(16, 0), ...$attributes]);
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(6.9603, 50.9375), 4326)::geography WHERE id = ?', [$event->id]);

    return $event;
}

function personalised(): User
{
    $user = User::factory()->onboarded()->create();
    $user->userSetting()->updateOrCreate([], ['settings' => ['personalisation' => true], 'sixteen_or_older' => true]);

    return $user->fresh();
}

function addFromToday(User $user, Event $event, CarbonImmutable $day, int $revision = 0): array
{
    return test()->withToken($user->createToken('iPhone')->plainTextToken)
        ->postJson('/api/v1/day/command', ['revision' => $revision, 'action' => 'add', 'date' => $day->format('Y-m-d'), 'candidate' => "event:{$event->id}"])
        ->assertOk()->json();
}

test('an event added from Today is learned as theirs; Undo takes it back', function () {
    $user = personalised();
    $event = plannableEvent($this->day);

    $reply = addFromToday($user, $event, $this->day);
    $rows = fn () => DB::table('user_signals')->where('subject_key', Pseudonym::for($user))->where('action', 'added_to_plan')->get();
    expect($rows())->toHaveCount(1)
        ->and($rows()->first())->surface->toBe('today')->item_type->toBe('event')->item_id->toBe($event->id);

    app('auth')->forgetGuards();
    $this->withToken($user->createToken('iPhone')->plainTextToken)
        ->postJson('/api/v1/day/command', ['revision' => $reply['revision'], 'action' => 'undo', 'change' => $reply['change']['id']])->assertOk();
    expect($rows())->toHaveCount(0);
});

test('🔒 nothing is learned from an add with personalisation off, or from a sensitive event', function () {
    $off = User::factory()->onboarded()->create();
    addFromToday($off, plannableEvent($this->day), $this->day);
    expect(DB::table('user_signals')->where('subject_key', Pseudonym::for($off))->count())->toBe(0);

    app('auth')->forgetGuards();
    $on = personalised();
    addFromToday($on, plannableEvent($this->day, ['sensitive' => true]), $this->day);
    expect(DB::table('user_signals')->where('subject_key', Pseudonym::for($on))->count())->toBe(0);
});
