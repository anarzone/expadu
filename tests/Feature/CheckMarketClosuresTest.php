<?php

use App\Services\GermanHolidayService;
use Carbon\Carbon;

test('detects shops closed tomorrow on Saturday', function () {
    $service = app(GermanHolidayService::class);

    $this->travelTo(now()->next('Saturday')->setTime(14, 0));

    expect($service->isShopsClosedTomorrow())->toBeTrue();
});

test('detects shops closed before public holiday', function () {
    $service = app(GermanHolidayService::class);

    // Thursday before Karfreitag
    $this->travelTo(Carbon::parse('2026-04-02 14:00'));

    expect($service->isShopsClosedTomorrow())->toBeTrue();
    expect($service->getHolidayName(now()->addDay()))->toBe('Karfreitag');
});

test('shops open on regular weekday', function () {
    $service = app(GermanHolidayService::class);

    $this->travelTo(Carbon::parse('2026-04-08 14:00'));

    expect($service->isShopsClosedTomorrow())->toBeFalse();
});

test('weather alert command runs market closure check', function () {
    $this->travelTo(now()->next('Saturday')->setTime(14, 0));

    // The command integrates market closure — just verify it doesn't crash
    $this->artisan('weather:check-alerts')->assertSuccessful();
});
