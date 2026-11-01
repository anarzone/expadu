const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const scope = { module: { exports: {} }, setTimeout, clearTimeout };
vm.runInNewContext(
    fs.readFileSync(require.resolve('../../connected/loading.js'), 'utf8'),
    scope,
);
const L = scope.module.exports;
function harness() {
    let seq = 0;
    const pending = new Map(),
        events = [];
    const gate = L.createGate({
        show: () => events.push('show'),
        hide: () => events.push('hide'),
        setTimer: (fn) => {
            pending.set(++seq, fn);
            return seq;
        },
        clearTimer: (id) => pending.delete(id),
    });
    return {
        gate,
        events,
        flush() {
            for (const [id, fn] of pending) {
                pending.delete(id);
                fn();
            }
        },
    };
}
test('fast completion never flashes an indicator', () => {
    const h = harness();
    const id = h.gate.start();
    h.gate.finish(id);
    h.flush();
    assert.deepEqual(h.events, []);
});
test('visible loading clears exactly once after cancellation or completion', () => {
    const h = harness();
    const id = h.gate.start();
    h.flush();
    h.gate.finish(id);
    h.gate.finish(id);
    assert.deepEqual(h.events, ['show', 'hide']);
});
test('an older completion cannot dismiss a newer request', () => {
    const h = harness();
    const old = h.gate.start();
    h.flush();
    const next = h.gate.start();
    h.flush();
    h.gate.finish(old);
    assert.deepEqual(h.events, ['show', 'hide', 'show']);
    h.gate.finish(next);
    assert.equal(h.events.at(-1), 'hide');
});
test('status labels are escaped and decorative skeletons are hidden', () => {
    assert.ok(L.status('<img onerror="x">').includes('&lt;img'));
    assert.ok(L.status('Preparing').includes('role="status"'));
    assert.ok(L.skeleton('cards').includes('aria-hidden="true"'));
});
