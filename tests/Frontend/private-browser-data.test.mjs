import assert from 'node:assert/strict';
import { test } from 'node:test';
import { clearPrivateBrowserData } from '../../resources/js/lib/private-browser-data.ts';

function storage(values) {
    const state = { ...values };
    Object.defineProperty(state, 'removeItem', {
        value: (key) => delete state[key],
    });

    return state;
}

test('successful logout removes account drafts, legacy saved items and private response caches', async () => {
    globalThis.sessionStorage = storage({
        'expadu-composer-message-7': 'private draft',
        'expadu-onboarding-draft:7': 'private answers',
        unrelated: 'keep',
    });
    globalThis.localStorage = storage({
        'expadu-events-saved-v1:7': '[42]',
        appearance: 'dark',
    });
    const deleted = [];
    globalThis.caches = { delete: async (name) => deleted.push(name) };
    globalThis.window = { caches: globalThis.caches };
    let unsubscribed = false;
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: {
            serviceWorker: {
                getRegistration: async () => ({
                    pushManager: {
                        getSubscription: async () => ({
                            unsubscribe: async () => {
                                unsubscribed = true;

                                return true;
                            },
                        }),
                    },
                }),
            },
        },
    });
    await clearPrivateBrowserData();
    assert.deepEqual(Object.keys(sessionStorage), ['unrelated']);
    assert.deepEqual(Object.keys(localStorage), ['appearance']);
    assert.deepEqual(deleted.sort(), ['api', 'inertia-pages']);
    assert.equal(unsubscribed, true);
});
