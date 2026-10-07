const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const ctx = {};
vm.runInNewContext(
    fs.readFileSync(path.join(__dirname, '../event-model.js'), 'utf8'),
    ctx,
);
const F = ctx.ExpaduEvents;
const items = [
    {
        id: 'a',
        date: '2026-09-08',
        start: '18:00',
        title: 'Art',
        venue: 'Museum',
        area: 'North',
        category: 'Culture',
        price: null,
        occurrence: 'a',
    },
    {
        id: 'b',
        date: '2026-09-08',
        start: '10:00',
        title: 'Talk',
        venue: 'Library',
        area: 'South',
        category: 'Language',
        price: 'Free',
        occurrence: 'b',
    },
];
test('categories remain single select and free, area, search and saved combine independently', () => {
    const f = F.normalize(
        {
            category: 'Language',
            free: true,
            area: 'South',
            query: 'library',
            saved: true,
        },
        items,
    );
    assert.deepEqual(
        Array.from(F.select(items, f, [{ occurrence: 'b' }]), (x) => x.id),
        ['b'],
    );
    assert.equal(F.select(items, f, []).length, 0);
    assert.equal(
        F.select(items, { ...f, category: 'Culture' }, [{ occurrence: 'b' }])
            .length,
        0,
    );
});
test('sort is chronological in either direction without changing the catalogue', () => {
    assert.deepEqual(
        Array.from(F.select(items, F.normalize({}, items)), (x) => x.id),
        ['b', 'a'],
    );
    assert.deepEqual(
        Array.from(
            F.select(items, F.normalize({ sort: 'Evening first' }, items)),
            (x) => x.id,
        ),
        ['a', 'b'],
    );
    assert.equal(items[0].id, 'a');
});
test('dates filter occurrences rather than repeating a sample on every day', () => {
    assert.equal(
        F.select(items, F.normalize({ date: '2026-09-19' }, items)).length,
        0,
    );
    assert.equal(F.normalize({ date: '2026-02-31' }, items).date, '2026-09-08');
    assert.equal(F.normalize({ date: 'invalid' }, items).date, '2026-09-08');
});
test('calendar crosses months and years and keeps selected date in its week', () => {
    assert.deepEqual(Array.from(F.week('2026-09-01')), [
        '2026-08-31',
        '2026-09-01',
        '2026-09-02',
        '2026-09-03',
        '2026-09-04',
        '2026-09-05',
        '2026-09-06',
    ]);
    assert.equal(F.shift('2026-12-31', 7), '2027-01-07');
});

test('month grid includes leap days and only the weeks needed', () => {
    const dates = Array.from(F.month('2024-02-15'));
    assert.equal(dates.length, 35);
    assert.equal(dates[0], '2024-01-29');
    assert.equal(dates.at(-1), '2024-03-03');
    assert.ok(dates.includes('2024-02-29'));
    assert.equal(F.month('2026-08-01').length, 42);
});
test('month navigation clamps the day across short months and year boundaries', () => {
    assert.equal(F.shiftMonth('2026-01-31', 1), '2026-02-28');
    assert.equal(F.shiftMonth('2024-01-31', 1), '2024-02-29');
    assert.equal(F.shiftMonth('2026-12-31', 1), '2027-01-31');
});
