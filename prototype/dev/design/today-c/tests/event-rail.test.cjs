const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup({ reduced = false, overflow = true, count = 2 } = {}) {
    const handlers = {},
        snaps = [],
        captures = new Set();
    const context = {
        window: { matchMedia: () => ({ matches: reduced }) },
        ExpaduUI: { esc: String, icon: () => '' },
    };
    for (const name of ['posters.js', 'festival.js'])
        vm.runInNewContext(
            fs.readFileSync(
                require.resolve('../../events-next/' + name),
                'utf8',
            ),
            context,
        );
    // Layout measurements are browser inputs; the gesture and event handling are real.
    const rail = {
        dataset: {},
        scrollLeft: 0,
        clientWidth: 300,
        scrollWidth: overflow ? count * 252 - 12 : 300,
        getBoundingClientRect: () => ({ left: 20 }),
        addEventListener: (type, handler) => {
            handlers[type] = handler;
        },
        setPointerCapture: (id) => captures.add(id),
        hasPointerCapture: (id) => captures.has(id),
        releasePointerCapture: (id) => captures.delete(id),
        scrollTo: (options) => {
            snaps.push(options);
            rail.scrollLeft = options.left;
        },
    };
    const cards = Array.from({ length: count }, (_, index) => ({
        left: index * 252,
        getBoundingClientRect() {
            return { left: 20 + this.left - rail.scrollLeft };
        },
    }));
    let currentCards = cards;
    rail.querySelectorAll = () => currentCards;
    function showCards(indices) {
        currentCards = indices.map((index) => cards[index]);
        currentCards.forEach((card, index) => (card.left = index * 252));
        rail.scrollWidth = currentCards.length * 252 - 12;
        rail.scrollLeft = 0;
    }
    const cardTarget = {
        closest: (selector) =>
            selector === '.festival-open' ? cardTarget : null,
    };
    context.window.ExpaduEventFestival.bindRail(rail);
    const fire = (type, x = 200, y = 100, extra = {}) => {
        const event = {
            target: cardTarget,
            button: 0,
            isPrimary: true,
            pointerType: 'mouse',
            pointerId: 1,
            clientX: x,
            clientY: y,
            detail: 1,
            prevented: false,
            stopped: false,
            preventDefault() {
                this.prevented = true;
            },
            stopImmediatePropagation() {
                this.stopped = true;
            },
            ...extra,
        };
        handlers[type]?.(event);
        return event;
    };
    return { rail, snaps, captures, fire, showCards };
}

test('mouse drags move the rail and snap forward or backward to a card', () => {
    const h = setup();
    h.fire('pointerdown');
    h.fire('pointermove', 100);
    assert.equal(h.rail.scrollLeft, 100);
    assert.equal(h.captures.has(1), true);
    h.fire('pointerup', 100);
    assert.equal(h.rail.scrollLeft, 192);
    assert.equal(h.snaps.at(-1).behavior, 'smooth');
    assert.equal(h.captures.size, 0);
    h.fire('pointerdown', 100);
    h.fire('pointermove', 220);
    h.fire('pointerup', 220);
    assert.equal(h.rail.scrollLeft, 0);
});

test('drag release cannot open an event, while the next regular click still can', () => {
    const h = setup();
    h.fire('pointerdown');
    h.fire('pointermove', 100);
    h.fire('pointerup', 100);
    assert.equal(h.fire('click', 100).stopped, true);
    h.fire('pointerdown', 150);
    h.fire('pointerup', 150);
    assert.equal(h.fire('click', 150).stopped, false);
});

test('small movement keeps normal clicks, but a short drag returns to its starting card', () => {
    const h = setup();
    h.fire('pointerdown');
    h.fire('pointermove', 197);
    h.fire('pointerup', 197);
    assert.equal(h.fire('click', 197).prevented, false);
    h.fire('pointerdown');
    h.fire('pointermove', 180);
    h.fire('pointerup', 180);
    assert.equal(h.rail.scrollLeft, 0);
    assert.equal(h.fire('click', 180).stopped, true);
});

test('touch scrolling, vertical gestures and separate icon actions are not captured', () => {
    for (const start of [
        { pointerType: 'touch' },
        { target: { closest: () => null } },
        { button: 2 },
        { isPrimary: false },
    ]) {
        const h = setup();
        h.fire('pointerdown', 200, 100, start);
        assert.equal(h.fire('pointermove', 100).prevented, false);
        h.fire('pointerup', 100);
        assert.equal(h.rail.scrollLeft, 0);
        assert.equal(h.captures.size, 0);
        assert.equal(h.fire('click').stopped, false);
    }
    const h = setup();
    h.fire('pointerdown');
    assert.equal(h.fire('pointermove', 196, 150).prevented, false);
    h.fire('pointermove', 100, 180);
    assert.equal(h.rail.scrollLeft, 0);
});

test('cancelled drags release capture and restore the original card', () => {
    for (const type of ['pointercancel', 'lostpointercapture']) {
        const h = setup();
        h.fire('pointerdown');
        h.fire('pointermove', 100);
        h.fire(type, 100);
        assert.equal(h.rail.scrollLeft, 0);
        assert.equal(h.captures.size, 0);
        assert.equal(h.rail.dataset.dragging, undefined);
    }
});

test('arrow keys snap the focused rail, respect its boundaries and reduced motion', () => {
    const h = setup({ reduced: true });
    const key = (key) => h.fire('keydown', 0, 0, { target: h.rail, key });
    key('ArrowRight');
    assert.equal(h.rail.scrollLeft, 192);
    assert.equal(h.snaps.at(-1).behavior, 'instant');
    key('ArrowRight');
    assert.equal(h.rail.scrollLeft, 192);
    key('Home');
    assert.equal(h.rail.scrollLeft, 0);
    key('ArrowLeft');
    assert.equal(h.rail.scrollLeft, 0);
    h.fire('keydown', 0, 0, { key: 'ArrowRight' });
    assert.equal(h.rail.scrollLeft, 0);
    key('End');
    assert.equal(h.rail.scrollLeft, 192);
});

test('desktop cards without overflow retain ordinary click behavior', () => {
    const h = setup({ overflow: false });
    h.fire('pointerdown');
    h.fire('pointermove', 100);
    h.fire('pointerup', 100);
    assert.equal(h.rail.scrollLeft, 0);
    assert.equal(h.captures.size, 0);
    assert.equal(h.fire('click').stopped, false);
});

test('keyboard navigation follows the remaining cards when an event is promoted and restored', () => {
    const h = setup({ count: 3 });
    h.showCards([1, 2]);
    h.fire('keydown', 0, 0, { target: h.rail, key: 'ArrowRight' });
    assert.equal(h.rail.scrollLeft, 192);
    h.showCards([0, 1, 2]);
    h.fire('keydown', 0, 0, { target: h.rail, key: 'End' });
    assert.equal(h.rail.scrollLeft, 444);
});

test('a drag after event promotion reaches the next remaining card without opening it', () => {
    const h = setup({ count: 3 });
    h.showCards([1, 2]);
    h.fire('pointerdown');
    h.fire('pointermove', 100);
    h.fire('pointerup', 100);
    assert.equal(h.rail.scrollLeft, 192);
    assert.equal(h.fire('click', 100).stopped, true);
});
