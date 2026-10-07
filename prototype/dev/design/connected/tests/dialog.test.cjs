const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup(nav = null, page) {
    let fontsReady;
    const windowListeners = {};
    const listeners = {};
    const dialog = {
        open: true,
        classList: { contains: () => false },
        addEventListener: (type, handler) => (listeners[type] = handler),
        getBoundingClientRect: () => ({
            left: 100,
            right: 500,
            top: 200,
            bottom: 700,
        }),
        close() {
            this.open = false;
        },
    };
    const nodes = {
        '#shell': page ? { innerHTML: '', querySelector: () => null } : null,
        '.service-nav, .services': nav,
        '#shared-dialog': dialog,
        '#dialog-body': {},
        '#shared-toast button': {},
    };
    vm.runInNewContext(fs.readFileSync(require.resolve('../ui.js'), 'utf8'), {
        ExpaduStore: {
            read: () => ({ savedIdeas: [], draft: [] }),
            routes: Object.fromEntries(
                [
                    'today',
                    'events',
                    'bureaucracy',
                    'departures',
                    'services',
                    'composer',
                ].map((name) => [name, `/design/${name}/`]),
            ),
        },
        ExpaduIcon: () => '',
        window: {
            addEventListener: (type, handler) =>
                (windowListeners[type] = handler),
        },
        document: {
            fonts: {
                ready: {
                    then(fn) {
                        fontsReady = fn;
                    },
                },
            },
            body: { dataset: { page }, insertAdjacentHTML() {} },
            querySelector: (selector) => nodes[selector] || null,
            querySelectorAll: () => [],
            addEventListener() {},
        },
        requestAnimationFrame() {},
    });
    return {
        shell: nodes['#shell'],
        dialog,
        finishFonts: () => fontsReady(),
        finishLoad: () => windowListeners.load(),
        click: (event) => listeners.click({ target: dialog, ...event }),
    };
}

test('shared navigation keeps five destinations and the current page without a Places tab', () => {
    for (const page of [
        'today',
        'events',
        'bureaucracy',
        'departures',
        'services',
    ]) {
        const html = setup(null, page).shell.innerHTML;
        assert.deepEqual(
            [...html.matchAll(/data-page="([^"]+)"/g)].map((m) => m[1]),
            ['today', 'events', 'bureaucracy', 'departures', 'services'],
        );
        assert.match(
            html,
            new RegExp(
                `data-page="${page}" href="/design/${page}/" aria-current="page"`,
            ),
        );
        assert.doesNotMatch(html, /Places|\/places\//);
    }
});

for (const [side, clientX, clientY] of [
    ['left', 50, 400],
    ['right', 550, 400],
    ['above', 300, 150],
    ['below', 300, 750],
]) {
    test(`a backdrop tap ${side} closes the dialog even with a negative offset`, () => {
        const { dialog, click } = setup();
        click({ clientX, clientY, offsetX: -1 });
        assert.equal(dialog.open, false);
    });
}

test('tapping dialog padding or its contents leaves it open', () => {
    const { dialog, click } = setup();
    click({ clientX: 110, clientY: 210, offsetX: 10 });
    assert.equal(dialog.open, true);
    click({ target: {}, clientX: 300, clientY: 400, offsetX: 0 });
    assert.equal(dialog.open, true);
});

test('the active page stays visible when fonts widen mobile navigation after first layout', () => {
    const nav = {
        scrollLeft: 0,
        scrollWidth: 340,
        clientWidth: 273,
        getBoundingClientRect: () => ({ left: 16, right: 289 }),
        querySelector: () => ({
            getBoundingClientRect: () => ({
                left: 288 - nav.scrollLeft,
                right: 338 - nav.scrollLeft,
            }),
        }),
    };
    const h = setup(nav);
    h.finishFonts();
    assert.equal(nav.scrollLeft, 49);
    h.finishLoad();
    assert.equal(nav.scrollLeft, 49);
});
