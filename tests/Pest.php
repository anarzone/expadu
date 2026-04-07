<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Http;
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
