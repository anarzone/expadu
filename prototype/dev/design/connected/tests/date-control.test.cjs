const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
function setup() {
    class Element {
        constructor() {
            this.dataset = {};
            this.attrs = {};
            this.listeners = {};
            this.children = [];
            this.hidden = false;
        }
        before(element) {
            this.wrapper = element;
        }
        append(...elements) {
            this.children.push(...elements);
        }
        setAttribute(name, value) {
            this.attrs[name] = value;
        }
        addEventListener(name, handler) {
            this.listeners[name] = handler;
        }
        dispatchEvent(event) {
            this.listeners[event.type]?.(event);
            this.events ||= [];
            this.events.push(event);
        }
        focus() {
            this.focused = true;
        }
        contains(element) {
            return (
                this === element ||
                this.children.some((x) => x.contains?.(element))
            );
        }
        querySelector(selector) {
            if (selector === '.shared-calendar') return this.children[2];
            if (selector === '.shared-date-toggle') return this.children[1];
            return new Element();
        }
    }
    const input = new Element();
    input.value = '2026-09-19';
    input.type = 'date';
    const document = {
        body: {},
        querySelectorAll: (selector) =>
            selector === '.shared-date'
                ? [input.wrapper]
                : input.dataset.dateEnhanced
                  ? []
                  : [input],
        createElement: () => new Element(),
        listeners: {},
        addEventListener(name, handler) {
            this.listeners[name] = handler;
        },
    };
    const context = {
        document,
        window: {},
        Date,
        Event: class {
            constructor(type, options) {
                this.type = type;
                Object.assign(this, options);
            }
        },
        MutationObserver: class {
            observe() {}
        },
    };
    vm.runInNewContext(
        fs.readFileSync(path.join(__dirname, '../date-control.js'), 'utf8'),
        context,
    );
    const [original, toggle, panel] = input.wrapper.children;
    const choose = (day) =>
        panel.onclick({ target: { closest: () => ({ dataset: { day } }) } });
    return { input, original, toggle, panel, choose, context };
}
test('calendar choice updates the real form input, emits change and restores focus', () => {
    const { input, original, toggle, panel, choose } = setup();
    assert.equal(original, input);
    toggle.onclick();
    assert.equal(panel.hidden, false);
    choose('2026-09-21');
    assert.equal(input.value, '2026-09-21');
    assert.match(toggle.textContent, /21 Sept/);
    assert.equal(panel.hidden, true);
    assert.equal(toggle.focused, true);
    assert.equal(input.events.at(-1).type, 'change');
    assert.equal(input.events.at(-1).bubbles, true);
});

test('month navigation stays open after replacing its button; outside clicks still close it', () => {
    const { input, toggle, panel, context } = setup();
    toggle.onclick();
    const removedButton = { dataset: { month: '1' } };
    const event = {
        target: { closest: () => removedButton },
        composedPath: () => [removedButton, panel, input.wrapper],
    };
    panel.onclick(event);
    context.document.listeners.click(event);
    assert.match(panel.innerHTML, /October 2026/);
    assert.equal(panel.hidden, false);
    assert.equal(input.value, '2026-09-19');
    context.document.listeners.click({ target: {}, composedPath: () => [] });
    assert.equal(panel.hidden, true);
});
test('moving focus off the trigger cannot dismiss the calendar before a pointer click', () => {
    const { input, toggle, panel, choose } = setup();
    toggle.onclick();
    input.wrapper.listeners.focusout?.({ relatedTarget: null });
    assert.equal(panel.hidden, false);
    choose('2026-09-22');
    assert.equal(input.value, '2026-09-22');
});
test('Escape closes only the date choice and does not alter the selected day', () => {
    const { input, toggle, panel } = setup();
    toggle.onclick();
    let prevented = false,
        stopped = false;
    input.wrapper.listeners.keydown({
        key: 'Escape',
        preventDefault() {
            prevented = true;
        },
        stopPropagation() {
            stopped = true;
        },
    });
    assert.equal(panel.hidden, true);
    assert.equal(input.value, '2026-09-19');
    assert.equal(prevented && stopped, true);
});

test('external date changes reopen the calendar in the selected month', () => {
    const { input, toggle, panel } = setup();
    input.value = '2026-10-06';
    input.dispatchEvent({ type: 'change' });
    toggle.onclick();
    assert.match(panel.innerHTML, /October 2026/);
});
