<?php

use App\Support\SentryScrubber;
use Sentry\Event;

test('the scrubber redacts sensitive keys and coordinates', function () {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://app.expadu.com/api/location/confirm?lat=50.9&lng=6.9',
        'query_string' => 'lat=50.9&lng=6.9',
        'data' => ['password' => 'hunter2', 'lat' => 50.9, 'name' => 'Anna', 'nested' => ['api_key' => 'sk-live-xxx']],
    ]);
    $event->setExtra(['session' => 'abc', 'harmless' => 'ok']);

    $out = SentryScrubber::scrub($event);

    $req = $out->getRequest();
    expect($req['url'])->toBe('https://app.expadu.com/api/location/confirm');
    expect($req['query_string'])->toBe('[redacted]');
    expect($req['data']['password'])->toBe('[redacted]');
    expect($req['data']['lat'])->toBe('[redacted]');
    expect($req['data']['nested']['api_key'])->toBe('[redacted]');
    expect($req['data']['name'])->toBe('Anna');
    expect($out->getExtra()['session'])->toBe('[redacted]');
    expect($out->getExtra()['harmless'])->toBe('ok');
});
