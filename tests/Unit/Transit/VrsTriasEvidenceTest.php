<?php

use App\Services\DisruptionService;
use App\Services\VrsTriasService;
use App\Transit\Dto\GeoPoint;
use App\Transit\TriasAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array', 'services.vrs.enabled' => true, 'services.vrs.trias_url' => 'https://trias.test']);
    Cache::flush();
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-06T21:45:00Z'));
    $this->mock(DisruptionService::class, fn ($mock) => $mock->shouldReceive('getDisruptedLines')->andReturn([]));
    Cache::put('trias_stop_id_v2_Ehrenfeld', ['id' => 'stop:1', 'name' => 'Ehrenfeld'], 3600);
});

function datedTriasStopEvent(string $journey, string $time, string $expected = '', string $serviceStatus = '', string $stopStatus = '', string $platform = ''): string
{
    $estimated = $expected === '' ? '' : "<EstimatedTime>{$expected}</EstimatedTime>";

    return <<<XML
    <StopEventResult><StopEvent>
      <ThisCall><CallAtStop><StopPointRef>stop:1</StopPointRef><StopPointName><Text>Ehrenfeld</Text></StopPointName>
        {$platform}<ServiceDeparture><TimetabledTime>{$time}</TimetabledTime>{$estimated}</ServiceDeparture>{$stopStatus}
      </CallAtStop></ThisCall>
      <Service><OperatingDayRef>2026-10-06</OperatingDayRef><JourneyRef>{$journey}</JourneyRef>
        <ServiceSection><LineRef>line:5</LineRef><PublishedLineName><Text>5</Text></PublishedLineName><Mode><PtMode>tram</PtMode></Mode></ServiceSection>
        <DestinationText><Text>Dom/Hbf</Text></DestinationText>{$serviceStatus}
      </Service>
    </StopEvent></StopEventResult>
    XML;
}

function datedTriasStopResponse(string $events): string
{
    return '<Trias><ServiceDelivery><DeliveryPayload><StopEventResponse>'.$events.'</StopEventResponse></DeliveryPayload></ServiceDelivery></Trias>';
}

test('TRIAS exposes each actual departure with timing evidence and its boarding point', function () {
    Http::fake(['trias.test' => Http::response(datedTriasStopResponse(
        datedTriasStopEvent('trip:1', '2026-10-06T21:50:00Z', '2026-10-06T21:52:00Z', '<Cancelled>true</Cancelled>', '', '<PlannedBay><Text>1</Text></PlannedBay><EstimatedBay><Text>2</Text></EstimatedBay>')
        .datedTriasStopEvent('trip:2', '2026-10-06T21:52:00Z')
    ))]);

    $result = app(VrsTriasService::class)->getDepartures('Ehrenfeld');

    expect($result)->toHaveKey('observed_at', '2026-10-06T21:45:00+00:00');
    $row = $result['departures'][0];
    expect($row['services'])->toHaveCount(2)
        ->and($row['services'][0])->toMatchArray([
            'departure_at' => '2026-10-06T21:52:00+00:00',
            'scheduled_at' => '2026-10-06T21:50:00+00:00',
            'expected_at' => '2026-10-06T21:52:00+00:00',
            'cancelled' => true,
            'delay' => 2,
            'time_basis' => 'realtime',
            'boarding_point' => ['id' => 'stop:1', 'name' => 'Ehrenfeld', 'platform' => '2'],
        ])
        ->and($row['services'][1])->toMatchArray([
            'expected_at' => null,
            'time_basis' => 'scheduled',
            'cancelled' => false,
            'boarding_point' => ['id' => 'stop:1', 'name' => 'Ehrenfeld', 'platform' => null],
        ])
        ->and($row['services'][0]['id'])->not->toBe($row['services'][1]['id'])
        ->and($row['cancelled'])->toBeTrue();
});

test('TRIAS does not offer a stop where boarding is unavailable', function (string $status) {
    Http::fake(['trias.test' => Http::response(datedTriasStopResponse(
        datedTriasStopEvent('trip:1', '2026-10-06T21:50:00Z', '', '<Cancelled>false</Cancelled>', $status)
    ))]);

    $row = app(VrsTriasService::class)->getDepartures('Ehrenfeld')['departures'][0];

    expect($row['services'][0]['cancelled'])->toBeTrue();
})->with(['<NotServicedStop>true</NotServicedStop>', '<NoBoardingAtStop>1</NoBoardingAtStop>']);

test('cached TRIAS observations keep their original age and service identities survive changed estimates', function () {
    Http::fake(['trias.test' => Http::sequence()
        ->push(datedTriasStopResponse(datedTriasStopEvent('trip:1', '2026-10-06T21:50:00Z', '2026-10-06T21:52:00Z')))
        ->push(datedTriasStopResponse(datedTriasStopEvent('trip:1', '2026-10-06T21:50:00Z', '2026-10-06T21:53:00Z'))),
    ]);

    $service = app(VrsTriasService::class);
    $first = $service->getDepartures('Ehrenfeld');
    $this->travel(30)->seconds();
    $cached = $service->getDepartures('Ehrenfeld');
    expect($cached)->toHaveKey('observed_at', '2026-10-06T21:45:00+00:00');
    $this->travel(31)->seconds();
    $second = $service->getDepartures('Ehrenfeld');

    expect($second)->toHaveKey('observed_at', '2026-10-06T21:46:01+00:00')
        ->and($second['departures'][0]['services'][0]['id'])->toBe($first['departures'][0]['services'][0]['id'])
        ->and($second['departures'][0]['services'][0]['expected_at'])->toBe('2026-10-06T21:53:00+00:00');
});

test('TRIAS journey parsing preserves provider dates through the routing adapter', function () {
    Http::fake(['trias.test' => Http::response(<<<'XML'
    <Trias><ServiceDelivery><DeliveryPayload><TripResponse><TripResult><Trip>
      <Duration>PT20M</Duration><StartTime>2026-10-06T21:50:00Z</StartTime><EndTime>2026-10-06T22:10:00Z</EndTime><Interchanges>0</Interchanges>
      <TripLeg><TimedLeg>
        <LegBoard><StopPointRef>stop:1</StopPointRef><StopPointName><Text>Ehrenfeld</Text></StopPointName><ServiceDeparture><TimetabledTime>2026-10-06T21:50:00Z</TimetabledTime></ServiceDeparture></LegBoard>
        <LegAlight><StopPointRef>stop:2</StopPointRef><StopPointName><Text>Dom/Hbf</Text></StopPointName><ServiceArrival><TimetabledTime>2026-10-06T22:10:00Z</TimetabledTime></ServiceArrival></LegAlight>
        <Service><ServiceSection><PublishedLineName><Text>5</Text></PublishedLineName><Mode><PtMode>tram</PtMode></Mode></ServiceSection></Service>
      </TimedLeg></TripLeg>
    </Trip></TripResult></TripResponse></DeliveryPayload></ServiceDelivery></Trias>
    XML)]);

    $journey = app(TriasAdapter::class)->plan(new GeoPoint(50.94, 6.94), new GeoPoint(50.95, 6.92))->journeys[0];

    expect($journey->departAt->toIso8601String())->toBe('2026-10-06T23:50:00+02:00')
        ->and($journey->arriveAt->toIso8601String())->toBe('2026-10-07T00:10:00+02:00')
        ->and($journey->legs[0]->arriveAt->toIso8601String())->toBe('2026-10-07T00:10:00+02:00');
});
