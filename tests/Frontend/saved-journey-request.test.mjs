import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    reusableJourney,
    sameSavedJourney,
} from '../../resources/js/components/departures/saved-journey-request.ts';
import { savedJourneyHref } from '../../resources/js/components/departures/travel-request.ts';

test('saved journey requests keep places and mode but discard old times and activity', () => {
    const request = reusableJourney({
        name: 'Museum',
        lat: 50.94,
        lng: 6.96,
        destinationId: 'osm:way/123',
        fromName: 'Office',
        fromLat: 50.93,
        fromLng: 6.95,
        preferredMode: 'transit',
        departAt: '2020-01-01T08:00',
        arriveBy: '2020-01-01T09:00',
        activityId: 'old-plan',
        planDate: '2020-01-01',
    });
    const href = savedJourneyHref(request);
    assert.equal(request.destination.id, 'osm:way/123');
    assert.equal(request.origin.name, 'Office');
    const params = new URL(href, 'http://localhost').searchParams;
    assert.equal(params.get('mode'), 'transit');
    assert.equal(params.get('from_name'), 'Office');
    assert.equal(params.has('depart_at'), false);
    assert.equal(params.has('arrive_by'), false);
    assert.equal(params.has('activity'), false);
    assert.equal(params.has('plan_date'), false);
});

test('saved requests use canonical identity and distinguish origins or modes', () => {
    const request = reusableJourney({ name: 'Museum', lat: 50.94, lng: 6.96 });
    assert.equal(
        sameSavedJourney(request, {
            ...request,
            destination: { ...request.destination, name: 'Renamed museum' },
        }),
        true,
    );
    assert.equal(
        sameSavedJourney(request, { ...request, mode: 'walk' }),
        false,
    );
    assert.equal(
        sameSavedJourney(request, {
            ...request,
            origin: { name: 'Office', lat: 50.93, lng: 6.95 },
        }),
        false,
    );
    assert.equal(
        new URL(savedJourneyHref(request), 'http://localhost').searchParams.has(
            'from_lat',
        ),
        false,
    );
});
