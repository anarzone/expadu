const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function setup(readyState = 'complete', page = 'events') {
    const listeners = {};
    const opened = [];
    const notices = [];
    const ctx = {
        ExpaduModel: { places: [], events: [] },
        ExpaduStore: {
            read: () => ({ brief: {}, savedIdeas: [] }),
            studio: () => ({}),
        },
        ExpaduHandoffs: {},
        window: {
            addEventListener() {},
            ExpaduUI: {
                esc: String,
                icon: () => '',
                open: (...args) => opened.push(args),
                toast: (message) => notices.push(message),
            },
        },
        document: {
            body: { dataset: { page } },
            readyState,
            addEventListener: (name, fn) => {
                listeners[name] = fn;
            },
            querySelectorAll: () => [],
        },
    };
    Object.assign(ctx, ctx.window);
    ctx.window = ctx;
    vm.runInNewContext(
        fs.readFileSync(require.resolve('../planning-ui.js'), 'utf8'),
        ctx,
    );
    return { ctx, opened, notices, listeners, api: ctx.window.ExpaduPlanning };
}
const event = {
    id: 'museum',
    type: 'event',
    title: 'Museum',
    date: '2026-09-08',
};

test('an early Events plan click waits for the event sheet instead of opening the generic form', () => {
    const h = setup('loading');
    h.api.open(event);
    assert.equal(h.opened.length, 0);
    const calls = [];
    h.ctx.window.ExpaduEventSheet = { open: (...args) => calls.push(args) };
    h.ctx.document.readyState = 'complete';
    h.listeners.DOMContentLoaded();
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], event);
    assert.equal(calls[0][1], 'plan');
});

test('Events offers recovery instead of an unfinished form when its sheet failed to load', () => {
    const h = setup();
    h.api.open(event);
    assert.equal(h.opened.length, 0);
    assert.match(h.notices[0], /Refresh/);
});

test('the event card opens planning within the event details sheet', () => {
    const h = setup();
    const calls = [];
    h.ctx.window.ExpaduEventSheet = { open: (...args) => calls.push(args) };
    h.api.open(event);
    assert.equal(calls.length, 1);
    assert.equal(calls[0][1], 'plan');
    assert.equal(h.opened.length, 0);
});

test('Today also waits for the shared event sheet instead of falling back to legacy markup', () => {
    const h = setup('loading', 'today');
    h.api.open(event);
    const calls = [];
    h.ctx.ExpaduEventSheet = { open: (...args) => calls.push(args) };
    h.ctx.document.readyState = 'complete';
    h.listeners.DOMContentLoaded();
    assert.equal(h.opened.length, 0);
    assert.equal(calls[0][1], 'plan');
});
test('appointments use the dedicated appointment frame', () => {
    const h = setup();
    const calls = [];
    h.ctx.ExpaduAppointmentSheet = { open: (item) => calls.push(item) };
    const appointment = { id: 'dentist', type: 'appointment', fixed: true };
    h.api.open(appointment);
    assert.equal(calls[0], appointment);
    assert.equal(h.opened.length, 0);
});

test('Today place ideas retain contextual planning without a standalone Places page', () => {
    const h = setup('complete', 'today');
    const calls = [];
    const item = {
        id: 'pitch-ivens',
        type: 'place',
        title: 'Bolzplatz · Ivenshofweg',
    };
    h.ctx.ExpaduPlaceSheet = { open: (...args) => calls.push(args) };
    h.api.open(item);
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], item);
    assert.equal(calls[0][1], 'plan');
    assert.equal(h.opened.length, 0);
});
