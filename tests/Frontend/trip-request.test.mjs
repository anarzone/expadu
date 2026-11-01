import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import { persistTrip } from '../../resources/js/components/departures/trip-request.ts';

const originalFetch = globalThis.fetch;
afterEach(() => {
    globalThis.fetch = originalFetch;
});

test('failed start/end responses do not refresh state or report success', async () => {
    for (const url of ['/api/trip/start', '/api/trip/end']) {
        let refreshed = false;
        globalThis.fetch = async () => new Response('{}', { status: 422 });
        await assert.rejects(
            persistTrip(url, {}, { 'X-CSRF-TOKEN': 'token' }, async () => {
                refreshed = true;

                return true;
            }),
            /couldn’t be saved/i,
        );
        assert.equal(refreshed, false);
    }
});

test('successful persistence refreshes shared trip state before returning', async () => {
    let request;
    let refreshed = false;
    globalThis.fetch = async (url, options) => {
        request = { url, options };

        return new Response('{}');
    };
    await persistTrip(
        '/api/trip/start',
        { destination: { name: 'Museum', lat: 50.9, lng: 6.95 } },
        { 'X-XSRF-TOKEN': 'csrf-token' },
        async () => {
            refreshed = true;

            return true;
        },
    );
    assert.equal(refreshed, true);
    assert.equal(request.url, '/api/trip/start');
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.headers['X-XSRF-TOKEN'], 'csrf-token');
    assert.deepEqual(JSON.parse(request.options.body).destination, {
        name: 'Museum',
        lat: 50.9,
        lng: 6.95,
    });
});

test('an unavailable refresh does not claim a confirmed state', async () => {
    globalThis.fetch = async () => new Response('{}');
    await assert.rejects(
        persistTrip(
            '/api/trip/end',
            undefined,
            { 'X-CSRF-TOKEN': 'token' },
            async () => false,
        ),
        /status couldn’t refresh/i,
    );
});
