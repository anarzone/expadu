<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

/* Getting there for the iPhone: the web's planner, and a trip started only on a way it planned for this person. */

beforeEach(function () {
    Cache::flush();
    config(['services.motis.url' => 'http://motis.test']);
    $prefix = (string) config('database.redis.options.prefix', '');
    Redis::eval("for _,k in ipairs(redis.call('KEYS', ARGV[1])) do redis.call('DEL', k) end return 1", 0, $prefix.'transit:cb:*');
    Http::fake([
        'motis.test/*' => Http::response('down', 503),
        'api.transitous.org/api/v3/plan*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/transitous/plan.json')), true)),
        'api.transitous.org/api/v1/reverse-geocode*' => Http::response([
            ['name' => 'Cologne', 'lat' => 50.9413, 'lon' => 6.9583, 'areas' => [['name' => 'Köln', 'adminLevel' => 6, 'matched' => true]]],
        ]),
    ]);
});

$where = ['to_lat' => 50.9413, 'to_lng' => 6.9583, 'to_name' => 'Kreuzblume', 'from_lat' => 50.9513, 'from_lng' => 6.9185];

test('🔒 Getting there needs a token and a confirmed address', function () use ($where) {
    $this->postJson('/api/v1/journey/plan', $where)->assertUnauthorized();
    $this->postJson('/api/v1/journey/start', ['plan' => (string) Str::uuid(), 'way' => 0])->assertUnauthorized();

    $unconfirmed = User::factory()->unverified()->create();
    $this->withToken($unconfirmed->createToken('iPhone')->plainTextToken)->postJson('/api/v1/journey/plan', $where)->assertForbidden();
});

test('the ways come in the app’s own shape, with a plan to start one from', function () use ($where) {
    $token = User::factory()->create()->createToken('iPhone')->plainTextToken;

    $plan = $this->withToken($token)->postJson('/api/v1/journey/plan', $where)->assertOk()
        ->assertJsonPath('to', 'Kreuzblume')
        ->assertJsonStructure(['plan', 'from', 'to', 'ways' => [['leave_time', 'arrive_time', 'mode', 'lines', 'minutes', 'legs']], 'ticket', 'rain', 'lifts', 'notices']);
    expect($plan->json('ways'))->not->toBeEmpty();
});

test('🔒 a trip starts only on a way planned for this person', function () use ($where) {
    $owner = User::factory()->create();
    $plan = $this->withToken($owner->createToken('iPhone')->plainTextToken)->postJson('/api/v1/journey/plan', $where)->assertOk()->json('plan');
    app('auth')->forgetGuards();

    $other = User::factory()->create();
    $this->withToken($other->createToken('iPhone')->plainTextToken)->postJson('/api/v1/journey/start', ['plan' => $plan, 'way' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors('way');
});
