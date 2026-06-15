<?php

use App\Jobs\RefreshWeather;
use App\Services\Weather\BrightSkyProvider;
use App\Services\Weather\MetNoProvider;
use App\Services\Weather\OpenMeteoProvider;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WttrInProvider;
use App\Services\WeatherService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::flush();
    // The request path queues a background refresh instead of fetching inline;
    // fake the queue so it never runs the provider chain during a request.
    Queue::fake();
});

/**
 * Replace the global Http facade with a fresh Factory so stubs set up in
 * tests/Pest.php are cleared. Use this in provider-specific tests where we
 * need to assert on exact fake responses.
 */
function resetHttpFakes(): void
{
    Http::swap(new HttpFactory(app('events')));
}

/**
 * Minimal fake provider that returns a pre-baked payload or null.
 */
function fakeProvider(string $name, ?array $payload): WeatherProvider
{
    return new class($name, $payload) implements WeatherProvider
    {
        public function __construct(private string $providerName, private ?array $payload) {}

        public function fetch(float $lat, float $lng): ?array
        {
            return $this->payload;
        }

        public function name(): string
        {
            return $this->providerName;
        }
    };
}

function samplePayload(float $temp = 12.0, string $icon = 'clear-day'): array
{
    return [
        'current' => [
            'temperature' => $temp,
            'feels_like' => null,
            'icon' => $icon,
            'wind_speed' => 10.0,
            'wind_gust' => 20.0,
            'wind_direction' => 90.0,
            'humidity' => 55.0,
            'precipitation' => 0.0,
        ],
        'hourly' => [
            ['hour' => 0, 'precipitation' => 0.0],
            ['hour' => 1, 'precipitation' => 0.0],
        ],
    ];
}

it('never blocks the page: the request path reads cache only and refreshes in the background', function () {
    // A provider that records if it's called — the request path must NOT touch it.
    $spy = new class implements WeatherProvider
    {
        public bool $called = false;

        public function fetch(float $lat, float $lng): ?array
        {
            $this->called = true;

            return null;
        }

        public function name(): string
        {
            return 'spy';
        }
    };

    $weather = (new WeatherService([$spy]))->getCurrentWeather(50.9, 6.9);

    expect($spy->called)->toBeFalse()              // the slow provider chain is never hit synchronously
        ->and($weather['available'])->toBeFalse(); // …and it degrades honestly, not a fake 0°
    Queue::assertPushed(RefreshWeather::class);     // the refresh is queued for the background worker
});

it('uses first successful provider', function () {
    $service = new WeatherService([
        fakeProvider('primary', samplePayload(14.5, 'clear-day')),
        fakeProvider('fallback', samplePayload(99.9, 'cloudy')),
    ]);

    $service->fetchSync(50.9375, 6.9603); // warm the cache (the background path)
    $result = $service->getCurrentWeather(50.9375, 6.9603);

    expect($result['temperature'])->toBe(14)
        ->and($result['icon'])->toBe('clear-day')
        ->and($result['condition'])->toBe('Clear sky')
        ->and($result['available'])->toBeTrue();
});

it('falls back to next provider when primary returns null', function () {
    $service = new WeatherService([
        fakeProvider('primary', null),
        fakeProvider('fallback', samplePayload(8.0, 'rain')),
    ]);

    $service->fetchSync(50.9375, 6.9603);
    $result = $service->getCurrentWeather(50.9375, 6.9603);

    expect($result['temperature'])->toBe(8)
        ->and($result['icon'])->toBe('rain')
        ->and($result['condition'])->toBe('Rain');
});

it('returns unavailable placeholder when there is no cached data', function () {
    $service = new WeatherService([
        fakeProvider('primary', null),
        fakeProvider('fallback', null),
    ]);

    // Request path on a cold cache: never fetches, returns the honest placeholder.
    $result = $service->getCurrentWeather(50.9375, 6.9603);

    expect($result['condition'])->toBe('Unavailable')
        ->and($result['available'])->toBeFalse();
});

it('cools down a failing provider so it is skipped on the next call', function () {
    $provider = new class implements WeatherProvider
    {
        public int $calls = 0;

        public function fetch(float $lat, float $lng): ?array
        {
            $this->calls++;

            return null;
        }

        public function name(): string
        {
            return 'test_cooldown_provider';
        }
    };

    $service = new WeatherService([$provider]);
    $service->fetchSync(50.9, 6.9);
    $service->fetchSync(50.9, 6.9);

    // First call hits the provider and blacklists it; the second call skips it.
    // This is the intended perf win — we don't pay HTTP cost on a known-failing
    // provider until its 5-minute cooldown expires.
    expect($provider->calls)->toBe(1);
});

it('caches successful responses so the request path never re-fetches', function () {
    $provider = new class implements WeatherProvider
    {
        public int $calls = 0;

        public function fetch(float $lat, float $lng): ?array
        {
            $this->calls++;

            return [
                'current' => [
                    'temperature' => 10.0,
                    'feels_like' => null,
                    'icon' => 'clear-day',
                    'wind_speed' => 5.0,
                    'wind_gust' => 0.0,
                    'wind_direction' => 0.0,
                    'humidity' => 50.0,
                    'precipitation' => 0.0,
                ],
                'hourly' => [],
            ];
        }

        public function name(): string
        {
            return 'test';
        }
    };

    $service = new WeatherService([$provider]);
    $service->fetchSync(50.9, 6.9);          // one provider hit, warms the cache
    $service->getCurrentWeather(50.9, 6.9);  // served from cache, no provider hit
    $service->getCurrentWeather(50.9, 6.9);

    expect($provider->calls)->toBe(1);
});

it('passes through feels_like when provider supplies it', function () {
    $payload = samplePayload(15.0, 'clear-day');
    $payload['current']['feels_like'] = 18.4;

    $service = new WeatherService([fakeProvider('test', $payload)]);
    $service->fetchSync(50.9, 6.9);
    $result = $service->getCurrentWeather(50.9, 6.9);

    expect($result['feels_like'])->toBe(18);
});

it('returns null feels_like when provider does not supply it', function () {
    // No estimation: missing feels_like stays null end-to-end.
    $payload = samplePayload(0.0, 'cloudy');
    $payload['current']['feels_like'] = null;
    $payload['current']['humidity'] = 60.0;
    $payload['current']['wind_speed'] = 30.0;

    $service = new WeatherService([fakeProvider('test', $payload)]);
    $service->fetchSync(50.9, 6.9);
    $result = $service->getCurrentWeather(50.9, 6.9);

    expect($result['feels_like'])->toBeNull();
});

it('open-meteo provider parses apparent_temperature', function () {
    resetHttpFakes();
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'current' => [
                'temperature_2m' => 15.0,
                'apparent_temperature' => 18.2,
                'relative_humidity_2m' => 47,
                'precipitation' => 0.0,
                'weather_code' => 0,
                'wind_speed_10m' => 5.0,
                'wind_direction_10m' => 85,
                'wind_gusts_10m' => 14.0,
                'cloud_cover' => 5,
            ],
            'hourly' => [
                'time' => ['2026-04-07T12:00'],
                'precipitation' => [0.0],
            ],
        ]),
    ]);

    $data = (new OpenMeteoProvider)->fetch(50.9, 6.9);

    expect($data)->not->toBeNull()
        ->and($data['current']['temperature'])->toBe(15.0)
        ->and($data['current']['feels_like'])->toBe(18.2)
        ->and($data['current']['wind_speed'])->toBe(5.0)
        ->and($data['current']['wind_gust'])->toBe(14.0)
        ->and($data['current']['humidity'])->toBe(47.0);
});

it('wttr.in provider parses FeelsLikeC and pulls gust from current hourly slot', function () {
    resetHttpFakes();
    // Cover the full day so the test is time-independent: each 3-hour slot
    // carries WindGustKmph=14, so the current-hour lookup always finds 14.
    $slots = [];
    foreach ([0, 300, 600, 900, 1200, 1500, 1800, 2100] as $time) {
        $slots[] = [
            'time' => (string) $time,
            'precipMM' => '0.0',
            'WindGustKmph' => '14',
        ];
    }

    Http::fake([
        'wttr.in/*' => Http::response([
            'current_condition' => [[
                'temp_C' => '15',
                'FeelsLikeC' => '18',
                'humidity' => '47',
                'weatherCode' => '113',
                'winddirDegree' => '85',
                'windspeedKmph' => '5',
                'precipMM' => '0.0',
            ]],
            'weather' => [[
                'hourly' => $slots,
            ]],
        ]),
    ]);

    $data = (new WttrInProvider)->fetch(50.9, 6.9);

    expect($data)->not->toBeNull()
        ->and($data['current']['temperature'])->toBe(15.0)
        ->and($data['current']['feels_like'])->toBe(18.0)
        ->and($data['current']['wind_speed'])->toBe(5.0)
        ->and($data['current']['wind_gust'])->toBe(14.0)
        ->and($data['current']['humidity'])->toBe(47.0)
        ->and($data['current']['icon'])->toBeIn(['clear-day', 'clear-night']);
});

it('detects rain start from hourly forecast', function () {
    $hour = now('Europe/Berlin')->hour;

    if ($hour >= 22) {
        // Avoid edge case where there's no room for a future rain hour.
        $this->markTestSkipped('Test cannot run in final 2 hours of the day.');
    }

    $rainHour = $hour + 2;

    $payload = samplePayload();
    $payload['hourly'] = [
        ['hour' => $hour, 'precipitation' => 0.0],
        ['hour' => $hour + 1, 'precipitation' => 0.0],
        ['hour' => $rainHour, 'precipitation' => 0.5],
    ];

    $service = new WeatherService([fakeProvider('test', $payload)]);
    $service->fetchSync(50.9, 6.9);
    $forecast = $service->getForecast(50.9, 6.9);

    expect($forecast['rain_starts'])->toBe(str_pad((string) $rainHour, 2, '0', STR_PAD_LEFT).':00')
        ->and($forecast['available'])->toBeTrue();
});

it('brightsky provider uses station observations for current when available', function () {
    resetHttpFakes();
    Http::fake([
        'api.brightsky.dev/current_weather*' => Http::response([
            'weather' => [
                'timestamp' => now('Europe/Berlin')->toIso8601String(),
                'temperature' => 13.2,
                'icon' => 'clear-day',
                'wind_speed_10' => 4.3,
                'wind_gust_speed_10' => 11.2,
                'wind_direction_10' => 110,
                'relative_humidity' => 44,
                'precipitation_60' => 0.0,
            ],
        ]),
        'api.brightsky.dev/weather*' => Http::response([
            'weather' => [
                [
                    'timestamp' => now('Europe/Berlin')->format('Y-m-d\TH:00:00+02:00'),
                    'temperature' => 13.1,
                    'icon' => 'clear-day',
                    'wind_speed' => 9.3,
                    'wind_gust_speed' => 16.7,
                    'wind_direction' => 81,
                    'relative_humidity' => null,
                    'precipitation' => 0.0,
                ],
            ],
        ]),
    ]);

    $data = (new BrightSkyProvider)->fetch(50.9, 6.9);

    expect($data)->not->toBeNull()
        ->and($data['current']['temperature'])->toBe(13.2)
        ->and($data['current']['icon'])->toBe('clear-day')
        ->and($data['current']['wind_speed'])->toBe(4.3)
        ->and($data['current']['wind_gust'])->toBe(11.2)
        ->and($data['current']['humidity'])->toBe(44.0)
        ->and($data['hourly'])->toHaveCount(1);
});

it('brightsky provider falls back to hourly entry when current_weather fails', function () {
    resetHttpFakes();
    Http::fake([
        'api.brightsky.dev/current_weather*' => Http::response('Not Found', 404),
        'api.brightsky.dev/weather*' => Http::response([
            'weather' => [
                [
                    'timestamp' => now('Europe/Berlin')->format('Y-m-d\TH:00:00+02:00'),
                    'temperature' => 13.1,
                    'icon' => 'clear-day',
                    'wind_speed' => 9.3,
                    'wind_gust_speed' => 16.7,
                    'wind_direction' => 81,
                    'relative_humidity' => null,
                    'precipitation' => 0.0,
                ],
            ],
        ]),
    ]);

    $data = (new BrightSkyProvider)->fetch(50.9, 6.9);

    expect($data)->not->toBeNull()
        ->and($data['current']['temperature'])->toBe(13.1)
        ->and($data['current']['humidity'])->toBeNull();
});

it('brightsky provider returns null when hourly endpoint fails', function () {
    resetHttpFakes();
    Http::fake([
        'api.brightsky.dev/current_weather*' => Http::response([
            'weather' => ['temperature' => 13.2],
        ]),
        'api.brightsky.dev/weather*' => Http::response('Bad Gateway', 502),
    ]);

    expect((new BrightSkyProvider)->fetch(50.9, 6.9))->toBeNull();
});

it('metno provider parses response and converts m/s to km/h', function () {
    resetHttpFakes();
    Http::fake([
        'api.met.no/*' => Http::response([
            'properties' => [
                'timeseries' => [
                    [
                        'time' => now('Europe/Berlin')->setTimezone('UTC')->format('Y-m-d\TH:00:00\Z'),
                        'data' => [
                            'instant' => [
                                'details' => [
                                    'air_temperature' => 11.7,
                                    'relative_humidity' => 58.6,
                                    'wind_from_direction' => 108.6,
                                    'wind_speed' => 10.0,
                                ],
                            ],
                            'next_1_hours' => [
                                'summary' => ['symbol_code' => 'clearsky_day'],
                                'details' => ['precipitation_amount' => 0.0],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $data = (new MetNoProvider)->fetch(50.9, 6.9);

    expect($data)->not->toBeNull()
        ->and($data['current']['temperature'])->toBe(11.7)
        ->and($data['current']['icon'])->toBe('clear-day')
        ->and($data['current']['wind_speed'])->toBe(36.0)
        ->and($data['current']['humidity'])->toBe(58.6);
});

it('metno provider maps symbol codes to icon vocab', function (string $symbol, string $expected) {
    resetHttpFakes();
    Http::fake([
        'api.met.no/*' => Http::response([
            'properties' => [
                'timeseries' => [[
                    'time' => now('Europe/Berlin')->setTimezone('UTC')->format('Y-m-d\TH:00:00\Z'),
                    'data' => [
                        'instant' => ['details' => ['air_temperature' => 10, 'wind_speed' => 0]],
                        'next_1_hours' => [
                            'summary' => ['symbol_code' => $symbol],
                            'details' => ['precipitation_amount' => 0],
                        ],
                    ],
                ]],
            ],
        ]),
    ]);

    $data = (new MetNoProvider)->fetch(50.9, 6.9);
    expect($data['current']['icon'])->toBe($expected);
})->with([
    'clearsky_day' => ['clearsky_day', 'clear-day'],
    'clearsky_night' => ['clearsky_night', 'clear-night'],
    'partlycloudy_day' => ['partlycloudy_day', 'partly-cloudy-day'],
    'fair_night' => ['fair_night', 'partly-cloudy-night'],
    'cloudy' => ['cloudy', 'cloudy'],
    'fog' => ['fog', 'fog'],
    'lightrain' => ['lightrain', 'rain'],
    'heavyrainshowers_day' => ['heavyrainshowers_day', 'rain'],
    'snow' => ['snow', 'snow'],
    'lightsleet' => ['lightsleet', 'sleet'],
    'rainandthunder' => ['rainandthunder', 'thunderstorm'],
]);

it('returns wind_gust key not wind_gusts in getCurrentWeather', function () {
    $payload = samplePayload();
    $payload['current']['wind_gust'] = 65.0;

    $service = new WeatherService([fakeProvider('test', $payload)]);
    $service->fetchSync(50.9, 6.9);
    $result = $service->getCurrentWeather(50.9, 6.9);

    expect($result)->toHaveKey('wind_gust')
        ->and($result)->not->toHaveKey('wind_gusts')
        ->and($result['wind_gust'])->toBe(65);
});
