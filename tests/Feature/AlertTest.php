<?php

use App\Models\Alert;
use App\Models\CityNews;
use App\Models\NoticeBrief;
use App\Models\User;

test('alerts page renders with alerts', function () {
    $user = User::factory()->onboarded()->create();
    Alert::factory()->count(3)->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $response = $this->get(route('alerts'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('alerts')
        ->has('alerts', 3)
        ->has('unreadCount')
    );
});

test('each alert is enriched with the v4 lane / category / severity', function () {
    $user = User::factory()->onboarded()->create();
    Alert::factory()->create(['user_id' => $user->id, 'subtype' => 'transit_disruption']);
    $this->actingAs($user);

    $this->get(route('alerts'))
        ->assertInertia(fn ($page) => $page
            ->where('alerts.0.category', 'transit')
            ->where('alerts.0.lane', 'action')
            ->has('alerts.0.severity')
            ->has('alerts.0.source'));
});

test('a transit card shows its KVB notice\'s English brief, else KVB\'s own words in German, with KVB\'s page and never the impact', function () {
    $user = User::factory()->onboarded()->create();
    $notice = fn (string $line, string $title, string $details) => CityNews::query()->create([
        'title' => $title, 'summary' => $details, 'source' => 'kvb-betriebslage',
        'source_url' => 'https://www.kvb.koeln/fahrtinfo/betriebslage/#'.md5($title), 'category' => 'transit',
        'relevance' => 'transit', 'affected_lines' => [$line], 'severity' => 'moderate',
        'published_at' => now(), 'expires_at' => now()->addHours(2),
    ]);
    $briefed = $notice('13', 'Linie 13: Umleitung', 'Zwischen Sülzgürtel und Neusser Str. fahren die Bahnen eine Umleitung.');
    $plain = $notice('4', 'Linie 4: Störung', 'Wegen eines Notarzteinsatzes kommt es zu Verspätungen.');
    NoticeBrief::query()->create([
        'input_hash' => NoticeBrief::hashFor($briefed->title, $briefed->summary), 'locale' => 'en',
        'headline' => 'Line 13 is diverted', 'body' => 'Trams take a detour between Sülzgürtel and Neusser Str.',
        'impact' => 'Allow ten extra minutes.', 'model' => 'test', 'prompt_version' => 'test',
    ]);
    foreach ([$briefed, $plain] as $i => $n) {
        Alert::factory()->create([
            'user_id' => $user->id, 'subtype' => 'transit_disruption', 'group_key' => "disruption:news_{$n->id}",
            'title' => "Line {$n->affected_lines[0]} disrupted on your route", 'body' => 'See KVB for details.',
            'updated_at' => now()->subMinutes($i),
        ]);
    }

    $alerts = collect($this->actingAs($user)->get(route('alerts'))->assertOk()->inertiaProps('alerts'))->keyBy('title');

    $page = 'https://www.kvb.koeln/fahrtinfo/betriebslage/';
    expect($alerts->keys()->all())->toBe(['Line 13 is diverted', 'Linie 4: Störung'])
        ->and($alerts['Line 13 is diverted']['body'])->toBe('Trams take a detour between Sülzgürtel and Neusser Str.')
        ->and($alerts['Line 13 is diverted']['notice'])->toBe(['lang' => 'en', 'url' => $page])
        ->and($alerts['Linie 4: Störung']['body'])->toBe('Wegen eines Notarzteinsatzes kommt es zu Verspätungen.')
        ->and($alerts['Linie 4: Störung']['notice'])->toBe(['lang' => 'de', 'url' => $page])
        ->and(json_encode($alerts->all()))->not->toContain('Allow ten extra minutes');
});

test('only KVB\'s own page is linked as KVB\'s notice', function () {
    $user = User::factory()->onboarded()->create();
    $city = CityNews::query()->create([
        'title' => 'Sperrung der Severinsbrücke', 'summary' => 'Die Brücke ist gesperrt.', 'source' => 'stadt-koeln',
        'source_url' => 'https://www.stadt-koeln.de/politik-und-verwaltung/presse/mitteilungen/12345', 'category' => 'transit',
        'relevance' => 'transit', 'affected_lines' => ['3'], 'severity' => 'moderate', 'published_at' => now(),
    ]);
    Alert::factory()->create([
        'user_id' => $user->id, 'subtype' => 'transit_disruption', 'group_key' => "disruption:news_{$city->id}",
        'title' => 'Line 3 disrupted on your route', 'body' => 'See KVB for details.',
    ]);

    $alert = $this->actingAs($user)->get(route('alerts'))->assertOk()->inertiaProps('alerts')[0];

    expect($alert['title'])->toBe('Sperrung der Severinsbrücke')
        ->and($alert['notice'])->toBe(['lang' => 'de', 'url' => null]);
});

test('user can mark an alert as read', function () {
    $user = User::factory()->onboarded()->create();
    $alert = Alert::factory()->create(['user_id' => $user->id, 'read_at' => null]);
    $this->actingAs($user);

    $this->post(route('alerts.read', $alert));

    expect($alert->fresh()->read_at)->not->toBeNull();
});

test('user can mark all alerts as read', function () {
    $user = User::factory()->onboarded()->create();
    Alert::factory()->count(3)->create(['user_id' => $user->id, 'read_at' => null]);
    $this->actingAs($user);

    $this->post(route('alerts.read-all'));

    expect($user->alerts()->whereNull('read_at')->count())->toBe(0);
});

test('user cannot mark another users alert as read', function () {
    $user = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $alert = Alert::factory()->create(['user_id' => $other->id, 'read_at' => null]);
    $this->actingAs($user);

    $this->post(route('alerts.read', $alert));

    expect($alert->fresh()->read_at)->toBeNull();
});

test('user can dismiss an alert', function () {
    $user = User::factory()->onboarded()->create();
    $alert = Alert::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $this->postJson(route('alerts.dismiss', $alert));

    expect($alert->fresh())
        ->dismissed_at->not->toBeNull()
        ->read_at->not->toBeNull();
});

test('dismissed alerts are excluded from the alerts page', function () {
    $user = User::factory()->onboarded()->create();
    Alert::factory()->create(['user_id' => $user->id, 'dismissed_at' => now()]);
    Alert::factory()->create(['user_id' => $user->id, 'dismissed_at' => null]);
    $this->actingAs($user);

    $this->get(route('alerts'))
        ->assertInertia(fn ($page) => $page->has('alerts', 1));
});

test('user cannot dismiss another users alert', function () {
    $user = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $alert = Alert::factory()->create(['user_id' => $other->id]);
    $this->actingAs($user);

    $this->postJson(route('alerts.dismiss', $alert));

    expect($alert->fresh()->dismissed_at)->toBeNull();
});
