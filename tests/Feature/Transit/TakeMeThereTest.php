<?php

use App\Models\User;
use App\Models\UserPlace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Cache::flush();
    config(['services.motis.url' => 'http://motis.test']);
    $prefix = (string) config('database.redis.options.prefix', '');
    Redis::eval(
        "for _,k in ipairs(redis.call('KEYS', ARGV[1])) do redis.call('DEL', k) end return 1",
        0,
        $prefix.'transit:cb:*'
    );
});

/**
 * MOTIS is the primary provider but isn't reachable in tests, so fake it
 * down and let routing + reverse-geocode fall to Transitous. Reverse-geocode
 * resolves both endpoints to Köln so the fare lands at the city level (1b).
 */
function fakeRoutingToKoeln(): void
{
    Http::fake([
        'motis.test/*' => Http::response('down', 503),
        'api.transitous.org/api/v3/plan*' => Http::response(
            json_decode(file_get_contents(base_path('tests/Fixtures/transitous/plan.json')), true),
        ),
        'api.transitous.org/api/v1/reverse-geocode*' => Http::response([
            ['name' => 'Cologne', 'lat' => 50.9413, 'lon' => 6.9583, 'areas' => [
                ['name' => 'Köln', 'adminLevel' => 6, 'matched' => true],
            ]],
        ]),
    ]);
}

test('returns a journey with journey-aware Rheinlandtarif fare advice', function () {
    fakeRoutingToKoeln();

    $student = User::factory()->onboarded()->create(['situation' => 'student']);
    UserPlace::factory()->create([
        'user_id' => $student->id, 'category' => 'home', 'lat' => 50.9513, 'lng' => 6.9185,
    ]);

    $this->actingAs($student);
    $response = $this->getJson('/api/journey?to_lat=50.9413&to_lng=6.9583&to_name=B%C3%BCrgeramt');

    $response->assertOk();
    $response->assertJsonPath('to.name', 'Bürgeramt');
    expect($response->json('journeys'))->not->toBeEmpty();

    // No Deutschlandticket → a concrete single fare for this trip, not the
    // old situation-based subscription chip. Both endpoints resolved to Köln,
    // so the level is exact (Kurzstrecke or 1b), never a cross-zone estimate.
    $response->assertJsonPath('ticket.covered_by_deutschlandticket', false);
    $response->assertJsonPath('ticket.estimated', false);
    expect(['K', '1b'])->toContain($response->json('ticket.preisstufe'));
    expect((float) $response->json('ticket.price_eur'))->toBeGreaterThan(0);
    expect($response->json('ticket.how_to_buy'))->not->toBeEmpty();
});

test('starts from an explicit origin + label, not the home fallback', function () {
    fakeRoutingToKoeln();

    $user = User::factory()->onboarded()->create();
    // A home far from the destination — the old code routed from here, which is
    // why the sheet disagreed with the card and dropped the walk option.
    UserPlace::factory()->create([
        'user_id' => $user->id, 'category' => 'home', 'lat' => 51.05, 'lng' => 6.80,
    ]);

    $this->actingAs($user);
    // The Places list forwards its resolved From (e.g. the Merkenich area) so
    // the journey starts there and the sheet headline matches the card.
    $response = $this->getJson('/api/journey?to_lat=50.9413&to_lng=6.9583&to_name=Bolzplatz'
        .'&from_lat=50.9430&from_lng=6.9550&from_name=Merkenich');

    $response->assertOk();
    $response->assertJsonPath('from.name', 'Merkenich');
    expect((float) $response->json('from.lat'))->toBe(50.943);
    expect((float) $response->json('from.lng'))->toBe(6.955);
});

test('a Deutschlandticket holder sees the trip as covered', function () {
    fakeRoutingToKoeln();

    $holder = User::factory()->onboarded()->create(['has_deutschlandticket' => true]);
    UserPlace::factory()->create([
        'user_id' => $holder->id, 'category' => 'home', 'lat' => 50.9513, 'lng' => 6.9185,
    ]);

    $this->actingAs($holder);
    $response = $this->getJson('/api/journey?to_lat=50.9413&to_lng=6.9583');

    $response->assertOk();
    $response->assertJsonPath('ticket.covered_by_deutschlandticket', true);
    expect($response->json('ticket.price_eur'))->toBeNull();
});

test('validates destination coordinates', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $this->getJson('/api/journey?to_lat=999&to_lng=6.95')
        ->assertUnprocessable();
});
