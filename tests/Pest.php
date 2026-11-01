<?php

use App\Bureaucracy\Verification\SourceCheckRecorder;
use App\Composer\ActivePlanStore;
use App\Composer\AppointmentRepository;
use App\Composer\Constraints;
use App\Composer\PrivatePlanCache;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\HtmlString;
use Tests\Support\OfflineSourceChecks;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->beforeEach(function () {
        // A clean cache per test — the discovery feed caches its global spot
        // scan, which would otherwise leak across tests after DB rollback.
        Cache::flush();

        // Per-user location anchors and notification throttle counters live in
        // raw Redis (not the array cache), so they survive DB rollback and leak
        // into the next test that reuses an auto-increment id (Postgres
        // sequences don't roll back, so ids climb across the suite — a fixed
        // range can't catch them). notif_throttle:last:{id} is the nastiest:
        // combined with each test's random travelTo, a leaked future timestamp
        // makes canPush()'s interval check go negative and silently strip the
        // 'push' channel. keys() returns prefixed names but del() re-applies the
        // prefix, so strip it first, then clear every match.
        $prefix = (string) config('database.redis.options.prefix');
        $strip = fn (string $key): string => str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
        foreach (['confirmed_location:*', 'location_history:*', 'location_proximity:*', 'notif_throttle:*', 'pending_actions:*', 'mute:*'] as $pattern) {
            $stale = array_map($strip, Redis::keys($pattern));
            if ($stale !== []) {
                Redis::del(...$stale);
            }
        }

        // No network here: automatically checked cards count as passing when their claims pass offline.
        app()->bind(SourceCheckRecorder::class, OfflineSourceChecks::class);

        // Mock Vite so tests don't need npm run build
        app()->instance(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null): HtmlString
            {
                return new HtmlString('');
            }

            public function reactRefresh(): HtmlString
            {
                return new HtmlString('');
            }
        });

        Http::fake([
            'wttr.in/*' => Http::response([
                'current_condition' => [[
                    'temp_C' => '15',
                    'FeelsLikeC' => '14',
                    'humidity' => '65',
                    'weatherCode' => '116',
                    'winddirDegree' => '220',
                    'windspeedKmph' => '5',
                    'precipMM' => '0.0',
                ]],
                'weather' => [[
                    'hourly' => [
                        ['time' => '0', 'precipMM' => '0.0', 'WindGustKmph' => '12'],
                        ['time' => '300', 'precipMM' => '0.0', 'WindGustKmph' => '12'],
                        ['time' => '600', 'precipMM' => '0.0', 'WindGustKmph' => '12'],
                        ['time' => '900', 'precipMM' => '0.0', 'WindGustKmph' => '12'],
                        ['time' => '1200', 'precipMM' => '0.0', 'WindGustKmph' => '12'],
                        ['time' => '1500', 'precipMM' => '0.0', 'WindGustKmph' => '12'],
                        ['time' => '1800', 'precipMM' => '0.0', 'WindGustKmph' => '12'],
                        ['time' => '2100', 'precipMM' => '0.0', 'WindGustKmph' => '12'],
                    ],
                ]],
            ]),
            'api.open-meteo.com/*' => Http::response([
                'current' => [
                    'temperature_2m' => 15.0,
                    'apparent_temperature' => 14.0,
                    'wind_speed_10m' => 5.0,
                    'wind_gusts_10m' => 12.0,
                    'wind_direction_10m' => 220,
                    'relative_humidity_2m' => 65,
                    'precipitation' => 0.0,
                    'weather_code' => 2,
                    'cloud_cover' => 50,
                ],
                'hourly' => [
                    'time' => [],
                    'precipitation' => [],
                    'temperature_2m' => [],
                    'wind_speed_10m' => [],
                ],
            ]),
            'photon.komoot.io/*' => Http::response(['features' => []]),
        ]);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Seed the active Composer plan through the real encrypted store, stamped with
 * the user's current appointment revision so it passes the freshness check.
 *
 * @param  array<string, mixed>  $plan
 * @return array<string, mixed> the plan as stored
 */
function storeComposerPlan(User $user, array $plan): array
{
    $plan['appointment_revision'] = app(AppointmentRepository::class)
        ->revision($user, Constraints::fromArray($plan['constraints']));
    app(ActivePlanStore::class)->save($user, $plan);

    return $plan;
}

/** @return array<string, mixed>|null */
function storedComposerPlan(User $user): ?array
{
    return app(PrivatePlanCache::class)->get($user, 'plan');
}
