<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('serves Travel at /travel, and /timetable is gone', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->get('/travel')->assertOk()->assertInertia(fn ($page) => $page->component('timetable'));
    $this->actingAs($user)->get('/timetable')->assertNotFound();
    expect(route('travel', absolute: false))->toBe('/travel');
});

it('re-points the links stored before the move', function () {
    $user = User::factory()->onboarded()->create();
    $alert = DB::table('alerts')->insertGetId([
        'user_id' => $user->id, 'type' => 'transit_delay', 'severity' => 'info', 'title' => 'Line 16 late',
        'deep_link' => '/timetable?view=journey&activity=manual:1', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $notification = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $notification, 'type' => 'App\\Notifications\\PlannedTripLeaveReminder', 'notifiable_type' => User::class,
        'notifiable_id' => $user->id, 'data' => json_encode(['url' => '/timetable?view=route']), 'created_at' => now(), 'updated_at' => now(),
    ]);

    (require database_path('migrations/2026_10_27_100000_point_stored_links_at_travel.php'))->up();

    expect(DB::table('alerts')->where('id', $alert)->value('deep_link'))->toBe('/travel?view=journey&activity=manual:1')
        ->and(json_decode(DB::table('notifications')->where('id', $notification)->value('data'), true)['url'])->toBe('/travel?view=route');
});
