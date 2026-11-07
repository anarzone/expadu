<?php

use App\Models\Alert;
use App\Models\CityNews;
use App\Models\User;

/* The bell's sheet for the iPhone: their own alerts, as the web lists them, in the app's groups. */

test('🔒 alerts need a token and a confirmed address', function () {
    $this->getJson('/api/v1/alerts')->assertUnauthorized();

    $unconfirmed = User::factory()->unverified()->create();
    $this->withToken($unconfirmed->createToken('iPhone')->plainTextToken)->getJson('/api/v1/alerts')->assertForbidden();
});

test('their alerts come newest first, in the app’s groups, a KVB notice with its lines, stops and page', function () {
    $user = User::factory()->create();
    $notice = CityNews::factory()->create(['source' => 'kvb', 'title' => 'Linie 12: Umleitung', 'summary' => 'Bauarbeiten am Ring.', 'source_url' => 'https://www.kvb.koeln/fahrtinfo/betriebslage/#12',
        'affected_lines' => ['12', '15'], 'affected_stops' => ['Friesenplatz']]);
    $at = fn (int $minutes) => now()->subMinutes($minutes);
    $disruption = Alert::factory()->for($user)->create(['type' => 'system', 'subtype' => 'transit_disruption', 'severity' => 'warn', 'group_key' => "disruption:news_{$notice->id}",
        'title' => 'Line 12 disrupted', 'body' => 'Old text', 'updated_at' => $at(1)]);
    $delay = Alert::factory()->for($user)->create(['type' => 'system', 'subtype' => 'transit_delay', 'severity' => 'info', 'group_key' => 'delay:18:Thielenbruch',
        'title' => 'Line 18 running late', 'body' => '', 'read_at' => now(), 'updated_at' => $at(2)]);
    $weather = Alert::factory()->for($user)->create(['type' => 'system', 'subtype' => 'weather', 'severity' => 'warn', 'group_key' => 'weather', 'title' => 'Storm warning', 'updated_at' => $at(3)]);
    $paper = Alert::factory()->for($user)->create(['type' => 'reminder', 'subtype' => 'buergeramt_slot', 'severity' => 'info', 'title' => 'A slot opened', 'updated_at' => $at(4)]);
    $rhine = Alert::factory()->for($user)->create(['type' => 'system', 'subtype' => 'rhine', 'severity' => 'info', 'title' => 'Rhine is high', 'updated_at' => $at(5)]);
    Alert::factory()->for($user)->create(['title' => 'Dismissed', 'dismissed_at' => now()]);
    Alert::factory()->for(User::factory())->create(['title' => 'Someone else’s']);

    $response = $this->withToken($user->createToken('iPhone')->plainTextToken)->getJson('/api/v1/alerts')->assertOk()
        ->assertJsonPath('unread', 4);
    $alerts = $response->json('alerts');

    expect(array_column($alerts, 'id'))->toBe([$disruption->id, $delay->id, $weather->id, $paper->id, $rhine->id])
        ->and(array_column($alerts, 'category'))->toBe(['lines', 'lines', 'weather', 'paper', 'city'])
        ->and($alerts[0])->toMatchArray([
            // No English brief yet: KVB's own words, and its page.
            'title' => 'Linie 12: Umleitung', 'body' => 'Bauarbeiten am Ring.', 'severity' => 'warn', 'source' => 'KVB live', 'read' => false,
            'url' => 'https://www.kvb.koeln/fahrtinfo/betriebslage/', 'lines' => ['12', '15'], 'stops' => ['Friesenplatz'],
        ])
        ->and($alerts[1])->toMatchArray(['body' => null, 'read' => true, 'url' => null, 'lines' => ['18'], 'stops' => []])
        ->and($alerts[2])->toMatchArray(['lines' => [], 'stops' => [], 'url' => null])
        ->and($alerts[0]['at'])->toBe($disruption->fresh()->updated_at->toIso8601String());
});

test('reading an alert marks it read', function () {
    $user = User::factory()->create();
    $alert = Alert::factory()->for($user)->create();

    $this->withToken($user->createToken('iPhone')->plainTextToken)->postJson("/api/v1/alerts/{$alert->id}/read")->assertOk()->assertExactJson(['ok' => true]);

    expect($alert->fresh()->read_at)->not->toBeNull();
});

test('🔒 another person’s alert is not found and stays unread', function () {
    $alert = Alert::factory()->for(User::factory())->create();
    $token = User::factory()->create()->createToken('iPhone')->plainTextToken;

    $this->withToken($token)->postJson("/api/v1/alerts/{$alert->id}/read")->assertNotFound();
    $this->withToken($token)->getJson('/api/v1/alerts')->assertOk()->assertJsonPath('alerts', [])->assertJsonPath('unread', 0);

    expect($alert->fresh()->read_at)->toBeNull();
});
