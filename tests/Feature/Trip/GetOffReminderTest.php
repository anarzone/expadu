<?php

use App\Jobs\SendTripStopReminder;
use App\Models\User;
use App\Notifications\GetOffReminderNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->actingAs($this->user);
});

/** A two-transit-leg trip (change + final exit) with future arrival times. */
function reminderPayload(array $overrides = []): array
{
    $change = now()->addMinutes(10)->toIso8601String();
    $exit = now()->addMinutes(20)->toIso8601String();

    return array_replace_recursive([
        'journey' => [
            'depart_at' => now()->toIso8601String(),
            'arrive_at' => now()->addMinutes(21)->toIso8601String(),
            'legs' => [
                ['mode' => 'walk', 'depart_at' => now()->toIso8601String(), 'arrive_at' => now()->addMinute()->toIso8601String(), 'to' => ['name' => 'Board Stop']],
                [
                    'mode' => 'tram',
                    'line' => '12',
                    'depart_at' => now()->addMinute()->toIso8601String(),
                    'arrive_at' => $change,
                    'to' => ['name' => 'Change Stop'],
                ],
                [
                    'mode' => 'bus',
                    'line' => '140',
                    'depart_at' => $change,
                    'arrive_at' => $exit,
                    'to' => ['name' => 'Exit Stop'],
                ],
                ['mode' => 'walk', 'depart_at' => $exit, 'arrive_at' => now()->addMinutes(21)->toIso8601String(), 'to' => ['name' => 'Destination']],
            ],
        ],
        'destination' => ['name' => 'Destination', 'lat' => 50.94, 'lng' => 6.95],
    ], $overrides);
}

/** Give the acting user a push subscription so reminders are worth scheduling. */
function subscribe(User $user): void
{
    $user->updatePushSubscription(
        'https://push.example/'.$user->id,
        'p256dh-public-key',
        'auth-token',
    );
}

test('starting a trip schedules a get-off reminder for each transit exit', function () {
    Queue::fake();
    subscribe($this->user);

    $this->postJson('/api/trip/start', reminderPayload())->assertOk();

    Queue::assertPushed(SendTripStopReminder::class, 2);

    // The intermediate transit leg is a change; the last one is the final exit.
    Queue::assertPushed(
        SendTripStopReminder::class,
        fn ($job) => $job->stopName === 'Change Stop' && $job->isFinal === false,
    );
    Queue::assertPushed(
        SendTripStopReminder::class,
        fn ($job) => $job->stopName === 'Exit Stop' && $job->isFinal === true,
    );
});

test('reminders are pinned to the redis worker connection', function () {
    Queue::fake();
    subscribe($this->user);

    $this->postJson('/api/trip/start', reminderPayload())->assertOk();

    Queue::assertPushed(
        SendTripStopReminder::class,
        fn ($job) => $job->connection === 'redis' && $job->queue === 'commute',
    );
});

test('no reminders are scheduled without a push subscription', function () {
    Queue::fake();

    $this->postJson('/api/trip/start', reminderPayload())->assertOk();

    Queue::assertNotPushed(SendTripStopReminder::class);
});

test('no reminders are scheduled when transit alerts are off', function () {
    Queue::fake();
    subscribe($this->user);
    $this->user->notificationPreference()->create([
        'preferences' => ['transit' => false],
    ]);

    $this->postJson('/api/trip/start', reminderPayload())->assertOk();

    Queue::assertNotPushed(SendTripStopReminder::class);
});

test('an ended route is rejected and cannot schedule reminders', function () {
    Queue::fake();
    subscribe($this->user);

    $payload = reminderPayload();
    $this->travel(30)->minutes();
    $this->postJson('/api/trip/start', $payload)->assertUnprocessable();

    Queue::assertNotPushed(SendTripStopReminder::class);
});

/** Build a persisted active trip and return [trip, startedAtIso]. */
function activeTripFor(User $user): array
{
    $trip = $user->activeTrip()->create([
        'journey' => ['legs' => [['mode' => 'tram', 'line' => '12']]],
        'destination' => ['name' => 'Destination', 'lat' => 50.94, 'lng' => 6.95],
        'started_at' => now(),
    ]);

    return [$trip, $trip->fresh()->started_at->toIso8601String()];
}

test('the reminder job pushes when the live trip still matches', function () {
    Notification::fake();
    subscribe($this->user);
    [, $startedAt] = activeTripFor($this->user);

    (new SendTripStopReminder($this->user->id, $startedAt, 'Exit Stop', true))->handle();

    Notification::assertSentTo($this->user, GetOffReminderNotification::class);
});

test('the reminder job stays silent once the trip has ended', function () {
    Notification::fake();
    subscribe($this->user);
    [$trip, $startedAt] = activeTripFor($this->user);
    $trip->delete();

    (new SendTripStopReminder($this->user->id, $startedAt, 'Exit Stop', true))->handle();

    Notification::assertNothingSent();
});

test('the reminder job stays silent after the trip was switched', function () {
    Notification::fake();
    subscribe($this->user);
    activeTripFor($this->user);

    // A reminder scheduled for an earlier start no longer matches the live trip.
    $staleStart = now()->subMinutes(30)->toIso8601String();
    (new SendTripStopReminder($this->user->id, $staleStart, 'Exit Stop', true))->handle();

    Notification::assertNothingSent();
});

test('the push message deep-links to the trip page with the right copy', function () {
    $final = (new GetOffReminderNotification('Exit Stop', true))
        ->toWebPush($this->user)
        ->toArray();

    expect($final['title'])->toBe('Get off next · Exit Stop');
    expect($final['data'])->toBe(['url' => '/timetable?view=route']);
    expect($final['tag'])->toBe('trip-get-off');

    $change = (new GetOffReminderNotification('Change Stop', false))
        ->toWebPush($this->user)
        ->toArray();

    expect($change['title'])->toBe('Change soon · Change Stop');
});
