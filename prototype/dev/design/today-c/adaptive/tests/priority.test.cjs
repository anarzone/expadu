const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
vm.runInThisContext(
    fs.readFileSync(path.join(__dirname, '../model.js'), 'utf8'),
);
const { select } = globalThis.TodayAdaptive;
const now = '2026-09-08T15:30:00+02:00';
const feature = {
    id: 'kunst-machen',
    important: true,
    date: '2026-09-08',
    startAt: '2026-09-08T16:00:00+02:00',
    endAt: '2026-09-08T17:30:00+02:00',
};
const delay = {
    id: 'sample-delay',
    routeId: 'usual-route',
    active: true,
    expiresAt: '2026-09-08T16:00:00+02:00',
};
const input = {
    now,
    day: '2026-09-08',
    routeId: 'usual-route',
    feature,
    delay,
};

test('a quiet day keeps the overview in front without manufacturing a lead', () => {
    assert.deepEqual(select({ now, day: '2026-09-08' }), {
        layout: 'everyday',
        featureId: null,
        delayId: null,
    });
});
test('a designated event on this day is promoted, with one identity for removing the duplicate', () => {
    assert.deepEqual(select({ ...input, delay: null }), {
        layout: 'event',
        featureId: 'kunst-machen',
        delayId: null,
    });
});
test('an active interruption on the person’s route takes priority over a feature', () => {
    assert.deepEqual(select(input), {
        layout: 'delay',
        featureId: null,
        delayId: 'sample-delay',
    });
});
test('unrelated route news cannot displace a relevant feature', () => {
    assert.equal(
        select({ ...input, routeId: 'another-route' }).layout,
        'event',
    );
});
test('resolved and expired delays no longer take the lead', () => {
    assert.equal(
        select({ ...input, delay: { ...delay, active: false } }).layout,
        'event',
    );
    assert.equal(
        select({ ...input, delay: { ...delay, expiresAt: now } }).layout,
        'event',
    );
});
test('ordinary, past and differently dated events stay in discovery', () => {
    for (const change of [
        { important: false },
        { endAt: now },
        { date: '2026-09-09' },
    ]) {
        assert.equal(
            select({
                ...input,
                delay: null,
                feature: { ...feature, ...change },
            }).layout,
            'everyday',
        );
    }
});
test('missing or invalid timing never creates urgency', () => {
    assert.equal(
        select({
            ...input,
            feature: null,
            delay: { ...delay, expiresAt: 'invalid' },
        }).layout,
        'everyday',
    );
    assert.equal(
        select({
            ...input,
            delay: null,
            feature: { ...feature, startAt: null, endAt: null },
        }).layout,
        'everyday',
    );
});
test('projection leaves the input signals and underlying plan unchanged', () => {
    const before = structuredClone(input);
    select(input);
    assert.deepEqual(input, before);
});
