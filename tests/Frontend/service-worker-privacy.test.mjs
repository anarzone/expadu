import assert from 'node:assert/strict';
import fs from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

function worker() {
    const routes = [];
    const listeners = {};
    const deleted = [];
    const source = fs
        .readFileSync('resources/sw.ts', 'utf8')
        .replace(/^import[\s\S]*?from '[^']+';\s*/gm, '');
    const context = {
        registerRoute: (match, strategy) => routes.push({ match, strategy }),
        CacheFirst: class {},
        StaleWhileRevalidate: class {},
        NetworkFirst: class {},
        ExpirationPlugin: class {},
        URL,
        self: {
            location: { origin: 'https://app.expadu.test' },
            skipWaiting: () => Promise.resolve(),
            addEventListener: (type, fn) => {
                listeners[type] = fn;
            },
            clients: { claim: async () => {} },
        },
        caches: {
            keys: async () => ['api', 'inertia-pages', 'build-assets', 'fonts'],
            delete: async (name) => {
                deleted.push(name);

                return true;
            },
        },
    };
    vm.runInNewContext(
        ts
            .transpileModule(source, {
                compilerOptions: {
                    target: ts.ScriptTarget.ES2022,
                    module: ts.ModuleKind.None,
                },
            })
            .outputText.replace('export {};', ''),
        context,
    );

    return { routes, listeners, deleted };
}

test('private pages and APIs never match a runtime cache route, including misleading tile paths', () => {
    const { routes } = worker();

    for (const path of [
        '/today',
        '/api/journey',
        '/api/tiles/triage',
        '/api/places',
        '/composer/workspace',
    ]) {
        const request = new Request('https://app.expadu.test' + path, {
            headers: { 'X-Inertia': 'true' },
        });
        assert.equal(
            routes.some(({ match }) =>
                match({ url: new URL(request.url), request }),
            ),
            false,
            path,
        );
    }

    const request = new Request(
        'https://app.expadu.test/build/assets/app-123.js',
    );
    assert.equal(
        routes.some(({ match }) =>
            match({ url: new URL(request.url), request }),
        ),
        true,
    );
});

test('activation removes legacy private response caches before taking control', async () => {
    const { listeners, deleted } = worker();
    let completion;
    listeners.activate({
        waitUntil: (promise) => {
            completion = promise;
        },
    });
    await completion;
    assert.deepEqual(deleted.sort(), ['api', 'inertia-pages']);
});
