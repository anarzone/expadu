<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function configureHealthyApiDependencies(?string $motisUrl = 'https://motis.test'): void
{
    config()->set('services.vrs.trias_url', 'https://trias.test');
    config()->set('services.vrs.gtfsrt_url', 'https://gtfsrt.test');
    config()->set('services.motis.url', $motisUrl);
    config()->set('services.transitous.url', 'https://transitous.test');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fakeHealthyApiDependencies(array $overrides = []): void
{
    Http::fake(array_merge([
        'https://trias.test' => Http::response('<Trias/>'),
        'https://gtfsrt.test' => Http::response('gtfs-rt'),
        'data.webservice-kvb.koeln/*' => Http::response([['name' => 'Neumarkt']]),
        'api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 22]]),
        'api.brightsky.dev/*' => Http::response([]),
        'photon.komoot.io/*' => Http::response(['features' => []]),
        'www.pegelonline.wsv.de/*' => Http::response(['value' => 218]),
        'motis.test/api/v1/geocode*' => Http::response(['places' => []]),
        'transitous.test/api/v1/geocode*' => Http::response(['places' => []]),
    ], $overrides));
}

test('health monitoring probes the routing providers the application actually uses', function () {
    configureHealthyApiDependencies();
    config()->set('mail.default', 'resend');
    config()->set('services.resend.key', 'resend-test-key');

    fakeHealthyApiDependencies([
        'api.resend.com/domains' => Http::response([]),
    ]);

    $this->artisan('api:health')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://motis.test/api/v1/geocode?text=K%C3%B6ln%20Neumarkt&language=de');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://transitous.test/api/v1/geocode?text=K%C3%B6ln%20Neumarkt&language=de');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'valhalla'));
});

test('health monitoring accepts the local log mailer without probing Resend', function () {
    configureHealthyApiDependencies();
    config()->set('mail.default', 'log');
    config()->set('services.resend.key', null);
    fakeHealthyApiDependencies([
        'api.resend.com/*' => Http::response([], 500),
    ]);

    $this->artisan('api:health')->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.resend.com'));
});

test('health monitoring accepts a restricted Resend sending key', function () {
    configureHealthyApiDependencies();
    config()->set('mail.default', 'resend');
    config()->set('services.resend.key', 'send-only-test-key');
    fakeHealthyApiDependencies([
        'api.resend.com/domains' => Http::response([
            'statusCode' => 401,
            'message' => 'This API key is restricted to only send emails',
            'name' => 'restricted_api_key',
        ], 401),
    ]);

    $this->artisan('api:health')->assertSuccessful();
});

test('health monitoring skips MOTIS when it is not configured', function () {
    configureHealthyApiDependencies(null);
    config()->set('mail.default', 'log');
    fakeHealthyApiDependencies();

    $this->artisan('api:health')->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'localhost:8080'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'transitous.test/api/v1/geocode'));
});
