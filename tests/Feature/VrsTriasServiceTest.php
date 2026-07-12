<?php

use App\Services\VrsTriasService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config(['services.vrs.enabled' => true]);
    config(['services.vrs.trias_url' => 'https://apitest.vrs.de:4443/v1/trias']);
    config(['services.vrs.requestor_ref' => 'expadu']);
});

// ── planJourney ──

it('planJourney returns null when disabled', function () {
    config(['services.vrs.enabled' => false]);

    $service = app(VrsTriasService::class);
    $result = $service->planJourney(50.94, 6.96, 50.93, 6.95);

    expect($result)->toBeNull();
});

it('planJourney returns null on HTTP failure', function () {
    Http::fake(['*apitest.vrs.de*' => Http::response('', 500)]);

    $service = app(VrsTriasService::class);
    $result = $service->planJourney(50.94, 6.96, 50.93, 6.95);

    expect($result)->toBeNull();
});

it('planJourney returns null on network error', function () {
    Http::fake(['*apitest.vrs.de*' => fn () => throw new Exception('Timeout')]);

    $service = app(VrsTriasService::class);
    $result = $service->planJourney(50.94, 6.96, 50.93, 6.95);

    expect($result)->toBeNull();
});

it('planJourney parses trip response with timed and continuous legs', function () {
    Http::fake(['*apitest.vrs.de*' => Http::response(triasTrip())]);

    $service = app(VrsTriasService::class);
    $result = $service->planJourney(50.94, 6.96, 50.93, 6.95);

    expect($result)->not->toBeNull()
        ->and($result['source'])->toBe('trias')
        ->and($result['trips'])->toBeArray()
        ->and($result['trips'])->not->toBeEmpty();

    $trip = $result['trips'][0];
    expect($trip)->toHaveKeys(['segments', 'steps', 'total_duration_min', 'departure_time', 'arrival_time', 'transfers']);
    expect($trip['segments'])->toBeArray();
    expect($trip['steps'])->toBeArray();
});

it('planJourney caches results', function () {
    Http::fake(['*apitest.vrs.de*' => Http::response(triasTrip())]);

    $service = app(VrsTriasService::class);
    $service->planJourney(50.94, 6.96, 50.93, 6.95);
    $service->planJourney(50.94, 6.96, 50.93, 6.95);

    // Should only make one HTTP request due to caching
    Http::assertSentCount(1);
});

it('planJourney returns null when API fails after retry', function () {
    Http::fake(['*' => Http::response('Server Error', 500)]);

    $service = app(VrsTriasService::class);
    $result = $service->planJourney(50.94, 6.96, 50.93, 6.95);

    expect($result)->toBeNull();
});

// ── getDepartures ──

it('getDepartures returns null when disabled', function () {
    config(['services.vrs.enabled' => false]);

    $service = app(VrsTriasService::class);
    $result = $service->getDepartures('Ehrenfeld');

    expect($result)->toBeNull();
});

it('getDepartures returns departures on valid response', function () {
    Http::fake(['*apitest.vrs.de*' => Http::sequence()
        ->push(triasLocation()) // resolveStopGlobalId call
        ->push(triasStopEvents()), // fetchStopEvents call
    ]);

    $service = app(VrsTriasService::class);
    $result = $service->getDepartures('Ehrenfeld');

    expect($result)->not->toBeNull()
        ->and($result['source'])->toBe('trias_rt')
        ->and($result['departures'])->toBeArray();
});

// ── resolveStopGlobalId ──

it('resolveStopGlobalId returns null on empty response', function () {
    Http::fake(['*apitest.vrs.de*' => Http::response(triasEmptyLocation())]);

    $service = app(VrsTriasService::class);
    $result = $service->resolveStopGlobalId('NonexistentStop');

    expect($result)->toBeNull();
});

it('resolveStopGlobalId returns id and name', function () {
    Http::fake(['*apitest.vrs.de*' => Http::response(triasLocation())]);

    $service = app(VrsTriasService::class);
    $result = $service->resolveStopGlobalId('Ehrenfeld');

    expect($result)->not->toBeNull()
        ->and($result)->toHaveKeys(['id', 'name'])
        ->and($result['id'])->toBeString()
        ->and($result['name'])->toBeString();
});

it('rejects a fuzzy city-prefixed stop and retries with the exact stop name', function () {
    Http::fake(['*apitest.vrs.de*' => Http::sequence()
        ->push(triasNamedLocation('de:05315:15461', 'Niehl Betriebshof Nord'))
        ->push(triasNamedLocation('de:05315:15411', 'Niehl Nord')),
    ]);

    $result = app(VrsTriasService::class)->resolveStopGlobalId('Niehl Nord');

    expect($result)->toBe([
        'id' => 'de:05315:15411',
        'name' => 'Niehl Nord',
    ]);
    Http::assertSentCount(2);
});

// ── Helper functions: TRIAS XML stubs ──

function triasTrip(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<Trias xmlns="http://www.vdv.de/trias" version="1.2">
<ServiceDelivery>
<DeliveryPayload>
<TripResponse>
<TripResponseContext>
<ServiceDeparture>
<TimetabledTime>2026-04-03T19:00:00Z</TimetabledTime>
</ServiceDeparture>
</TripResponseContext>
<TripResult>
<ResultId>1</ResultId>
<Trip>
<TripId>trip_1</TripId>
<Duration>PT23M</Duration>
<StartTime>2026-04-03T19:00:00Z</StartTime>
<EndTime>2026-04-03T19:23:00Z</EndTime>
<Interchanges>0</Interchanges>
<TripLeg>
<ContinuousLeg>
<LegStart><GeoPosition><Longitude>6.9400</Longitude><Latitude>50.9400</Latitude></GeoPosition><LocationName><Text>Your location</Text></LocationName></LegStart>
<LegEnd><GeoPosition><Longitude>6.9450</Longitude><Latitude>50.9420</Latitude></GeoPosition><LocationName><Text>Merkenich Mitte</Text></LocationName></LegEnd>
<Service><IndividualMode>walk</IndividualMode></Service>
<Duration>PT5M</Duration>
<TimeWindowStart>2026-04-03T19:00:00Z</TimeWindowStart>
<TimeWindowEnd>2026-04-03T19:05:00Z</TimeWindowEnd>
</ContinuousLeg>
</TripLeg>
<TripLeg>
<TimedLeg>
<LegBoard><StopPointRef>de:05315:16510</StopPointRef><StopPointName><Text>Merkenich Mitte</Text></StopPointName><ServiceDeparture><TimetabledTime>2026-04-03T19:06:00Z</TimetabledTime><EstimatedTime>2026-04-03T19:06:00Z</EstimatedTime></ServiceDeparture></LegBoard>
<LegAlight><StopPointRef>de:05315:18001</StopPointRef><StopPointName><Text>Ebertplatz</Text></StopPointName><ServiceArrival><TimetabledTime>2026-04-03T19:20:00Z</TimetabledTime><EstimatedTime>2026-04-03T19:21:00Z</EstimatedTime></ServiceArrival></LegAlight>
<Service><JourneyRef>vrs:12345</JourneyRef><LineRef>vrs:12</LineRef><DirectionRef>outward</DirectionRef><Mode><PtMode>tram</PtMode></Mode><PublishedLineName><Text>12</Text></PublishedLineName><DestinationText><Text>Zollstock Südfriedhof</Text></DestinationText></Service>
</TimedLeg>
</TripLeg>
<TripLeg>
<ContinuousLeg>
<LegStart><GeoPosition><Longitude>6.9570</Longitude><Latitude>50.9530</Latitude></GeoPosition><LocationName><Text>Ebertplatz</Text></LocationName></LegStart>
<LegEnd><GeoPosition><Longitude>6.9580</Longitude><Latitude>50.9540</Latitude></GeoPosition><LocationName><Text>Destination</Text></LocationName></LegEnd>
<Service><IndividualMode>walk</IndividualMode></Service>
<Duration>PT3M</Duration>
<TimeWindowStart>2026-04-03T19:20:00Z</TimeWindowStart>
<TimeWindowEnd>2026-04-03T19:23:00Z</TimeWindowEnd>
</ContinuousLeg>
</TripLeg>
</Trip>
</TripResult>
</TripResponse>
</DeliveryPayload>
</ServiceDelivery>
</Trias>';
}

function triasStopEvents(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<Trias xmlns="http://www.vdv.de/trias" version="1.2">
<ServiceDelivery>
<DeliveryPayload>
<StopEventResponse>
<StopEventResult>
<StopEvent>
<ThisCall><CallAtStop><StopPointRef>de:05315:11101</StopPointRef><ServiceDeparture><TimetabledTime>2026-04-03T20:00:00Z</TimetabledTime><EstimatedTime>2026-04-03T20:01:00Z</EstimatedTime></ServiceDeparture></CallAtStop></ThisCall>
<Service><JourneyRef>vrs:111</JourneyRef><LineRef>vrs:13</LineRef><Mode><PtMode>tram</PtMode></Mode><PublishedLineName><Text>13</Text></PublishedLineName><DestinationText><Text>Sülzgürtel</Text></DestinationText></Service>
</StopEvent>
</StopEventResult>
</StopEventResponse>
</DeliveryPayload>
</ServiceDelivery>
</Trias>';
}

function triasEmptyLocation(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<Trias xmlns="http://www.vdv.de/trias" version="1.2">
<ServiceDelivery>
<DeliveryPayload>
<LocationInformationResponse>
</LocationInformationResponse>
</DeliveryPayload>
</ServiceDelivery>
</Trias>';
}

function triasLocation(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<Trias xmlns="http://www.vdv.de/trias" version="1.2">
<ServiceDelivery>
<DeliveryPayload>
<LocationInformationResponse>
<Location><Location><StopPlace><StopPlaceRef>de:05315:11101</StopPlaceRef><StopPlaceName><Text>Köln Ehrenfeld</Text></StopPlaceName></StopPlace><GeoPosition><Longitude>6.9183</Longitude><Latitude>50.9478</Latitude></GeoPosition></Location></Location>
</LocationInformationResponse>
</DeliveryPayload>
</ServiceDelivery>
</Trias>';
}

function triasNamedLocation(string $id, string $name): string
{
    return str_replace(
        ['de:05315:11101', 'Köln Ehrenfeld'],
        [$id, $name],
        triasLocation(),
    );
}
