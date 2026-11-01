<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Around here moved from /explore to /around-here with no redirect: stored links follow.

test('stored links to /explore point at /around-here, and nothing else changes', function () {
    $user = User::factory()->create();
    $alert = DB::table('alerts')->insertGetId([
        'user_id' => $user->id, 'type' => 'info', 'title' => 'Near you', 'body' => 'x',
        'deep_link' => '/explore?kind=cafe&near=home', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\Test', 'notifiable_type' => User::class, 'notifiable_id' => $user->id,
        // As Laravel stores it: JSON with escaped slashes, an absolute link and an unrelated host.
        'data' => json_encode(['url' => 'https://app.expadu.com/explore?place=7', 'path' => '/explore', 'source' => 'https://explore.overturemaps.org/?mode=explore#id=1', 'other' => '/events?x=1']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('composer_workspaces')->insert([
        'user_id' => $user->id,
        'state' => json_encode(['cards' => [['href' => '/explore?place=42', 'label' => 'explore a Veedel']]]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    (require database_path('migrations/2026_10_27_100000_point_stored_links_at_around_here.php'))->up();

    $data = json_decode(DB::table('notifications')->value('data'), true);
    $state = json_decode(DB::table('composer_workspaces')->value('state'), true);

    expect(DB::table('alerts')->where('id', $alert)->value('deep_link'))->toBe('/around-here?kind=cafe&near=home')
        ->and($data)->toBe([
            'url' => 'https://app.expadu.com/around-here?place=7',
            'path' => '/around-here',
            'source' => 'https://explore.overturemaps.org/?mode=explore#id=1',
            'other' => '/events?x=1',
        ])
        ->and($state['cards'][0])->toBe(['href' => '/around-here?place=42', 'label' => 'explore a Veedel']);
});

test('the old path is gone, the new one serves Around here', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->get('/explore')->assertNotFound();
    $this->actingAs($user)->get('/around-here')->assertOk();
    expect(route('around-here', [], false))->toBe('/around-here');
});
