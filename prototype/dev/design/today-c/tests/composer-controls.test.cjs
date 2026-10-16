const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup(reducedMotion = true, store = {}) {
    const animations = [];
    const node = () => ({
        handlers: {},
        attributes: {},
        value: '',
        open: false,
        addEventListener(type, fn) {
            (this.handlers[type] ||= []).push(fn);
        },
        fire(type, event = {}) {
            for (const fn of this.handlers[type] || []) fn(event);
        },
        setAttribute(key, value) {
            this.attributes[key] = value;
        },
        querySelectorAll() {
            return [];
        },
        closest() {
            return null;
        },
        focus() {
            document.activeElement = this;
            tray.fire('focusin', { target: this });
        },
        blur() {
            document.activeElement = document;
            tray.fire('focusout', { target: this });
        },
    });
    const document = node(),
        tray = node(),
        field = node(),
        launcher = node();
    const dismiss = node(),
        actions = node(),
        approval = node(),
        tools = node();
    const context = node(),
        send = node(),
        label = node();
    const nodes = {
        '#composer-tray': tray,
        '#request': field,
        '.composer-launcher': launcher,
        '.composer-dismiss': dismiss,
        '[type="submit"]': send,
        '.today-composer-actions': actions,
        '.today-approval': approval,
        '.today-composer-tools': tools,
        '#included-ideas': context,
    };
    document.querySelector = tray.querySelector = (selector) => nodes[selector];
    tray.contains = (target) => Object.values(nodes).includes(target);
    tray.classList = {
        toggle(_, on) {
            tray.expanded = on;
        },
        add() {},
    };
    tray.animate = (frames, options) => {
        let finish, reject;
        const animation = {
            frames,
            options,
            finished: new Promise((resolve, fail) => {
                finish = resolve;
                reject = fail;
            }),
            finish() {
                finish();
            },
            cancel() {
                reject(new Error('Cancelled'));
            },
        };
        animations.push(animation);
        return animation;
    };
    approval.querySelector = () => label;
    document.activeElement = document;
    vm.runInNewContext(
        fs.readFileSync(require.resolve('../composer-controls.js'), 'utf8'),
        {
            document,
            window: node(),
            queueMicrotask,
            matchMedia: () => ({ matches: reducedMotion }),
            ExpaduStore: { studio: () => null, read: () => ({}), ...store },
        },
    );
    launcher.fire('click');
    return {
        document,
        tray,
        field,
        launcher,
        dismiss,
        actions,
        approval,
        tools,
        animations,
    };
}

test('moving focus off the input to a non-focusing touch control keeps Composer open', async () => {
    const { document, tray, field, actions, tools } = setup();
    // WebKit/touch buttons can blur the textarea without becoming activeElement.
    document.fire('pointerdown', { target: actions });
    field.blur();
    await new Promise(queueMicrotask);
    assert.equal(tray.expanded, true);
    assert.equal(tools.inert, false);
    actions.open = true;
    actions.fire('toggle');
    assert.equal(actions.open, true);
    assert.equal(tray.expanded, true);
});
test('outside tap and explicit close still dismiss without clearing the message', () => {
    const { document, tray, field, launcher, dismiss } = setup();
    field.value = 'Plan a walk';
    document.fire('pointerdown', { target: document });
    assert.equal(tray.expanded, false);
    assert.equal(field.value, 'Plan a walk');
    launcher.fire('click');
    dismiss.fire('click');
    assert.equal(tray.expanded, false);
    assert.equal(field.value, 'Plan a walk');
});
test('Escape closes an open menu first, then Composer', () => {
    const { tray, approval } = setup();
    approval.open = true;
    const event = {
        key: 'Escape',
        target: { closest: () => null },
        preventDefault() {},
    };
    tray.fire('keydown', event);
    assert.equal(approval.open, false);
    assert.equal(tray.expanded, true);
    tray.fire('keydown', event);
    assert.equal(tray.expanded, false);
});

test('closing animates before hiding and restores launcher focus afterwards', async () => {
    const { tray, field, dismiss, launcher, document, animations } =
        setup(false);
    field.value = 'A walk tomorrow';
    assert.equal(animations.length, 1);
    animations[0].finish();
    await Promise.resolve();
    dismiss.fire('click');
    assert.equal(tray.expanded, true);
    assert.equal(field.inert, true);
    assert.equal(animations.length, 2);
    animations[1].finish();
    await Promise.resolve();
    assert.equal(tray.expanded, false);
    assert.equal(document.activeElement, launcher);
    assert.equal(field.value, 'A walk tomorrow');
});
test('a stale close completion cannot collapse a reopened Composer', async () => {
    const { tray, dismiss, launcher, animations } = setup(false);
    dismiss.fire('click');
    const closing = animations.at(-1);
    launcher.fire('click');
    closing.finish();
    await Promise.resolve();
    assert.equal(tray.expanded, true);
});
test('reduced motion opens and closes immediately without animation', () => {
    const { tray, dismiss, animations } = setup(true);
    dismiss.fire('click');
    assert.equal(tray.expanded, false);
    assert.equal(animations.length, 0);
});

test('the launcher opens the box even with a conversation to go back to', () => {
    // Going back is a line inside the box (connected/composer-entry.js).
    const visits = [];
    const { tray } = setup(true, {
        isComposerMinimized: () => false,
        canReturnToComposer: () => true,
        go: (page, params) => visits.push([page, params.resume]),
    });
    assert.deepEqual(visits, []);
    assert.equal(tray.expanded, true);
});
