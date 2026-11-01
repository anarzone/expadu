const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(
    require('node:path').join(__dirname, '../journey-session.js'),
    'utf8',
);
const key = 'expadu.journey-preview.v1';
test('connected destinations all load the shared resume control', () => {
    const path = require('node:path');
    for (const page of [
        'today-c',
        'events-next',
        'bureaucracy-next',
        'services-next',
        'departures-next',
        'composer-studio',
    ]) {
        const html = fs.readFileSync(
            path.join(__dirname, '../../', page, 'index.html'),
            'utf8',
        );
        assert.equal(
            (html.match(/src="\.\.\/connected\/journey-session\.js\?/g) || [])
                .length,
            1,
            `${page} needs one shared journey session`,
        );
        assert.ok(
            html.includes('../connected/journey-session.css?'),
            `${page} needs the shared resume style`,
        );
    }
});
function session(data = new Map(), blocked = false) {
    const context = vm.createContext({
        localStorage: {
            getItem: (key) => data.get(key) || null,
            setItem: (key, value) => {
                if (blocked) throw Error('Storage full');
                data.set(key, value);
            },
            removeItem: (key) => {
                if (blocked) throw Error('Storage full');
                data.delete(key);
            },
        },
        CustomEvent: class {
            constructor(type, detail) {
                this.type = type;
                this.detail = detail;
            }
        },
        dispatchEvent() {},
    });
    vm.runInContext(source, context);
    return context.JourneySession;
}
const record = {
    routeId: 'r18',
    step: 2,
    title: 'Museum Ludwig',
    instruction: 'Köln Appellhofplatz',
    mode: 'transit',
    condition: 'offline',
};
test('another page can resume the same route, step and connection state', () => {
    const data = new Map();
    assert.equal(session(data).write(record), true);
    const restored = session(data).read();
    assert.equal(restored.routeId, 'r18');
    assert.equal(restored.step, 2);
    assert.equal(restored.condition, 'offline');
});
test('a boarding-stop journey retains its origin context across pages', () => {
    const data = new Map();
    session(data).write({ ...record, boardingStop: 'neumarkt' });
    const restored = session(data).read();
    assert.equal(restored.boardingStop, 'neumarkt');
    assert.equal(restored.step, 2);
    assert.equal(restored.routeId, 'r18');
});
test('invalid boarding context cannot be silently restored as an ordinary journey', () => {
    for (const boardingStop of ['', '../neumarkt', 'n'.repeat(41), {}, 42]) {
        const data = new Map([
            [key, JSON.stringify({ ...record, version: 1, boardingStop })],
        ]);
        assert.equal(session(data).read(), null);
    }
});
test('a journey preview neither creates nor overwrites a proposal', () => {
    const data = new Map([['expadu-connected-v1', 'existing proposal']]);
    const s = session(data);
    s.write(record);
    s.write(null);
    assert.equal(data.get('expadu-connected-v1'), 'existing proposal');
    assert.equal(data.size, 1);
    assert.equal(s.read(), null);
});
test('unavailable storage keeps in-page progress and correctly reports persistence failure', () => {
    const s = session(new Map(), true);
    assert.equal(s.write(record), false);
    assert.equal(s.read().step, 2);
    s.write({ ...record, step: 3 });
    assert.equal(s.read().step, 3);
    s.write(null);
    assert.equal(s.read(), null);
});
test('corrupt, incompatible or unsafe persisted records do not create a resume action', () => {
    for (const value of [
        '{broken',
        JSON.stringify({ ...record, version: 2 }),
        JSON.stringify({ ...record, version: 1, step: -1 }),
        JSON.stringify({ ...record, version: 1, routeId: '../composer/' }),
    ]) {
        assert.equal(session(new Map([[key, value]])).read(), null);
    }
});
test('fresh pages see an ended preview and stored strings stay bounded', () => {
    const data = new Map();
    const s = session(data);
    s.write({
        ...record,
        title: 'x'.repeat(200),
        instruction: 'y'.repeat(400),
        condition: 'untrusted',
    });
    assert.equal(s.read().title.length, 120);
    assert.equal(s.read().instruction.length, 160);
    assert.equal(s.read().condition, 'normal');
    s.write(null);
    assert.equal(session(data).read(), null);
});
