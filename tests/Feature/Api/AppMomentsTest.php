<?php

use App\Models\Event;
use App\Models\EventReminder;
use App\Models\NotificationPreference;
use App\Models\PlannedTrip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
 * What the iPhone schedules as its own notifications (track, week 2): no push without the paid
 * Apple account, so the next 36 hours come as data, each with a stable id and the words a push
 * would carry, only the kinds they keep on and outside their quiet hours (what they asked for aside).
 */

beforeEach(function () {
    Notification::fake();
    // Monday 16 November 2026, 17:00.
    $this->travelTo(CarbonImmutable::parse('2026-11-16 17:00', 'Europe/Berlin'));
    $this->user = User::factory()->create();
    $this->as = $this->withToken($this->user->createToken('iPhone')->plainTextToken);
});

function momentTrip(User $user, string $departs, bool $remind = true): PlannedTrip
{
    $at = CarbonImmutable::parse($departs, 'Europe/Berlin');

    return PlannedTrip::query()->create([
        'user_id' => $user->id, 'date' => $at->toDateString(), 'target_id' => 'plan-'.uniqid(), 'title' => 'Blue Card appointment',
        'journey' => ['legs' => [['mode' => 'walk'], ['mode' => 'tram', 'line' => '4', 'depart_at' => $at->addMinutes(4)->toIso8601String()]]],
        'remind' => $remind, 'depart_at' => $at, 'arrive_at' => $at->addMinutes(40),
    ]);
}

function momentReminder(User $user, string $remindAt, string $starts, ?string $sent = null): EventReminder
{
    $event = Event::factory()->create(['title' => 'Jazz im Stadtgarten', 'title_en' => null, 'location_name' => 'Stadtgarten',
        'starts_at' => $starts, 'ends_at' => CarbonImmutable::parse($starts)->addHours(2), 'recurrence' => null]);

    return EventReminder::query()->create(['user_id' => $user->id, 'event_id' => $event->id, 'occurrence_start' => $starts,
        'offset_minutes' => 60, 'remind_at' => CarbonImmutable::parse($remindAt, 'Europe/Berlin'), 'sent_at' => $sent]);
}

test('🔒 the moments need a token and a confirmed address', function () {
    $this->withoutToken()->getJson('/api/v1/moments')->assertUnauthorized();
    $unverified = User::factory()->unverified()->create();
    $this->withToken($unverified->createToken('iPhone')->plainTextToken)->getJson('/api/v1/moments')->assertForbidden();
});

test('a planned way with its reminder on is "time to leave" five minutes before, in a push\'s words; without it, nothing', function () {
    $trip = momentTrip($this->user, '2026-11-16 18:30');
    momentTrip($this->user, '2026-11-16 19:30', remind: false);
    momentTrip($this->user, '2026-11-18 09:00');

    $moments = collect($this->as->getJson('/api/v1/moments')->assertOk()
        ->assertJsonPath('quiet_from', NotificationPreference::QUIET_FROM)
        ->json('moments'))->where('kind', 'leave')->values()->all();

    expect($moments)->toHaveCount(1)
        ->and($moments[0])->toMatchArray(['kind' => 'leave', 'title' => 'Time to leave for Blue Card appointment', 'body' => 'Leave at 18:30 · Tram 4 at 18:34', 'link' => '/travel'])
        ->and(CarbonImmutable::parse($moments[0]['at'])->setTimezone('Europe/Berlin')->format('H:i'))->toBe('18:25')
        ->and($moments[0]['id'])->toBe("leave:{$trip->id}:".$trip->depart_at->getTimestamp());
});

test('an event they asked to be reminded of comes when they asked, unless they turned event reminders off or it was sent', function () {
    momentReminder($this->user, '2026-11-17 19:00', '2026-11-17 20:00');
    momentReminder($this->user, '2026-11-17 12:00', '2026-11-17 13:00', sent: '2026-11-16 12:00:00');

    $moments = collect($this->as->getJson('/api/v1/moments')->assertOk()->json('moments'))->where('kind', 'event')->values()->all();
    expect($moments)->toHaveCount(1)
        ->and($moments[0])->toMatchArray(['kind' => 'event', 'title' => 'Jazz im Stadtgarten', 'body' => 'At Stadtgarten. Starts at 20:00.']);

    $this->user->notificationPreference()->updateOrCreate(['user_id' => $this->user->id], ['preferences' => [...NotificationPreference::defaults(), 'events' => false]]);
    app('auth')->forgetGuards();
    expect(collect($this->as->getJson('/api/v1/moments')->json('moments'))->where('kind', 'event'))->toBeEmpty();
});

test('🔒 quiet hours hold all but what they asked to be told when to leave for', function () {
    momentReminder($this->user, '2026-11-16 23:00', '2026-11-17 00:30');
    momentTrip($this->user, '2026-11-16 23:30');

    $moments = $this->as->getJson('/api/v1/moments')->assertOk()->json('moments');

    // The reminder at 23:00 waits for the morning; the way at 23:30 is told five minutes before.
    expect(array_column($moments, 'kind'))->toContain('leave')->not->toContain('event')
        ->and(collect($moments)->every(fn (array $m): bool => $m['kind'] === 'leave' || CarbonImmutable::parse($m['at'])->setTimezone('Europe/Berlin')->format('H:i') < '22:00'))->toBeTrue();
});

test('the morning brief of a day with something on is there in its template\'s words, and not when briefs are off', function () {
    // Tomorrow's 09:00 appointment: the brief comes 45 minutes before they set off.
    momentTrip($this->user, '2026-11-17 09:00');

    $brief = collect($this->as->getJson('/api/v1/moments')->assertOk()->json('moments'))->firstWhere('kind', 'brief');
    expect($brief)->not->toBeNull()
        ->and($brief['title'])->toBe('Your Tuesday')
        ->and($brief['id'])->toBe('brief:morning:2026-11-17')
        ->and(CarbonImmutable::parse($brief['at'])->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-11-17 08:15')
        ->and($brief['body'])->toContain('Blue Card appointment');

    $this->user->notificationPreference()->updateOrCreate(['user_id' => $this->user->id], ['preferences' => [...NotificationPreference::defaults(), 'brief' => false]]);
    app('auth')->forgetGuards();
    expect(collect($this->as->getJson('/api/v1/moments')->json('moments'))->where('kind', 'brief'))->toBeEmpty();
});
