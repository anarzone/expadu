import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    parseTravelDestination,
    travelDestinationParams,
    scheduledTravelDestination,
    composerTravelHref,
    travelRouteSignature,
} from '../../resources/js/components/departures/travel-request.ts';

const destination = {
    name: 'A facility, not the city centre',
    destinationId: 'osm:way/4321',
    lat: 50.9345,
    lng: 6.9532,
    arriveBy: '2026-10-09T10:17:00+02:00',
    activityId: 'manual:17',
    planDate: '2026-10-09',
    backLabel: 'My appointment',
    preferredMode: 'walk',
    fromLat: 50.9,
    fromLng: 6.9,
    fromName: 'Office',
};

test('a refresh retains facility identity, exact arrival and originating activity', () => {
    const url =
        '/timetable?' +
        new URLSearchParams(travelDestinationParams(destination));
    assert.deepEqual(parseTravelDestination(url), destination);
});

test('invalid and partial coordinates cannot become a journey destination', () => {
    for (const coordinates of [
        'to_lat=&to_lng=6',
        'to_lat=NaN&to_lng=6',
        'to_lat=91&to_lng=6',
        'to_lat=50&to_lng=181',
    ]) {
        assert.equal(
            parseTravelDestination('/timetable?to_name=Park&' + coordinates),
            null,
        );
    }

    assert.equal(
        parseTravelDestination('/timetable?to_name=Equator&to_lat=0&to_lng=0')
            .lat,
        0,
    );
});

test('changing the requested day detaches the old activity but retains the exact destination', () => {
    const changed = scheduledTravelDestination(
        destination,
        'arrive',
        '2026-10-10T10:17',
    );
    assert.equal(changed.activityId, undefined);
    assert.equal(changed.planDate, undefined);
    assert.equal(changed.arriveBy, '2026-10-10T10:17');
    assert.equal(changed.destinationId, 'osm:way/4321');
    const sameDay = scheduledTravelDestination(
        destination,
        'arrive',
        '2026-10-09T09:55',
    );
    assert.equal(sameDay.activityId, 'manual:17');
});

test('leaving now clears a fixed deadline and cannot silently retain a future activity', () => {
    const changed = scheduledTravelDestination(destination, 'now', '');
    assert.equal(changed.arriveBy, undefined);
    assert.equal(changed.departAt, undefined);
    assert.equal(changed.activityId, undefined);
});

test('Composer receives a bounded review context without a prompt or auto-send parameter', () => {
    const href = composerTravelHref(destination, {
        requestedDate: '2026-10-09',
        requestedTime: '10:17',
        schedule: 'arrive',
    });
    const url = new URL(href, 'https://example.test');
    assert.equal(url.pathname, '/composer');
    assert.deepEqual([...url.searchParams.keys()], ['travel']);
    const context = JSON.parse(url.searchParams.get('travel'));
    assert.equal(context.destinationId, 'osm:way/4321');
    assert.equal(context.lat, 50.9345);
    assert.equal(context.activityId, 'manual:17');
    assert.equal(context.requestedTime, '10:17');
    assert.equal(context.requestedDate, '2026-10-09');
    assert.equal(context.origin.name, 'Office');
    assert.equal(context.preferredMode, 'walk');
    assert.ok(href.length < 8000);
});

test('route identity separates opposite directions and walk from bike', () => {
    const make = (mode, from, to) => ({
        mode,
        legs: [
            {
                mode,
                line: '18',
                headsign: to,
                from: { name: from },
                to: { name: to },
            },
        ],
    });
    assert.notEqual(
        travelRouteSignature(make('transit', 'A', 'B')),
        travelRouteSignature(make('transit', 'B', 'A')),
    );
    assert.notEqual(
        travelRouteSignature(make('walk', 'A', 'B')),
        travelRouteSignature(make('bike', 'A', 'B')),
    );
});

test('Composer does not attach an itinerary arriving on another day to the original activity', () => {
    const href = composerTravelHref(
        destination,
        {
            requestedDate: '2026-10-09',
            requestedTime: '10:17',
            schedule: 'arrive',
        },
        {
            mode: 'transit',
            depart_at: '2026-10-09T23:45:00+02:00',
            arrive_at: '2026-10-10T00:02:00+02:00',
            duration_min: 17,
            legs: [],
        },
    );
    const context = JSON.parse(
        new URL(href, 'https://example.test').searchParams.get('travel'),
    );
    assert.equal(context.activityId, undefined);
    assert.equal(context.planDate, undefined);
    assert.equal(context.requestedDate, '2026-10-09');
    assert.equal(context.route.arriveAt, '2026-10-10T00:02:00+02:00');
});

test('a typed appointment location keeps arrival context without creating coordinates', async () => {
    const { parseTravelDraft } =
        await import('../../resources/js/components/departures/travel-request.ts');
    const url =
        '/timetable?to_name=B%C3%BCrgeramt&arrive_by=2026-10-09T10%3A17&activity=manual%3A17&plan_date=2026-10-09&back_label=Appointment';
    assert.equal(parseTravelDestination(url), null);
    assert.deepEqual(parseTravelDraft(url), {
        name: 'Bürgeramt',
        arriveBy: '2026-10-09T10:17',
        activityId: 'manual:17',
        planDate: '2026-10-09',
        backLabel: 'Appointment',
    });
});
