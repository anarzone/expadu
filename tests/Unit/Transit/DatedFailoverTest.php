<?php

use App\Services\NearbyStopService;
use App\Transit\CircuitBreaker;
use App\Transit\Dto\GeoPoint;
use App\Transit\FailoverRouteService;
use App\Transit\MotisAdapter;
use App\Transit\TransitousAdapter;
use App\Transit\TriasAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

test('unsupported dated TRIAS fallback does not record a provider outage', function ($arriveBy) {
    Cache::flush();
    config(['services.motis.url' => null]);
    $transitous = Mockery::mock(TransitousAdapter::class);
    $transitous->shouldReceive('withTimeBudget')->andReturnSelf();
    $transitous->shouldReceive('plan')->andThrow(new RuntimeException('unavailable'));
    $trias = Mockery::mock(TriasAdapter::class);
    $trias->shouldNotReceive('plan');
    $breaker = Mockery::mock(CircuitBreaker::class);
    $breaker->shouldReceive('isOpen')->andReturnFalse();
    $breaker->shouldReceive('recordFailure')->with('transitous')->once();
    $breaker->shouldNotReceive('recordFailure')->with('trias');
    $nearby = Mockery::mock(NearbyStopService::class);
    $nearby->shouldReceive('getDeparturesByType')->andReturn(['kvb' => []]);
    $service = new FailoverRouteService(Mockery::mock(MotisAdapter::class), $transitous, $trias, $breaker, $nearby);

    $result = $service->plan(new GeoPoint(50.94, 6.94), new GeoPoint(50.95, 6.95), CarbonImmutable::parse('2026-10-06T14:00:00+02:00'), arriveBy: $arriveBy);
    expect($result->source)->toBe('degraded')->and($result->journeys)->toBe([]);
})->with([false, true]);
