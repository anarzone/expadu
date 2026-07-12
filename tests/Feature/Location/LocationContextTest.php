<?php

use App\Enums\LocationSource;
use App\Models\User;
use App\Models\UserPlace;
use App\Services\UserLocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->service = app(UserLocationService::class);
    $this->user = User::factory()->create();
    Redis::del("confirmed_location:{$this->user->id}");
    Redis::del("location_history:{$this->user->id}");
});

test('a live GPS fix in the request wins', function () {
    $request = Request::create('/api/places', 'GET', ['lat' => '50.95', 'lng' => '6.92']);

    $origin = $this->service->context($this->user, $request);

    expect($origin->source)->toBe(LocationSource::Live);
    expect($origin->hasOrigin())->toBeTrue();
    expect($origin->lat)->toBe(50.95);
    expect($origin->lng)->toBe(6.92);
});

test('an explicitly picked area resolves to its centroid', function () {
    DB::table('veedels')->insert([
        'name' => 'Ehrenfeld', 'bezirk' => 'Ehrenfeld',
        'centroid_lat' => 50.949, 'centroid_lng' => 6.917,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $request = Request::create('/api/places', 'GET', ['from_area' => 'Ehrenfeld']);

    $origin = $this->service->context($this->user, $request);

    expect($origin->source)->toBe(LocationSource::Area);
    expect($origin->label)->toBe('Ehrenfeld');
    expect($origin->lat)->toBe(50.949);
});

test('falls back to the browsed area when nothing else is known', function () {
    DB::table('veedels')->insert([
        'name' => 'Nippes', 'bezirk' => 'Nippes',
        'centroid_lat' => 50.96, 'centroid_lng' => 6.95,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $origin = $this->service->context($this->user, Request::create('/api/places'), 'Nippes');

    expect($origin->source)->toBe(LocationSource::Area);
    expect($origin->lat)->toBe(50.96);
});

test('returns None — never a guessed centre — when location is unknown', function () {
    $origin = $this->service->context($this->user, Request::create('/api/places'));

    expect($origin->source)->toBe(LocationSource::None);
    expect($origin->hasOrigin())->toBeFalse();
    expect($origin->lat)->toBeNull();
    expect($origin->toGeoPoint())->toBeNull();
});

test('a home place no longer anchors distance', function () {
    // A user with a home place but no live location resolves to None — the
    // redesign deliberately drops home/work as a distance anchor.
    UserPlace::factory()->create([
        'user_id' => $this->user->id, 'category' => 'home',
        'lat' => 50.948, 'lng' => 6.921,
    ]);

    $origin = $this->service->context($this->user, Request::create('/api/places'));

    expect($origin->source)->toBe(LocationSource::None);
});

test('the confirmed location is the shared origin', function () {
    // The same value the Places list and take-me-there both read.
    $this->service->confirm($this->user, 50.97, 6.93, 'Mülheim');

    $origin = $this->service->context($this->user, Request::create('/api/places'));

    expect($origin->source)->toBe(LocationSource::Confirmed);
    expect($origin->lat)->toBe(50.97);
    expect($origin->label)->toBe('Mülheim');
});

test('a newer accepted GPS ping replaces an older confirmed live fix', function () {
    Redis::setex("confirmed_location:{$this->user->id}", 7200, json_encode([
        'lat' => 50.97,
        'lng' => 6.93,
        'name' => 'Old position',
        'at' => now()->subMinutes(10)->timestamp,
    ]));

    $freshFix = now()->addSecond();
    Redis::zadd("location_history:{$this->user->id}", $freshFix->timestamp, json_encode([
        'lat' => 50.995, 'lng' => 6.955, 'at' => $freshFix->toIso8601String(),
    ]));

    $origin = $this->service->context($this->user, Request::create('/api/places'));
    $resolved = $this->service->resolve($this->user);

    expect($origin->source)->toBe(LocationSource::Ping)
        ->and($origin->lat)->toBe(50.995)
        ->and($resolved['source'])->toBe('last_ping')
        ->and($resolved['lat'])->toBe(50.995);
});

test('a recent accepted ping anchors the origin', function () {
    Redis::zadd("location_history:{$this->user->id}", now()->timestamp, json_encode([
        'lat' => 50.9485, 'lng' => 6.9230, 'at' => now()->toIso8601String(),
    ]));

    $origin = $this->service->context($this->user, Request::create('/api/places'));

    expect($origin->source)->toBe(LocationSource::Ping);
    expect($origin->lat)->toBe(50.9485);
    expect($origin->lng)->toBe(6.9230);
});

test('a rejected weak-signal ping never anchors the origin', function () {
    // Weak-network noise (bad accuracy / impossible speed) is stored for
    // debugging but must not drag the board to the wrong station. An older
    // accepted fix behind it wins.
    $key = "location_history:{$this->user->id}";
    Redis::zadd($key, now()->subMinutes(5)->timestamp, json_encode([
        'lat' => 50.9485, 'lng' => 6.9230, 'at' => now()->subMinutes(5)->toIso8601String(),
    ]));
    Redis::zadd($key, now()->timestamp, json_encode([
        'lat' => 50.0000, 'lng' => 7.5000, 'rejected' => 'accuracy_800m', 'at' => now()->toIso8601String(),
    ]));

    $origin = $this->service->context($this->user, Request::create('/api/places'));

    expect($origin->source)->toBe(LocationSource::Ping);
    expect($origin->lat)->toBe(50.9485);
    expect($origin->lng)->toBe(6.9230);
});

test('a history of only rejected pings resolves to no origin', function () {
    Redis::zadd("location_history:{$this->user->id}", now()->timestamp, json_encode([
        'lat' => 50.0000, 'lng' => 7.5000, 'rejected' => 'speed_400kmh', 'at' => now()->toIso8601String(),
    ]));

    $origin = $this->service->context($this->user, Request::create('/api/places'));

    expect($origin->source)->toBe(LocationSource::None);
});

test('veedelAt resolves a coordinate to its nearest Veedel', function () {
    // No boundaries loaded → nearest-centroid fallback (the local/staging case).
    DB::table('veedels')->insert([
        ['name' => 'Ehrenfeld', 'bezirk' => 'Ehrenfeld', 'centroid_lat' => 50.9503, 'centroid_lng' => 6.9113, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Mülheim', 'bezirk' => 'Mülheim', 'centroid_lat' => 50.9667, 'centroid_lng' => 6.9931, 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect($this->service->veedelAt(50.9485, 6.9230))->toBe('Ehrenfeld');
    expect($this->service->veedelAt(50.9670, 6.9900))->toBe('Mülheim');
});

test('veedelAt is null when no Veedels are known', function () {
    expect($this->service->veedelAt(50.9485, 6.9230))->toBeNull();
});

test('confirming a live fix remembers its Veedel as the default area', function () {
    DB::table('veedels')->insert([
        ['name' => 'Ehrenfeld', 'bezirk' => 'Ehrenfeld', 'centroid_lat' => 50.9503, 'centroid_lng' => 6.9113, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Deutz', 'bezirk' => 'Innenstadt', 'centroid_lat' => 50.9389, 'centroid_lng' => 6.9750, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->user->update(['veedel' => 'Ehrenfeld']);

    // A live fix in Deutz becomes the remembered default — every surface now
    // falls back to Deutz, not the onboarding neighbourhood.
    $this->service->confirm($this->user, 50.9389, 6.9750, 'Deutz');

    expect($this->user->fresh()->veedel)->toBe('Deutz');
});

test('confirming does not clobber the remembered area when no Veedel resolves', function () {
    // No Veedels seeded → nothing to resolve → keep the existing default.
    $this->user->update(['veedel' => 'Ehrenfeld']);

    $this->service->confirm($this->user, 50.9389, 6.9750);

    expect($this->user->fresh()->veedel)->toBe('Ehrenfeld');
});
