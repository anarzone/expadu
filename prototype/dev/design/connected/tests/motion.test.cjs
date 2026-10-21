const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup(reduced = false) {
    const window = {},
        animations = [],
        listeners = {};
    vm.runInNewContext(
        fs.readFileSync(require.resolve('../motion.js'), 'utf8'),
        {
            window,
            queueMicrotask,
            document: {
                addEventListener(type, listener) {
                    listeners[type] = listener;
                },
            },
            matchMedia: () => ({ matches: reduced }),
        },
    );
    const element = {
        animate(frames, options) {
            let finish;
            const animation = {
                frames,
                options,
                cancelled: false,
                finished: new Promise((resolve) => {
                    finish = resolve;
                }),
                cancel() {
                    this.cancelled = true;
                },
                finish() {
                    finish();
                },
            };
            animations.push(animation);
            return animation;
        },
    };
    return { motion: window.ExpaduMotion, element, animations, listeners };
}
function agendaHarness(reduced = false, bottom = 650) {
    const h = setup(reduced);
    const scrolls = [];
    const scroller = {
        scrollTop: 0,
        getBoundingClientRect: () => ({ top: 100, bottom: 500 }),
        scrollTo: (options) => scrolls.push(options),
    };
    const agenda = { getBoundingClientRect: () => ({ top: 400, bottom }) };
    const details = {
        ...h.element,
        open: false,
        style: {},
        getBoundingClientRect: () => ({ height: 200 }),
        closest: (selector) =>
            selector === '.place-sheet-scroll' ? scroller : agenda,
    };
    const summary = {
        parentElement: details,
        getBoundingClientRect: () => ({ height: 44 }),
    };
    const click = () =>
        h.listeners.click({
            preventDefault() {},
            target: { closest: () => summary },
        });
    return { ...h, details, scrolls, click };
}
test('opening an agenda reveals it above the footer after expansion', async () => {
    const h = agendaHarness();
    h.click();
    assert.equal(h.scrolls.length, 0);
    h.animations[0].finish();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(h.scrolls[0].top, 162);
    assert.equal(h.scrolls[0].behavior, 'smooth');
});
test('agenda reveal respects reduced motion and does not scroll on collapse', async () => {
    const h = agendaHarness(true);
    h.click();
    await Promise.resolve();
    assert.equal(h.scrolls[0].behavior, 'instant');
    assert.equal(h.animations.length, 0);
    h.click();
    assert.equal(h.scrolls.length, 1);
    assert.equal(h.details.open, false);
});
test('an agenda already inside the visible area does not move the page', async () => {
    const h = agendaHarness(true, 470);
    h.click();
    await Promise.resolve();
    assert.equal(h.scrolls.length, 0);
});
test('reopening cancels a pending close and prevents its stale completion', async () => {
    const { motion, element, animations } = setup();
    let closed = false;
    motion.run(element, [], {}, () => {
        closed = true;
    });
    const closing = animations[0];
    motion.reveal(element);
    assert.equal(closing.cancelled, true);
    closing.finish();
    await Promise.resolve();
    assert.equal(closed, false);
});
test('reduced motion completes immediately without starting animation', () => {
    const { motion, element, animations } = setup(true);
    let finished = false;
    motion.run(element, [], {}, () => {
        finished = true;
    });
    assert.equal(finished, true);
    assert.equal(animations.length, 0);
});
test('completed transitions release their fill and run completion once', async () => {
    const { motion, element, animations } = setup();
    let count = 0;
    motion.run(element, [], {}, () => count++);
    animations[0].finish();
    await Promise.resolve();
    assert.equal(count, 1);
    assert.equal(animations[0].cancelled, true);
});
test('switching direction replaces an in-flight panel transition', () => {
    const { motion, element, animations } = setup();
    motion.reveal(element, 1);
    motion.reveal(element, -1);
    assert.equal(animations[0].cancelled, true);
    assert.equal(animations[1].frames[0].translate, '-12px 0');
});
