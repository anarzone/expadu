const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup() {
    const listeners = {},
        changes = [],
        animations = [];
    const root = {
        style: {},
        addEventListener: (type, fn) => (listeners[type] = fn),
        setPointerCapture() {},
        releasePointerCapture() {},
        hasPointerCapture: () => true,
    };
    const context = {
        window: {
            ExpaduMotion: {
                cancel() {},
                run: (...args) => animations.push(args),
            },
        },
    };
    vm.runInNewContext(
        fs.readFileSync(require.resolve('../week-swipe.js'), 'utf8'),
        context,
    );
    context.window.ExpaduEventWeek.bind(root, (days) => changes.push(days));
    const fire = (type, x, y = 100, more = {}) => {
        const event = {
            pointerId: 1,
            isPrimary: true,
            button: 0,
            clientX: x,
            clientY: y,
            preventDefault() {},
            ...more,
        };
        listeners[type](event);
    };
    return { root, changes, animations, fire };
}

test('swiping left advances one week and right goes back one week', () => {
    const h = setup();
    h.fire('pointerdown', 200);
    h.fire('pointermove', 110);
    h.fire('pointerup', 110);
    h.fire('pointerdown', 110);
    h.fire('pointermove', 200);
    h.fire('pointerup', 200);
    assert.deepEqual(h.changes, [7, -7]);
});

test('vertical scrolling and short drags do not change the selected week', () => {
    const h = setup();
    h.fire('pointerdown', 200);
    h.fire('pointermove', 190, 180);
    h.fire('pointerup', 110, 200);
    h.fire('pointerdown', 200);
    h.fire('pointermove', 180);
    h.fire('pointerup', 180);
    assert.deepEqual(h.changes, []);
    assert.equal(h.root.style.translate, '');
    assert.equal(h.animations.length, 1);
});

test('cancelled swipes settle back without changing the day', () => {
    const h = setup();
    h.fire('pointerdown', 200);
    h.fire('pointermove', 110);
    h.fire('pointercancel', 110);
    assert.deepEqual(h.changes, []);
    assert.equal(h.root.style.translate, '');
});

test('the click after a swipe cannot accidentally choose a date', () => {
    const h = setup();
    let blocked = 0;
    h.fire('pointerdown', 200);
    h.fire('pointermove', 110);
    h.fire('pointerup', 110);
    const click = { detail: 1, stopImmediatePropagation: () => blocked++ };
    h.fire('click', 110, 100, click);
    assert.equal(blocked, 1);
    h.fire('pointerdown', 150);
    h.fire('pointerup', 150);
    h.fire('click', 150, 100, click);
    assert.equal(blocked, 1);
});
