<?php

use App\Models\Alert;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

test('the people who joined an event tomorrow find it on their Alerts page, never pushed alone; a cancelled one says nothing', function () {
    Notification::fake();
    $user = User::factory()->create();
    $on = Event::factory()->create(['starts_at' => now()->addDay()->setTime(19, 0), 'status' => 'active']);
    $cancelled = Event::factory()->create(['starts_at' => now()->addDay()->setTime(20, 0), 'status' => 'cancelled']);
    $on->attendees()->attach($user, ['joined_at' => now()]);
    $cancelled->attendees()->attach($user, ['joined_at' => now()]);

    $this->artisan('events:send-reminders')->assertSuccessful();

    // Tier 2 (owner, 26 Oct 2026): said in the day's brief, not a push of its own.
    Notification::assertNothingSent();
    expect(Alert::query()->where('user_id', $user->id)->pluck('title')->all())->toBe(["Tomorrow: {$on->title}"])
        ->and($on->attendees()->first()->pivot->reminded_at)->not->toBeNull()
        ->and($cancelled->attendees()->first()->pivot->reminded_at)->toBeNull();
});
