<?php

use App\ContextEngine\DayImportance;
use App\Models\Event;
use App\Models\EventReminder;
use App\Models\PlannedTrip;
use App\Models\User;
use App\Models\UserPlace;
use App\Notifications\EventOccurrenceReminder;
use App\Notifications\PlannedTripLeaveReminder;
use App\Support\NotificationThrottle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;

// One gate for every push (owner, 26 Oct 2026): quiet hours, the day's budget by how important
// the day is, delays and weather only on their way, what they asked for always on time.

uses()->group('context-engine');

/** Wednesday 11 November 2026, Berlin time. */
function gateAt(string $time, string $date = '2026-11-11'): CarbonImmutable
{
    return CarbonImmutable::parse("{$date} {$time}", 'Europe/Berlin');
}

function gateTrip(User $user, string $leaves, array $extra = []): PlannedTrip
{
    $at = gateAt($leaves);

    return PlannedTrip::query()->create([
        'user_id' => $user->id, 'date' => $at->toDateString(), 'target_id' => 'plan-'.uniqid(), 'title' => 'Blue Card appointment',
        'journey' => [], 'remind' => true, 'depart_at' => $at, 'arrive_at' => $at->addMinutes(40), ...$extra,
    ]);
}

function gateWork(User $user, string $arriveBy, string $days = 'weekdays'): UserPlace
{
    return UserPlace::factory()->create(['user_id' => $user->id, 'name' => 'Work', 'category' => 'work', 'arrive_by' => $arriveBy, 'day_mode' => $days]);
}

beforeEach(function () {
    Notification::fake();
    $this->user = User::factory()->create();
});

test('what they asked for goes on time in quiet hours when it is close, and waits until morning when it is not', function () {
    // A leave reminder at 23:30 for a way leaving at 23:34.
    $this->travelTo(gateAt('23:20'));
    $trip = gateTrip($this->user, '23:34');
    $this->travelTo(gateAt('23:30'));
    $this->artisan('travel:send-leave-reminders')->assertSuccessful();
    Notification::assertSentToTimes($this->user, PlannedTripLeaveReminder::class, 1);
    expect($trip->fresh()->reminded_at)->not->toBeNull();

    // "Remind me 3½ hours before" an event at 09:00: due at 05:30, said at 06:00.
    $event = Event::factory()->for($this->user, 'organiser')->create(['starts_at' => '2026-11-12 09:00:00', 'ends_at' => '2026-11-12 10:00:00', 'recurrence' => null]);
    $reminder = EventReminder::query()->create(['user_id' => $this->user->id, 'event_id' => $event->id, 'occurrence_start' => '2026-11-12 09:00:00',
        'offset_minutes' => 210, 'remind_at' => '2026-11-12 05:30:00']);
    $this->travelTo(gateAt('05:30', '2026-11-12'));
    $this->artisan('events:send-occurrence-reminders')->assertSuccessful();
    Notification::assertNotSentTo($this->user, EventOccurrenceReminder::class);
    expect($reminder->fresh()->sent_at)->toBeNull();

    $this->travelTo(gateAt('06:00', '2026-11-12'));
    $this->artisan('events:send-occurrence-reminders')->assertSuccessful();
    Notification::assertSentToTimes($this->user, EventOccurrenceReminder::class, 1);
    expect($reminder->fresh()->sent_at)->not->toBeNull();

    // Getting off, during the way: now.
    $this->travelTo(gateAt('23:50'));
    expect(NotificationThrottle::decide($this->user, 'trip_get_off', 'moderate', now())->send)->toBeTrue();
});

test('nothing else pushes in quiet hours but a critical disruption; the brief and the app keep the rest', function () {
    $this->travelTo(gateAt('23:00'));

    expect(NotificationThrottle::decide($this->user, 'transit_delay', 'major')->reason)->toBe('quiet_hours')
        ->and(NotificationThrottle::decide($this->user, 'transit_disruption', 'major')->reason)->toBe('quiet_hours')
        ->and(NotificationThrottle::decide($this->user, 'transit_disruption', 'critical')->send)->toBeTrue()
        ->and(NotificationThrottle::decide($this->user, 'event_tomorrow')->reason)->toBe('in_brief')
        ->and(NotificationThrottle::decide($this->user, 'rhine_level')->reason)->toBe('in_app_only');
});

test('delays and weather push only on their way: from Work’s arrive-by on its days, else the old windows', function () {
    gateWork($this->user, '10:30');
    $at = fn (string $time, string $date = '2026-11-11') => $this->travelTo(gateAt($time, $date));

    $at('07:00');
    expect(NotificationThrottle::decide($this->user, 'weather_alert', 'major')->reason)->toBe('outside_commute');
    $at('09:00');
    expect(NotificationThrottle::decide($this->user, 'weather_alert', 'major')->send)->toBeTrue();
    // No evening arrive-by: the old evening window.
    $at('17:00');
    expect(NotificationThrottle::decide($this->user, 'transit_delay', 'major')->send)->toBeTrue();
    // Saturday, not a work day: the old morning window.
    $at('07:00', '2026-11-14');
    expect(NotificationThrottle::decide($this->user, 'weather_alert', 'major')->send)->toBeTrue();
});

test('the day’s budget is five, eight on an important day; what they asked for is never held back by it', function () {
    $this->travelTo(gateAt('12:00'));
    Redis::set("notif_throttle:day:{$this->user->id}:2026-11-11", 5);

    expect(NotificationThrottle::decide($this->user, 'transit_disruption', 'major')->reason)->toBe('daily_budget')
        ->and(NotificationThrottle::decide($this->user, 'planned_trip_leave', 'moderate', now()->addMinutes(5))->send)->toBeTrue();

    // Work, a planned way and it's cancelled: an important day, with room for three more.
    gateWork($this->user, '09:00');
    gateTrip($this->user, '17:30', ['alerted_kind' => 'cancelled']);
    expect(app(DayImportance::class)->band($this->user, now()))->toBe(DayImportance::IMPORTANT)
        ->and(NotificationThrottle::decide($this->user, 'transit_disruption', 'major')->send)->toBeTrue();
    Redis::set("notif_throttle:day:{$this->user->id}:2026-11-11", 8);
    expect(NotificationThrottle::decide($this->user, 'transit_disruption', 'major')->reason)->toBe('daily_budget');
});

test('pushes come 15 minutes apart; a later one waits for its slot', function () {
    $this->travelTo(gateAt('12:00'));
    NotificationThrottle::recordSent($this->user);
    $this->travelTo(gateAt('12:10'));

    $held = NotificationThrottle::decide($this->user, 'transit_disruption', 'major');
    expect($held->later)->toBeTrue()->and($held->retryAt?->format('H:i'))->toBe('12:15');
});

test('a day is quiet, normal or important by its commitments times the worst risk to them, and is worked out again when they change', function () {
    $this->travelTo(gateAt('08:00'));
    $importance = app(DayImportance::class);

    expect($importance->for($this->user, now())['band'])->toBe(DayImportance::QUIET);

    gateWork($this->user, '09:00');
    expect($importance->for($this->user, now()))->toMatchArray(['score' => 3.0, 'band' => DayImportance::NORMAL]);

    $trip = gateTrip($this->user, '17:30');
    expect($importance->for($this->user, now()))->toMatchArray(['score' => 6.0, 'band' => DayImportance::NORMAL]);

    // The way is late: twice the day.
    $trip->update(['alerted_kind' => 'late']);
    expect($importance->for($this->user, now()))->toMatchArray(['score' => 12.0, 'band' => DayImportance::IMPORTANT, 'risks' => ['late']]);

    // Saturday: no work, no way.
    expect($importance->for($this->user, gateAt('08:00', '2026-11-14'))['band'])->toBe(DayImportance::QUIET);
});

test('their preference holds on every path: a get-off for someone who turned transit off never goes', function () {
    $this->travelTo(gateAt('12:00'));
    $this->user->notificationPreference()->create(['preferences' => ['transit' => false]]);

    expect(NotificationThrottle::decide($this->user->fresh(), 'trip_get_off', 'moderate', now())->reason)->toBe('preference_off')
        ->and(NotificationThrottle::decide($this->user->fresh(), 'planned_trip_change', 'critical', now())->reason)->toBe('preference_off');
});
