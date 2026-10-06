import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    readJourneyPreview,
    writeJourneyPreview,
} from '../../resources/js/components/departures/journey-preview.ts';

const destination = {
    name: 'Museum',
    lat: 50.9,
    lng: 6.9,
    fromLat: 50.8,
    fromLng: 6.8,
};
const journey = {
    mode: 'walk',
    depart_at: '2026-10-05T12:00:00Z',
    arrive_at: '2026-10-05T12:15:00Z',
    depart_time: '14:00',
    arrive_time: '14:15',
    duration_min: 15,
    transfers: 0,
    legs: [
        {
            mode: 'walk',
            from: { name: 'Office', lat: 50.8, lng: 6.8 },
            to: destination,
            depart_at: '2026-10-05T12:00:00Z',
            arrive_at: '2026-10-05T12:15:00Z',
            duration_min: 15,
        },
    ],
};
function memory() {
    const data = new Map();

    return {
        getItem: (k) => data.get(k) ?? null,
        setItem: (k, v) => data.set(k, v),
        removeItem: (k) => data.delete(k),
    };
}

test('preview restores exact request only within its account and freshness window', () => {
    const storage = memory();
    writeJourneyPreview(storage, 1, destination, journey, 1000);
    assert.deepEqual(
        readJourneyPreview(storage, 1, destination, 2000),
        journey,
    );
    assert.equal(readJourneyPreview(storage, 2, destination, 2000), null);
    assert.equal(
        readJourneyPreview(
            storage,
            1,
            { ...destination, arriveBy: '2026-10-06T12:00' },
            2000,
        ),
        null,
    );
    assert.equal(readJourneyPreview(storage, 1, destination, 31 * 60000), null);
});

test('closing preview removes restoration and unavailable storage is harmless', () => {
    const storage = memory();
    writeJourneyPreview(storage, 1, destination, journey, 1000);
    writeJourneyPreview(storage, 1, destination, null);
    assert.equal(readJourneyPreview(storage, 1, destination, 2000), null);
    assert.doesNotThrow(() =>
        writeJourneyPreview(
            {
                setItem() {
                    throw new Error();
                },
            },
            1,
            destination,
            journey,
        ),
    );
    assert.equal(
        readJourneyPreview(
            {
                getItem() {
                    throw new Error();
                },
            },
            1,
            destination,
        ),
        null,
    );
});
