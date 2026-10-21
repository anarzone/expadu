import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    placeMapPoint,
    placeRoutingPoint,
    placePracticalDetails,
} from '../../resources/js/components/places/place-evidence.ts';

const place = {
    id: 17,
    name: 'Sports facility',
    lat: 50.9,
    lng: 6.9,
    price_text: 'free',
    open_now: true,
    opening_hours_text: '24/7',
};

test('missing or invalid map coordinates never become pins at zero or a substitute point', () => {
    for (const coordinates of [
        { lat: null, lng: null },
        { lat: 50.9, lng: null },
        { lat: NaN, lng: 6.9 },
        { lat: 91, lng: 6.9 },
        { lat: 50.9, lng: 181 },
    ]) {
        assert.equal(placeMapPoint({ ...place, ...coordinates }), null);
    }

    assert.deepEqual(placeMapPoint({ ...place, lat: 0, lng: 0 }), {
        lat: 0,
        lng: 0,
    });
    assert.deepEqual(
        placeMapPoint({ ...place, routing_lat: 50.91, routing_lng: 6.91 }),
        { lat: 50.9, lng: 6.9 },
    );
});

test('directions prefer supplied routing coordinates without substituting a city centre', () => {
    assert.deepEqual(
        placeRoutingPoint({ ...place, routing_lat: 50.91, routing_lng: 6.91 }),
        { lat: 50.91, lng: 6.91 },
    );
    assert.equal(
        placeRoutingPoint({
            ...place,
            lat: null,
            lng: null,
            routing_lat: null,
            routing_lng: null,
        }),
        null,
    );
    assert.deepEqual(
        placeRoutingPoint({ ...place, routing_lat: 50.91, routing_lng: null }),
        { lat: 50.9, lng: 6.9 },
    );
});

test('legacy or assumed hours never create an open-now claim', () => {
    const unknown = placePracticalDetails(place);
    assert.equal(unknown.openNow, null);
    assert.match(unknown.fee, /not verified/i);
    assert.match(unknown.access, /not verified/i);
    const assumed = placePracticalDetails({
        ...place,
        hours_assumed: true,
        place_facts: { hours: { status: 'known', raw: '24/7' } },
    });
    assert.equal(assumed.openNow, null);
});

test('known source facts remain useful while conditional and conflicting facts stay uncertain', () => {
    const facts = {
        hours: { status: 'known', raw: 'Mo-Fr 09:00-17:00' },
        fee: { status: 'known', value: 'free' },
        access: { status: 'known', value: 'public' },
    };
    const known = placePracticalDetails({ ...place, place_facts: facts });
    assert.equal(known.openNow, true);
    assert.equal(known.hours, 'Mo-Fr 09:00-17:00');
    assert.equal(known.fee, 'No entry fee reported');
    assert.equal(known.access, 'Public access reported');
    const uncertain = placePracticalDetails({
        ...place,
        place_facts: {
            ...facts,
            fee: { status: 'conditional', value: 'free' },
            access: { status: 'conflicting', value: 'public' },
        },
    });
    assert.match(uncertain.fee, /conditions/i);
    assert.match(uncertain.access, /conflicting/i);
});
