<?php

use App\Models\Event;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use Illuminate\Support\Facades\Notification;

test('a cancelled event sends no reminder to the people who joined it', function () {
    Notification::fake();
    $user = User::factory()->create();
    $on = Event::factory()->create(['starts_at' => now()->addDay()->setTime(19, 0), 'status' => 'active']);
    $cancelled = Event::factory()->create(['starts_at' => now()->addDay()->setTime(20, 0), 'status' => 'cancelled']);
    $on->attendees()->attach($user, ['joined_at' => now()]);
    $cancelled->attendees()->attach($user, ['joined_at' => now()]);

    $this->artisan('events:send-reminders')->assertSuccessful();

    Notification::assertSentToTimes($user, EventReminderNotification::class, 1);
    expect($cancelled->attendees()->first()->pivot->reminded_at)->toBeNull();
});
