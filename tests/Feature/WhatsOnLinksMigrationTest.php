<?php

use App\Models\Alert;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * No Events page (owner, 27 Oct 2026): links stored before point at What's on over Today, keeping
 * their day, filters, listing or Saved; /api/events and other sites are left alone.
 */
test('stored Events links point at What’s on, and nothing else changes', function () {
    $user = User::factory()->create();
    $migration = require database_path('migrations/2026_10_27_140000_point_stored_event_links_at_whats_on.php');
    $alert = Alert::factory()->create(['user_id' => $user->id, 'deep_link' => '/events?date=2026-10-22&event=5&start=2026-10-22T19%3A00%3A00%2B02%3A00']);
    $plain = Alert::factory()->create(['user_id' => $user->id, 'deep_link' => '/events']);
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(), 'type' => 'test', 'notifiable_type' => User::class, 'notifiable_id' => $user->id,
        'data' => json_encode(['url' => '/events/saved', 'other' => '/events/42', 'api' => '/api/events?x=1', 'site' => 'https://example.com/events']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration->up();

    $data = json_decode(DB::table('notifications')->value('data'), true);
    expect($alert->fresh()->deep_link)->toBe('/today?whats_on=1&date=2026-10-22&event=5&start=2026-10-22T19%3A00%3A00%2B02%3A00')
        ->and($plain->fresh()->deep_link)->toBe('/today?whats_on=1')
        ->and($data)->toBe(['url' => '/today?whats_on=1&saved=1', 'other' => '/today?whats_on=1&event=42', 'api' => '/api/events?x=1', 'site' => 'https://example.com/events']);
});
