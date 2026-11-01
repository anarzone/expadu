<?php

use App\Models\Alert;
use App\Models\ComposerWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Today lives at /today (owner, 27 Oct 2026: links updated, no redirect).

test('Today is at /today, and /dashboard is gone', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->get('/today')->assertOk()->assertInertia(fn ($page) => $page->component('today'));
    $this->actingAs($user)->get('/dashboard')->assertNotFound();
    expect(route('today', absolute: false))->toBe('/today')
        ->and(config('fortify.home'))->toBe('/today');
});

test('stored links to /dashboard follow it to /today, and nothing else changes', function () {
    $user = User::factory()->onboarded()->create();
    $alert = fn (string $link) => Alert::create(['user_id' => $user->id, 'type' => 'system', 'title' => 'T', 'deep_link' => $link])->id;
    $plain = $alert('/dashboard');
    $query = $alert('/dashboard?stop=Neumarkt');
    $host = $alert('https://app.expadu.com/dashboard#day');
    $other = $alert('/dashboards-and-more');
    $elsewhere = $alert('https://mapillary.com/dashboard/developers');

    $notification = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $notification, 'type' => 'App\\Notifications\\RhineFloodNotification', 'notifiable_type' => User::class,
        'notifiable_id' => $user->id, 'data' => json_encode(['url' => '/dashboard', 'title' => 'Open the dashboard']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $workspace = ComposerWorkspace::create(['user_id' => $user->id, 'revision' => 1, 'state' => [
        'messages' => [['text' => 'See /dashboard later', 'cards' => [['label' => 'Open Today', 'href' => '/dashboard']]]],
    ]]);

    (require database_path('migrations/2026_10_27_100000_rewrite_stored_dashboard_links_to_today.php'))->up();

    expect(Alert::whereIn('id', [$plain, $query, $host, $other, $elsewhere])->orderBy('id')->pluck('deep_link')->all())->toBe([
        '/today', '/today?stop=Neumarkt', 'https://app.expadu.com/today#day', '/dashboards-and-more', 'https://mapillary.com/dashboard/developers',
    ]);
    expect(json_decode((string) DB::table('notifications')->where('id', $notification)->value('data'), true))
        ->toBe(['url' => '/today', 'title' => 'Open the dashboard']);
    // jsonb keeps its own key order.
    expect($workspace->fresh()->state['messages'][0])
        ->toEqualCanonicalizing(['text' => 'See /dashboard later', 'cards' => [['label' => 'Open Today', 'href' => '/today']]]);
});
