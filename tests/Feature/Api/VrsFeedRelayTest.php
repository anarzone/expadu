<?php

use App\Services\VrsRealtimeService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config([
        'services.vrs.enabled' => true,
        'services.vrs.gtfsrt_url' => 'https://gtfs-rt-test.vrs.de:4443/buffer/tripUpdate.buf',
        'services.vrs.relay_token' => null,
    ]);
    // A GTFS-RT feed's bytes, as VRS sends them: passed on untouched, never read here.
    $this->feed = "\x0a\x0d\x0a\x03\x32\x2e\x30\x10\x00\x18\xd2\x85\xd8\xcc\x06";
    Http::fake(['*gtfs-rt-test.vrs.de*' => Http::response($this->feed, 200)]);
    $this->token = app(VrsRealtimeService::class)->relayToken();
});

it('is no route at all without the router token', function (?string $sent) {
    $request = $sent === null ? $this : $this->withHeader('X-Relay-Token', $sent);

    $request->get('/api/internal/vrs-gtfs-rt')->assertNotFound();
    Http::assertNothingSent();
})->with([
    'no token' => [null],
    'a wrong token' => ['not-the-token'],
    'an empty token' => [''],
]);

it('is shut when live times are off, token or not', function () {
    config(['services.vrs.enabled' => false]);

    $this->withHeader('X-Relay-Token', $this->token)->get('/api/internal/vrs-gtfs-rt')->assertNotFound();
});

it('derives the token from the app key unless one is set, and takes the set one', function () {
    expect($this->token)->toBe(hash_hmac('sha256', 'motis-gtfsrt-relay', (string) config('app.key')));

    config(['services.vrs.relay_token' => 'set-for-the-router']);
    $this->withHeader('X-Relay-Token', $this->token)->get('/api/internal/vrs-gtfs-rt')->assertNotFound();
    $this->withHeader('X-Relay-Token', 'set-for-the-router')->get('/api/internal/vrs-gtfs-rt')->assertOk();
});

it('passes the feed on as VRS sent it, and fetches it once while fresh', function () {
    $first = $this->withHeader('X-Relay-Token', $this->token)->get('/api/internal/vrs-gtfs-rt');
    $again = $this->withHeader('X-Relay-Token', $this->token)->get('/api/internal/vrs-gtfs-rt');

    $first->assertOk()->assertHeader('Content-Type', 'application/x-protobuf');
    expect($first->getContent())->toBe($this->feed)
        ->and($again->getContent())->toBe($this->feed);
    Http::assertSentCount(1);
});

it('keeps the raw feed fresh for the router without reading it, once the router has asked', function () {
    Artisan::call('transit:fetch-gtfsrt');
    Http::assertNothingSent();

    $this->withHeader('X-Relay-Token', $this->token)->get('/api/internal/vrs-gtfs-rt')->assertOk();
    Artisan::call('transit:fetch-gtfsrt');

    Http::assertSentCount(2);
    expect(Artisan::output())->toContain('Raw feed kept for the router: '.strlen($this->feed).' bytes.')
        // Not read: the parsed feed the boards use stays as it was (nothing).
        ->and(Cache::get('vrs_gtfsrt_trip_updates_v2'))->toBeNull();
});

it('answers 503 when VRS has never been read', function () {
    Http::swap(new Factory);
    Http::fake(['*gtfs-rt-test.vrs.de*' => Http::response('', 500)]);

    $this->withHeader('X-Relay-Token', $this->token)->get('/api/internal/vrs-gtfs-rt')->assertStatus(503);
});
