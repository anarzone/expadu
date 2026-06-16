<?php

use App\Models\User;
use App\Services\VeedelLocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    // Anchors live in Redis, which is shared across test runs — clear the keys
    // this file owns at the start of each test (same approach as ActionBusTest).
    $prefix = (string) config('database.redis.options.prefix', '');
    Redis::eval(
        "for _,k in ipairs(redis.call('KEYS', ARGV[1])) do redis.call('DEL', k) end return 1",
        0,
        $prefix.'veedel_anchor:*',
    );
});

function seedVeedel(string $name, float $lat, float $lng): void
{
    DB::table('veedels')->insert([
        'name' => $name,
        'bezirk' => $name,
        'centroid_lat' => $lat,
        'centroid_lng' => $lng,
    ]);
}

it('resolves the nearest Veedel from coordinates', function () {
    seedVeedel('Merkenich', 51.0396893, 6.9301582);
    seedVeedel('Ehrenfeld', 50.9503304, 6.9112611);

    $locator = app(VeedelLocator::class);

    expect($locator->nearestVeedel(51.0397, 6.9302))->toBe('Merkenich');
    expect($locator->nearestVeedel(50.9503, 6.9113))->toBe('Ehrenfeld');
});

it('returns null when the point is outside Köln', function () {
    seedVeedel('Merkenich', 51.0396893, 6.9301582);

    // Berlin — hundreds of km from any Köln centroid.
    expect(app(VeedelLocator::class)->nearestVeedel(52.52, 13.405))->toBeNull();
});

it('updates the Veedel when the user genuinely relocates', function () {
    seedVeedel('Merkenich', 51.0396893, 6.9301582);
    seedVeedel('Ehrenfeld', 50.9503304, 6.9112611);
    $user = User::factory()->create(['veedel' => 'Ehrenfeld']);

    $result = app(VeedelLocator::class)->syncFromPing($user, 51.0397, 6.9302);

    expect($result)->toBe('Merkenich');
    expect($user->fresh()->veedel)->toBe('Merkenich');
});

it('ignores small movements (jitter) once anchored', function () {
    // Two adjacent cells ~1.1 km apart so a sub-500 m move can cross the midpoint
    // (midpoint at lat 50.9050).
    seedVeedel('Alpha', 50.9000, 6.9000);
    seedVeedel('Beta', 50.9100, 6.9000);
    $user = User::factory()->create(['veedel' => 'Alpha']);
    $locator = app(VeedelLocator::class);

    // First ping on the Alpha side → anchors here, Veedel already Alpha.
    expect($locator->syncFromPing($user, 50.9020, 6.9000))->toBeNull();

    // Jitter: 445 m north — now nearest to Beta, but inside the radius gate.
    $result = $locator->syncFromPing($user, 50.9060, 6.9000);

    expect($result)->toBeNull();
    expect($user->fresh()->veedel)->toBe('Alpha');
});

it('updates once the move clears the radius gate', function () {
    seedVeedel('Alpha', 50.9000, 6.9000);
    seedVeedel('Beta', 50.9100, 6.9000);
    $user = User::factory()->create(['veedel' => 'Alpha']);
    $locator = app(VeedelLocator::class);

    // Anchor on the Alpha side.
    $locator->syncFromPing($user, 50.9020, 6.9000);

    // ~891 m north of the anchor and clearly nearest to Beta → switches.
    $result = $locator->syncFromPing($user, 50.9100, 6.9000);

    expect($result)->toBe('Beta');
    expect($user->fresh()->veedel)->toBe('Beta');
});

it('never updates the Veedel when location sharing is off', function () {
    seedVeedel('Merkenich', 51.0396893, 6.9301582);
    $user = User::factory()->create(['veedel' => 'Ehrenfeld']);
    $user->userSetting()->create(['settings' => ['share_location' => false]]);

    $result = app(VeedelLocator::class)->syncFromPing($user, 51.0397, 6.9302);

    expect($result)->toBeNull();
    expect($user->fresh()->veedel)->toBe('Ehrenfeld');
});
