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
