<?php

use App\Support\MockClock;
use App\Support\MockScenario;
use Illuminate\Http\Request;

test('the mock scenario cookie does nothing outside the local environment', function () {
    $request = Request::create('/', cookies: [MockClock::COOKIE => '2026-09-30T13:10:00+02:00', MockScenario::COOKIE => 'lift']);

    expect(app()->environment('local'))->toBeFalse()
        ->and(MockScenario::current($request))->toBeNull();
});

test('on this machine it needs the mock clock and a situation the mock has', function () {
    app()->detectEnvironment(fn () => 'local');
    $clock = [MockClock::COOKIE => '2026-09-30T13:10:00+02:00'];

    expect(MockScenario::current(Request::create('/', cookies: $clock + [MockScenario::COOKIE => 'lift'])))->toBe('lift')
        ->and(MockScenario::current(Request::create('/', cookies: [MockScenario::COOKIE => 'lift'])))->toBeNull()
        ->and(MockScenario::current(Request::create('/', cookies: $clock + [MockScenario::COOKIE => 'everything'])))->toBeNull();
});
