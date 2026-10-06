const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const vm = require('node:vm');
const ts = require('typescript');
const exports_ = {};
const source = path.resolve(
    __dirname,
    '../../../resources/js/components/events/event-presentation.ts',
);
const code = fs.existsSync(source) ? fs.readFileSync(source, 'utf8') : '';
vm.runInNewContext(
    ts.transpileModule(code, {
        compilerOptions: { module: ts.ModuleKind.CommonJS },
    }).outputText,
    { exports: exports_, Intl, Date },
);

const occurrence = {
    id: 17,
    occurrence_start: '2026-10-24T22:30:00Z',
    occurrence_end: '2026-10-25T00:15:00Z',
    title: 'Night at the museum',
    venue: { name: 'Museum', veedel: 'Centre' },
    category_label: 'Culture',
};

test('Composer handoff keeps occurrence identity and uses its Berlin date across UTC midnight', () => {
    assert.equal(typeof exports_.eventSelectionQuery, 'function');
    const query = exports_.eventSelectionQuery(occurrence);
    assert.equal(query.event, 17);
    assert.equal(query.start, '2026-10-24T22:30:00Z');
    assert.equal(query.date, '2026-10-25');
    assert.equal(query.prompt, undefined);
    assert.equal(query.plan, undefined);
});

test('schedule formatting retains the exact listed time and does not invent an unknown finish', () => {
    assert.equal(typeof exports_.eventSchedule, 'function');
    assert.equal(exports_.eventSchedule(occurrence), '00:30–02:15');
    assert.equal(
        exports_.eventSchedule({ ...occurrence, occurrence_end: null }),
        '00:30 · end to check',
    );
});

test('expired or started fixed events are not presented as a fresh planning action', () => {
    assert.equal(typeof exports_.canPlanEvent, 'function');
    assert.equal(
        exports_.canPlanEvent(occurrence, Date.parse('2026-10-24T22:00:00Z')),
        true,
    );
    assert.equal(
        exports_.canPlanEvent(occurrence, Date.parse('2026-10-24T22:30:00Z')),
        false,
    );
    assert.equal(
        exports_.canPlanEvent({ ...occurrence, occurrence_start: 'invalid' }),
        false,
    );
});

test('a saved recurring occurrence does not save every other date of that event', () => {
    assert.equal(typeof exports_.occurrenceKey, 'function');
    assert.notEqual(
        exports_.occurrenceKey(occurrence),
        exports_.occurrenceKey({
            ...occurrence,
            occurrence_start: '2026-11-01T00:30:00+01:00',
        }),
    );
    assert.equal(
        exports_.occurrenceKey(occurrence),
        exports_.occurrenceKey({
            ...occurrence,
            occurrence_start: '2026-10-25T00:30:00+02:00',
        }),
    );
});
