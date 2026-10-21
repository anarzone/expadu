import assert from 'node:assert/strict';
import { test } from 'node:test';
import { api, ApiError } from '../../resources/js/components/composer/types.ts';

function browser(t, handler) {
    const originalFetch = globalThis.fetch;
    const originalDocument = globalThis.document;
    globalThis.document = {
        querySelector: () => ({ getAttribute: () => 'csrf-token' }),
    };
    globalThis.fetch = handler;
    t.after(() => {
        globalThis.fetch = originalFetch;

        if (originalDocument === undefined) {
            delete globalThis.document;
        } else {
            globalThis.document = originalDocument;
        }
    });
}
function pending(_url, options) {
    return new Promise((_resolve, reject) => {
        if (options.signal.aborted) {
            return reject(options.signal.reason);
        }

        options.signal.addEventListener(
            'abort',
            () => reject(options.signal.reason),
            { once: true },
        );
    });
}

test('reads and writes require JSON acknowledgement with credentials and CSRF', async (t) => {
    browser(t, async (_url, options) => {
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.redirect, 'error');
        assert.equal(options.headers['X-CSRF-TOKEN'], 'csrf-token');
        assert.equal(options.method, 'POST');

        return Response.json({ revision: 2 });
    });
    assert.deepEqual(await api('/composer/workspace', { action: 'apply' }), {
        revision: 2,
    });
});

test('a stalled read is bounded and offers recovery', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    browser(t, pending);
    const result = api('/composer/workspace');
    const rejected = assert.rejects(result, /taking longer than expected/i);
    t.mock.timers.tick(30_001);
    await rejected;
});

test('a stalled write does not claim the plan was unchanged', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    browser(t, pending);
    const result = api('/composer/workspace', { action: 'apply' });
    const rejected = assert.rejects(
        result,
        /couldn.t confirm whether.*saved.*Refresh/i,
    );
    t.mock.timers.tick(30_001);
    await rejected;
});

test('caller cancellation retains its reason and stops the request', async (t) => {
    browser(t, pending);
    const controller = new AbortController();
    const result = api('/composer/workspace', undefined, controller.signal);
    const reason = new DOMException('No longer on this page', 'AbortError');
    controller.abort(reason);
    await assert.rejects(result, (error) => error === reason);
});

test('conflict status survives and an HTML login response is never acknowledged', async (t) => {
    browser(t, async () =>
        Response.json({ message: 'A newer revision exists.' }, { status: 409 }),
    );
    await assert.rejects(
        api('/composer/workspace', {}),
        (error) => error instanceof ApiError && error.status === 409,
    );
    globalThis.fetch = async () =>
        new Response('<html>Login</html>', {
            headers: { 'Content-Type': 'text/html' },
        });
    await assert.rejects(
        api('/composer/workspace', {}),
        /couldn.t confirm whether.*saved/i,
    );
});
