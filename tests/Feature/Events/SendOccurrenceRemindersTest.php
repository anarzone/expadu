<?php

use App\Models\Event;
use App\Models\EventReminder;
use App\Models\User;
use App\Notifications\EventOccurrenceReminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-12 10:00:00', 'Europe/Berlin'));
    Notification::fake();
});

function pendingOccurrenceReminder(array $eventAttributes = []): EventReminder
{
    $user = User::factory()->create();
    $event = Event::factory()->for($user, 'organiser')->create(array_replace([
        'starts_at' => '2026-06-12 11:30:00',
        'ends_at' => '2026-06-12 12:30:00',
        'recurrence' => null,
    ], $eventAttributes));

    return EventReminder::query()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'occurrence_start' => '2026-06-12 11:30:00',
        'offset_minutes' => 60,
        'remind_at' => '2026-06-12 09:55:00',
    ]);
}

test('delivery suppresses a saved reminder when its exact published occurrence is no longer available', function (array $changes) {
    $reminder = pendingOccurrenceReminder();
    $userBefore = $reminder->user->getAttributes();
    $reminderBefore = $reminder->getAttributes();
    $reminder->event->update($changes);

    $this->artisan('events:send-occurrence-reminders')->assertSuccessful();
    $this->artisan('events:send-occurrence-reminders')->assertSuccessful();

    Notification::assertNothingSent();
    expect($reminder->refresh()->sent_at)->not->toBeNull()
        ->and($reminder->user->getAttributes())->toBe($userBefore)
        ->and(collect($reminder->getAttributes())->except(['sent_at', 'updated_at'])->sortKeys()->all())
        ->toBe(collect($reminderBefore)->except(['sent_at', 'updated_at'])->sortKeys()->all());
})->with([
    'source held' => [['status' => 'hidden', 'needs_review' => true]],
    'expired' => [['status' => 'expired']],
    'no longer meets visibility gate' => [['relevance' => 0.1]],
    'one-off time moved' => [['starts_at' => '2026-06-12 12:00:00']],
    'recurring weekday moved' => [['starts_at' => '2026-06-05 11:30:00', 'recurrence' => 'FREQ=WEEKLY;BYDAY=SA']],
    'recurring series ended' => [['starts_at' => '2026-06-05 11:30:00', 'recurrence' => 'FREQ=WEEKLY;BYDAY=FR', 'recurrence_until' => '2026-06-11 00:00:00']],
]);

test('delivery still sends an exact recurring occurrence once', function () {
    $reminder = pendingOccurrenceReminder([
        'starts_at' => '2026-06-05 11:30:00',
        'ends_at' => '2026-06-05 12:30:00',
        'recurrence' => 'FREQ=WEEKLY;BYDAY=FR',
    ]);

    $this->artisan('events:send-occurrence-reminders')->assertSuccessful();
    $this->artisan('events:send-occurrence-reminders')->assertSuccessful();

    Notification::assertSentToTimes($reminder->user, EventOccurrenceReminder::class, 1);
    expect($reminder->refresh()->sent_at)->not->toBeNull();
});

test('delivery retains the existing notification preference suppression policy', function () {
    $reminder = pendingOccurrenceReminder();
    $reminder->user->notificationPreference()->create(['preferences' => ['events' => false]]);

    $this->artisan('events:send-occurrence-reminders')->assertSuccessful();

    Notification::assertNothingSent();
    expect($reminder->refresh()->sent_at)->not->toBeNull();
});
