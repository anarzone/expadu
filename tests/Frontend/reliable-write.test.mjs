import assert from 'node:assert/strict';
import { test } from 'node:test';
import { writeJson } from '../../resources/js/lib/reliable-write.ts';

// No XSRF-TOKEN cookie, so csrfHeaders falls back to the page's meta token.
const token = {
    cookie: '',
    querySelector: () => ({ getAttribute: () => 'csrf-token' }),
};

test('writes accept only a successful JSON acknowledgement', async () => {
    globalThis.document = token;
    const originalFetch = globalThis.fetch;

    try {
        for (const response of [
            new Response('{}', { status: 419 }),
            new Response('{}', { status: 500 }),
            new Response('<html>Login</html>', {
                status: 200,
                headers: { 'Content-Type': 'text/html' },
            }),
        ]) {
            globalThis.fetch = async () => response;
            await assert.rejects(
                writeJson('/notification-preferences', 'PUT', {
                    preferences: {},
                }),
            );
        }

        globalThis.fetch = async (_url, options) => {
            assert.equal(options.headers['X-CSRF-TOKEN'], 'csrf-token');
            assert.equal(options.credentials, 'same-origin');
            assert.equal(options.redirect, 'error');

            return Response.json({ ok: true });
        };
        assert.deepEqual(await writeJson('/alerts/1/read', 'POST'), {
            ok: true,
        });
    } finally {
        globalThis.fetch = originalFetch;
        delete globalThis.document;
    }
});
