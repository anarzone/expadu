const { test } = require('node:test'),
    assert = require('node:assert/strict'),
    fs = require('node:fs'),
    vm = require('node:vm'),
    path = require('node:path');
function page(storage, session = storage) {
    const events = [];
    const context = vm.createContext({
        localStorage: storage,
        sessionStorage: session,
        URL,
        location: { href: 'http://localhost/dev/design/places-next/' },
        document: { addEventListener() {} },
        window: {
            scrollY: 420,
            addEventListener() {},
            scrollTo(options) {
                this.scrollY = options.top;
            },
            dispatchEvent(e) {
                events.push(e);
            },
        },
        CustomEvent: class {
            constructor(type, args) {
                this.type = type;
                this.detail = args.detail;
            }
        },
    });
    for (const f of ['model.js', 'store.js'])
        vm.runInContext(
            fs.readFileSync(path.join(__dirname, '..', f), 'utf8'),
            context,
        );
    return {
        M: context.ExpaduModel,
        S: context.window.ExpaduStore,
        context,
        events,
    };
}
test('day edits persist together and preserve the rest of the workspace', () => {
    const data = new Map([
        [
            'expadu-composer-studio-v1',
            JSON.stringify({
                plans: [{ id: 'day', items: ['a', 'b'] }],
                saved: [],
                prompt: 'keep this',
            }),
        ],
    ]);
    const p = page({
        getItem: (key) => data.get(key) ?? null,
        setItem: (key, value) => data.set(key, value),
        removeItem: (key) => data.delete(key),
    });
    const result = p.S.updateDay((shared, workspace) => {
        workspace.plans[0].items.pop();
        shared.revision++;
        return 'removed';
    });
    assert.equal(result, 'removed');
    const saved = JSON.parse(data.get('expadu-composer-studio-v1'));
    assert.deepEqual(saved.plans[0].items, ['a']);
    assert.equal(saved.prompt, 'keep this');
    assert.equal(p.events.at(-1).type, 'expadu:change');
});
test('if the second day write fails, the first write is rolled back', () => {
    const original = JSON.stringify({
        plans: [{ id: 'day', items: ['a', 'b'] }],
        saved: [],
    });
    const data = new Map([['expadu-composer-studio-v1', original]]);
    const p = page({
        getItem: (key) => data.get(key) ?? null,
        setItem: (key, value) => {
            if (key === 'expadu-connected-design-v1') throw Error('Quota');
            data.set(key, value);
        },
        removeItem: (key) => data.delete(key),
    });
    assert.throws(
        () =>
            p.S.updateDay((_shared, workspace) =>
                workspace.plans[0].items.pop(),
            ),
        /Could not save/,
    );
    assert.equal(data.get('expadu-composer-studio-v1'), original);
    assert.equal(data.has('expadu-connected-design-v1'), false);
    assert.equal(p.events.length, 0);
});
test('a separate department restores the same draft and full request', () => {
    const data = new Map(),
        storage = {
            getItem: (k) => data.get(k) || null,
            setItem: (k, v) => data.set(k, v),
        },
        a = page(storage);
    a.S.update((s) => {
        s.prompt = 'quiet, no parks';
        a.M.add(s, a.M.places[0]);
    });
    const b = page(storage);
    assert.equal(b.S.read().prompt, 'quiet, no parks');
    assert.equal(b.S.read().draft[0].title, 'Blücherpark');
});
test('failed persistence keeps the newest draft in memory and blocks handoff', () => {
    const storage = {
            getItem: () => null,
            setItem: () => {
                throw new Error('QuotaExceededError');
            },
        },
        p = page(storage);
    p.S.update((s) => {
        s.prompt = 'my exact request';
        p.M.add(s, p.M.places[0]);
    });
    assert.equal(p.S.read().prompt, 'my exact request');
    assert.equal(p.S.read().draft.length, 1);
    assert.equal(p.S.go('composer'), false);
    assert.equal(
        p.context.location.href,
        'http://localhost/dev/design/places-next/',
    );
    assert.equal(p.events.at(-1).detail.persistent, false);
});
test('damaged stored JSON does not crash department navigation', () => {
    const p = page({ getItem: () => '{broken', setItem() {} });
    assert.equal(p.S.read().draft.length, 0);
});

test('first connection preserves existing Studio bookmarks and only synchronizes bookmarks', () => {
    const data = new Map([
        [
            'expadu-composer-studio-v1',
            JSON.stringify({
                version: 1,
                saved: ['walk'],
                plans: [{ id: 'day', date: '2026-09-19' }],
                conversation: { currentId: 'chat' },
            }),
        ],
    ]);
    const p = page({
        getItem: (k) => data.get(k) || null,
        setItem: (k, v) => data.set(k, v),
    });
    assert.equal(p.S.read().savedIdeas[0].id, 'walk');
    p.S.update((s) =>
        s.savedIdeas.push(p.M.places.find((x) => x.id === 'greenbelt')),
    );
    let studio = JSON.parse(data.get('expadu-composer-studio-v1'));
    assert.deepEqual(studio.saved, ['walk', 'greenbelt']);
    assert.equal(studio.plans[0].id, 'day');
    assert.equal(studio.conversation.currentId, 'chat');
    p.S.update(
        (s) => (s.savedIdeas = s.savedIdeas.filter((x) => x.id !== 'walk')),
    );
    studio = JSON.parse(data.get('expadu-composer-studio-v1'));
    assert.deepEqual(studio.saved, ['greenbelt']);
});
test('Composer handoff targets approved workspace and records the requested plan date', () => {
    const data = new Map();
    const p = page({
        getItem: (k) => data.get(k) || null,
        setItem: (k, v) => data.set(k, v),
    });
    p.S.go('composer', { date: '2026-09-19' });
    assert.equal(
        p.context.location.href,
        'http://localhost/dev/design/composer-studio/?date=2026-09-19',
    );
    assert.equal(p.S.read().composerHandoff.date, '2026-09-19');
    p.S.go('composer', { panel: 'saved' });
    assert.match(p.context.location.href, /#saved$/);
});

test('Today Composer actions preserve typed text and only carry supported actions', () => {
    const data = new Map();
    const p = page({
        getItem: (k) => data.get(k) || null,
        setItem: (k, v) => data.set(k, v),
    });
    p.S.update((s) => {
        s.prompt = 'My unfinished question';
    });
    for (const action of ['appointment', 'examples', 'new']) {
        p.S.go('composer', { action, panel: undefined });
        assert.equal(new URL(p.context.location.href).hash, '');
        assert.equal(p.S.read().composerHandoff.action, action);
        assert.equal(p.S.read().prompt, 'My unfinished question');
    }
    p.S.go('composer', { action: 'unknown' });
    assert.equal(p.S.read().composerHandoff.action, null);
});

test('minimize returns to the originating screen and resume preserves workspace URL and scroll', () => {
    const data = new Map();
    const storage = {
        getItem: (k) => data.get(k) || null,
        setItem: (k, v) => data.set(k, v),
    };
    const p = page(storage);
    p.context.location.href =
        'http://localhost/dev/design/places-next/?category=park#results';
    p.S.go('composer', { date: '2026-09-19' });
    const handoff = p.S.read().composerHandoff.id;
    p.context.window.scrollY = 900;
    p.S.minimizeComposer();
    assert.equal(
        p.context.location.href,
        'http://localhost/dev/design/places-next/?category=park#results',
    );
    assert.equal(p.S.isComposerMinimized(), true);
    p.S.restoreComposerPosition();
    assert.equal(p.context.window.scrollY, 420);
    p.S.go('composer', { resume: '1' });
    assert.equal(
        p.context.location.href,
        'http://localhost/dev/design/composer-studio/?date=2026-09-19',
    );
    assert.equal(p.S.read().composerHandoff.id, handoff);
    p.S.restoreComposerPosition();
    assert.equal(p.context.window.scrollY, 900);
    assert.equal(p.S.isComposerMinimized(), false);
});
test('Composer can return to the Places guide with its selection and search intact', () => {
    const data = new Map();
    const p = page({
        getItem: (k) => data.get(k) || null,
        setItem: (k, v) => data.set(k, v),
    });
    const origin =
        'http://localhost/dev/design/places-guide/?collection=green&place=sample-osm-way-150031301';
    p.context.location.href = origin;
    p.S.go('composer');
    p.S.minimizeComposer();
    assert.equal(p.context.location.href, origin);
});

test('Composer returns to the Today preview and retains its selected scenario', () => {
    const data = new Map();
    const p = page({
        getItem: (k) => data.get(k) || null,
        setItem: (k, v) => data.set(k, v),
    });
    const origin = 'http://localhost/dev/design/today-next/?view=delay';
    p.context.location.href = origin;
    p.S.go('composer');
    assert.equal(p.S.read().composerHandoff.submit, false);
    p.S.minimizeComposer();
    assert.equal(p.context.location.href, origin);
});

test('direct Composer entry safely minimizes to Today, including file previews', () => {
    for (const url of [
        'http://localhost/dev/design/composer-studio/',
        'file:///tmp/prototype/dev/design/composer-studio/index.html',
    ]) {
        const data = new Map();
        const storage = {
            getItem: (k) => data.get(k) || null,
            setItem: (k, v) => data.set(k, v),
        };
        const p = page(storage);
        p.context.location.href = url;
        p.S.minimizeComposer();
        assert.equal(
            p.context.location.href,
            url.startsWith('file:')
                ? 'file:///tmp/prototype/dev/design/today-next/brief/index.html'
                : 'http://localhost/dev/design/today-next/brief/',
        );
    }
});

test('department review can return to the same Composer position without replaying a request', () => {
    const data = new Map();
    const p = page({
        getItem: (k) => data.get(k) || null,
        setItem: (k, v) => data.set(k, v),
    });
    p.S.go('composer', { date: '2026-09-19' });
    const handoff = p.S.read().composerHandoff.id;
    p.context.window.scrollY = 650;
    p.S.rememberWorkspace();
    p.context.location.href =
        'http://localhost/dev/design/departures-next/?to=Museum';
    assert.equal(p.S.canReturnToComposer(), true);
    p.S.go('composer', { resume: '1' });
    assert.equal(
        p.context.location.href,
        'http://localhost/dev/design/composer-studio/?date=2026-09-19',
    );
    assert.equal(p.S.read().composerHandoff.id, handoff);
    p.S.restoreComposerPosition();
    assert.equal(p.context.window.scrollY, 650);
    assert.equal(p.S.canReturnToComposer(), false);
});

test('a department can create its first Composer proposal atomically without replacing an existing workspace', () => {
    const data = new Map();
    const p = page({
        getItem: (key) => data.get(key) ?? null,
        setItem: (key, value) => data.set(key, value),
        removeItem: (key) => data.delete(key),
    });
    let creates = 0;
    const options = {
        createWorkspace: () => {
            creates++;
            return { plans: [], proposal: null, prompt: 'preserved' };
        },
    };
    p.S.updateDay((shared, workspace) => {
        workspace.proposal = { date: '2026-10-01', items: [] };
    }, options);
    p.S.updateDay((shared, workspace) => {
        workspace.proposal.items.push({ title: 'Paperwork time' });
    }, options);
    assert.equal(creates, 1);
    assert.equal(p.S.studio().prompt, 'preserved');
    assert.equal(p.S.studio().proposal.items.length, 1);
});
test('a failed first proposal leaves no partial Composer workspace behind', () => {
    const data = new Map();
    const p = page({
        getItem: (key) => data.get(key) ?? null,
        setItem: (key, value) => {
            if (key === 'expadu-connected-design-v1') throw Error('Quota');
            data.set(key, value);
        },
        removeItem: (key) => data.delete(key),
    });
    assert.throws(
        () =>
            p.S.updateDay(() => {}, {
                createWorkspace: () => ({ plans: [], proposal: {} }),
            }),
        /Could not save/,
    );
    assert.equal(data.has('expadu-composer-studio-v1'), false);
    assert.equal(p.events.length, 0);
});

test('scroll restoration waits for asynchronous department content before consuming the return position', () => {
    const data = new Map();
    const p = page({
        getItem: (key) => data.get(key) || null,
        setItem: (key, value) => data.set(key, value),
    });
    p.S.go('composer');
    p.S.minimizeComposer();
    p.context.window.scrollY = 0;
    p.context.document.querySelector = () => ({ getAttribute: () => 'true' });
    p.S.restoreComposerPosition();
    assert.equal(
        p.context.window.scrollY,
        0,
        'Do not scroll an unrendered page',
    );
    p.context.document.querySelector = () => null;
    p.S.restoreComposerPosition();
    assert.equal(
        p.context.window.scrollY,
        420,
        'The retained position applies after rendering',
    );
});

test('nested Today preview resolves department routes against its declared base', () => {
    const data = new Map();
    const p = page({
        getItem: (k) => data.get(k) || null,
        setItem: (k, v) => data.set(k, v),
    });
    p.context.location.href =
        'http://localhost/dev/design/today-next/brief/?view=everyday';
    p.context.document.baseURI = 'http://localhost/dev/design/today-next/';
    p.S.go('departures', { to: 'Museum Ludwig' });
    assert.equal(
        new URL(p.context.location.href).pathname,
        '/dev/design/departures-next/',
    );
    p.S.go('composer', { date: '2026-09-30' });
    assert.equal(
        new URL(p.context.location.href).pathname,
        '/dev/design/composer-studio/',
    );
});
