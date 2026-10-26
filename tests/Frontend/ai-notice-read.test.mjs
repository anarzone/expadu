import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    forgetAiNotice,
    knownAiNotice,
    readAiNotice,
    subscribeAiNotice,
} from '../../resources/js/components/composer/ai-notice-read.ts';

const URL = '/privacy/processing/composer_assist';
const ON = {
    available: true,
    processor_name: 'Synthetic AI processor',
    processor_privacy_url: null,
    notice_version: 'notice-1',
    provider_version: 'provider-1',
};

/** fetch answered by `respond` (a function per call); returns how many reads went out. */
function browser(t, respond) {
    const originalFetch = globalThis.fetch;
    const calls = { count: 0 };
    globalThis.fetch = (url, options) => {
        calls.count++;
        assert.equal(url, URL);
        assert.equal(options.credentials, 'same-origin');

        return respond(calls.count);
    };
    forgetAiNotice();
    t.after(() => {
        globalThis.fetch = originalFetch;
        forgetAiNotice();
    });

    return calls;
}

const json = (body, ok = true) =>
    Promise.resolve({ ok, json: () => Promise.resolve(body) });

test('the notice is read once: one read in flight, then known for the page', async (t) => {
    const calls = browser(t, () => json(ON));

    const [one, two] = await Promise.all([
        readAiNotice(URL),
        readAiNotice(URL),
    ]);
    assert.equal(calls.count, 1);
    assert.deepEqual(one, {
        state: 'on',
        notice: {
            processor_name: 'Synthetic AI processor',
            processor_privacy_url: null,
            notice_version: 'notice-1',
            provider_version: 'provider-1',
        },
    });
    assert.deepEqual(two, one);

    assert.deepEqual(await readAiNotice(URL), one);
    assert.equal(calls.count, 1);
    assert.equal(knownAiNotice()?.notice_version, 'notice-1');
});

test('off is an answer: a send goes without the AI, and it is not read again', async (t) => {
    const calls = browser(t, () => json({ available: false }));

    assert.deepEqual(await readAiNotice(URL), { state: 'off' });
    assert.deepEqual(await readAiNotice(URL), { state: 'off' });
    assert.equal(calls.count, 1);
    assert.equal(knownAiNotice(), null);
});

test('an error, a broken notice or no network is unreadable, and the next send reads again', async (t) => {
    const replies = [
        () => json({}, false),
        () =>
            json({ available: true, processor_name: 'Synthetic AI processor' }),
        () => Promise.reject(new TypeError('network down')),
        () => json(ON),
    ];
    const calls = browser(t, (n) => replies[n - 1]());

    for (let i = 0; i < 3; i++) {
        assert.deepEqual(await readAiNotice(URL), { state: 'unreadable' });
    }
    assert.equal((await readAiNotice(URL)).state, 'on');
    assert.equal(calls.count, 4);
});

test('a notice that does not answer in time is unreadable; its late answer still counts', async (t) => {
    let answer;
    browser(t, () => new Promise((resolve) => (answer = resolve)));

    assert.deepEqual(await readAiNotice(URL, 20), { state: 'unreadable' });
    assert.equal(knownAiNotice(), null);

    answer({ ok: true, json: () => Promise.resolve(ON) });
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(knownAiNotice()?.provider_version, 'provider-1');
});

test('whoever shows the line hears when the notice is known', async (t) => {
    browser(t, () => json(ON));
    let heard = 0;
    const stop = subscribeAiNotice(() => heard++);
    t.after(stop);

    await readAiNotice(URL);
    assert.equal(heard, 1);
});
