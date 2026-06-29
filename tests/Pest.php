<?php

use App\Home\HomeContext;
use App\Models\User;
use App\Models\UserTask;
use App\Profile\ProfileEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\HtmlString;
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

        // Per-user location anchors live in raw Redis (not the array cache), so
        // they survive DB rollback and leak a "confirmed"/ping origin into the
        // next test that reuses an auto-increment id. Clear the low id range
        // each test creates so every test starts with no live location.
        for ($id = 1; $id <= 50; $id++) {
            Redis::del("confirmed_location:{$id}", "location_history:{$id}");
        }

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
 * Build a HomeContext for home-feed unit tests — real profile + open tasks,
 * with controllable now/rain/weights/events.
 *
 * @param  array<string, mixed>  $overrides
 */
function homeContext(User $user, array $overrides = []): HomeContext
{
    return new HomeContext(
        userId: $user->id,
        profile: app(ProfileEngine::class)->build($user),
        now: $overrides['now'] ?? CarbonImmutable::now('Europe/Berlin'),
        rainExpected: $overrides['rainExpected'] ?? false,
        rainSummary: $overrides['rainSummary'] ?? null,
        intentWeights: $overrides['intentWeights'] ?? [],
        isWeekendWindow: $overrides['isWeekendWindow'] ?? false,
        isEvening: $overrides['isEvening'] ?? false,
        openTasks: $overrides['openTasks'] ?? UserTask::query()
            ->where('user_id', $user->id)
            ->open()
            ->notSnoozed()
            ->with('task')
            ->get(),
        tonightEvents: $overrides['tonightEvents'] ?? collect(),
    );
}
