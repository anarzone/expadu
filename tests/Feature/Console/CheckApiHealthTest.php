<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('health monitoring probes the routing providers the application actually uses', function () {
    config()->set('services.vrs.trias_url', 'https://trias.test');
    config()->set('services.vrs.gtfsrt_url', 'https://gtfsrt.test');
    config()->set('services.resend.key', 'resend-test-key');
    config()->set('services.motis.url', 'https://motis.test');
    config()->set('services.transitous.url', 'https://transitous.test');

    Http::fake([
        'https://trias.test' => Http::response('<Trias/>'),
        'https://gtfsrt.test' => Http::response('gtfs-rt'),
        'data.webservice-kvb.koeln/*' => Http::response([['name' => 'Neumarkt']]),
        'api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 22]]),
        'api.brightsky.dev/*' => Http::response([]),
        'photon.komoot.io/*' => Http::response(['features' => []]),
        'www.pegelonline.wsv.de/*' => Http::response(['value' => 218]),
        'api.resend.com/domains' => Http::response([]),
        'motis.test/api/v1/geocode*' => Http::response(['places' => []]),
        'transitous.test/api/v1/geocode*' => Http::response(['places' => []]),
    ]);

    $this->artisan('api:health')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://motis.test/api/v1/geocode?text=K%C3%B6ln%20Neumarkt&language=de');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://transitous.test/api/v1/geocode?text=K%C3%B6ln%20Neumarkt&language=de');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'valhalla'));
});
