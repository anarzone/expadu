const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup() {
    const context = {};
    vm.runInNewContext(
        fs.readFileSync(require.resolve('../day-swipe.js'), 'utf8'),
        context,
    );
    const handlers = {},
        calls = [];
    const root = {
        addEventListener: (name, fn) => {
            handlers[name] = fn;
        },
        setPointerCapture: () => calls.push('capture'),
        hasPointerCapture: () => true,
        releasePointerCapture: () => {},
    };
    const card = {
        isConnected: true,
        expanded: false,
        getAttribute: (name) =>
            name === 'aria-expanded' ? String(card.expanded) : null,
        getBoundingClientRect: () => ({ width: 240 }),
    };
    const target = {
        closest: (selector) => (selector === '[data-day-swipe]' ? card : null),
    };
    context.ExpaduDaySwipe.bind(root, {
        begin: () => {
            calls.push('begin');
            return card;
        },
        move: (_card, distance) => calls.push(['move', distance]),
        end: (_card, commit, distance) => calls.push(['end', commit, distance]),
        reveal: () => calls.push('reveal'),
    });
    let clock = 0;
    const send = (name, x, y = 0, extra = {}) =>
        handlers[name]({
            target,
            clientX: x,
            clientY: y,
            pointerId: 1,
            button: 0,
            isPrimary: true,
            timeStamp: (clock += 30),
            preventDefault: () => calls.push('prevent'),
            ...extra,
        });
    return { calls, card, send, handlers, root };
}
test('a left drag tracks the finger and opens confirmation after release', () => {
    const h = setup();
    h.send('pointerdown', 240);
    assert.deepEqual(h.calls, []);
    h.send('pointermove', 160);
    assert.ok(h.calls.includes('begin'));
    assert.ok(h.calls.some((call) => call[0] === 'move' && call[1] === 80));
    h.send('pointerup', 140);
    assert.deepEqual(h.calls.at(-1), ['end', true, 100]);
});
test('two nearby taps reveal the action, while a single tap never does', () => {
    for (const pointerType of ['touch', 'mouse', 'pen']) {
        const h = setup();
        h.send('pointerdown', 140, 20, { pointerType });
        h.send('pointerup', 140, 20, { pointerType });
        assert.ok(!h.calls.includes('reveal'));
        h.send('pointerdown', 144, 22, { pointerType });
        h.send('pointerup', 144, 22, { pointerType });
        assert.equal(h.calls.filter((call) => call === 'reveal').length, 1);
        assert.ok(!h.calls.some((call) => call[0] === 'end'));
    }
});
test('slow, distant, interrupted and long-held taps do not count as a double tap', () => {
    for (const scenario of ['slow', 'distant', 'cancelled', 'held']) {
        const h = setup();
        h.send('pointerdown', 140, 20, { timeStamp: 0 });
        h.send(
            scenario === 'cancelled' ? 'pointercancel' : 'pointerup',
            140,
            20,
            { timeStamp: scenario === 'held' ? 600 : 30 },
        );
        const timeStamp =
            scenario === 'slow' || scenario === 'held' ? 700 : 100;
        const x = scenario === 'distant' ? 220 : 140;
        h.send('pointerdown', x, 20, { timeStamp });
        h.send('pointerup', x, 20, { timeStamp: timeStamp + 30 });
        assert.ok(!h.calls.includes('reveal'), scenario);
    }
});
test('an open row supports a right swipe to dismiss its action', () => {
    const h = setup();
    h.card.expanded = true;
    h.send('pointerdown', 120);
    h.send('pointermove', 175);
    h.send('pointerup', 180);
    assert.deepEqual(h.calls.at(-1), ['end', true, 60]);
});
test('the second tap release cannot activate the newly revealed delete action', () => {
    const h = setup();
    h.send('pointerdown', 140);
    h.send('pointerup', 140);
    h.send('pointerdown', 140);
    h.send('pointerup', 140);
    let prevented = false;
    h.handlers.click({
        detail: 2,
        preventDefault() {
            prevented = true;
        },
        stopImmediatePropagation() {},
    });
    assert.equal(prevented, true);
});
test('a short drag, reversed drag and interrupted drag restore the activity', () => {
    for (const [name, endX] of [
        ['pointerup', 215],
        ['pointerup', 245],
        ['pointercancel', 120],
    ]) {
        const h = setup();
        h.send('pointerdown', 240);
        h.send('pointermove', 200);
        h.send(name, endX);
        assert.equal(h.calls.at(-1)[0], 'end');
        assert.equal(h.calls.at(-1)[1], false);
    }
});
test('vertical scrolling, right drags, secondary pointers and buttons do not open confirmation', () => {
    for (const [x, y, extra] of [
        [238, 40, {}],
        [280, 1, {}],
        [120, 0, { isPrimary: false }],
        [120, 0, { button: 2 }],
    ]) {
        const h = setup();
        h.send('pointerdown', 240, 0, extra);
        h.send('pointermove', x, y);
        h.send('pointerup', x, y);
        assert.ok(!h.calls.includes('begin'));
        assert.ok(
            !h.calls.includes('prevent'),
            'native scrolling stays available',
        );
    }
    const h = setup();
    h.send('pointerdown', 240, 0, { target: { closest: () => ({}) } });
    h.send('pointermove', 100);
    assert.ok(!h.calls.includes('begin'));
});
test('lost capture cancels and a rerendered activity cannot commit the old gesture', () => {
    const h = setup();
    h.send('pointerdown', 240);
    h.send('pointermove', 120);
    h.send('lostpointercapture', 120);
    assert.deepEqual(h.calls.at(-1), ['end', false, 120]);
    const changed = setup();
    changed.send('pointerdown', 240);
    changed.send('pointermove', 120);
    changed.card.isConnected = false;
    changed.send('pointerup', 120);
    assert.ok(!changed.calls.some((call) => call[0] === 'end' && call[1]));
});
test('the release click cannot activate the newly revealed Remove button', () => {
    const h = setup();
    h.send('pointerdown', 240);
    h.send('pointermove', 100);
    h.send('pointerup', 100);
    let prevented = false;
    h.handlers.click({
        detail: 1,
        target: { closest: () => ({}) },
        preventDefault: () => {
            prevented = true;
        },
        stopImmediatePropagation() {},
    });
    assert.equal(prevented, true);
});
