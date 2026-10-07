const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
function setup(draft = []) {
    const created = [];
    const workspace = { plans: [] };
    const shared = {
        draft: JSON.parse(JSON.stringify(draft)),
        revision: 0,
        brief: { date: '2026-09-19' },
    };
    const error = {
        hidden: true,
        focus() {
            this.focused = true;
        },
        scrollIntoView() {
            this.revealed = true;
        },
    };
    const submit = { textContent: 'Add to proposal' };
    const editContext = { hidden: true, textContent: '' };
    const feedback = { hidden: true, focus() {}, scrollIntoView() {} };
    const choice = { date: '2026-09-19', start: '18:00', end: '19:00' };
    const form = {
        dataset: {},
        elements: {
            date: { value: '2026-09-19' },
            attendance: { value: 'session' },
            start: { value: '' },
            end: { value: '' },
        },
        querySelector(selector) {
            if (selector === '[type="submit"]') return submit;
            if (selector === '[data-visit-edit-context]') return editContext;
            if (selector === '[data-visit-edit-feedback]') return feedback;
            return selector === '[role="alert"]'
                ? error
                : { after() {}, before() {} };
        },
    };
    const container = {
        querySelector() {
            return form;
        },
    };
    let closes = 0;
    const context = {
        window: { addEventListener() {} },
        document: {
            addEventListener() {},
            querySelectorAll() {
                return [];
            },
            createElement() {
                const element = {
                    querySelector() {
                        return null;
                    },
                    closest() {
                        return null;
                    },
                    querySelectorAll() {
                        return [];
                    },
                };
                created.push(element);
                return element;
            },
        },
        URLSearchParams,
        FormData: class {
            *[Symbol.iterator]() {
                yield* Object.entries(choice);
            }
        },
    };
    for (const file of [
        'model.js',
        'handoffs.js',
        'visit-timeline.js',
        'day-actions.js',
    ])
        vm.runInNewContext(
            fs.readFileSync(path.join(__dirname, '..', file), 'utf8'),
            context,
        );
    context.window.ExpaduVisitTimeline = context.ExpaduVisitTimeline;
    context.window.ExpaduDayActions = context.ExpaduDayActions;
    context.window.ExpaduDaySwipe = {
        bind(root, callbacks) {
            root.gestures = callbacks;
        },
    };
    context.ExpaduStore = {
        studio: () => workspace,
        read: () => shared,
        update: (fn) => fn(shared),
        updateDay: (fn) => fn(shared, workspace),
        isPersistent: () => true,
    };
    context.window.ExpaduUI = {
        esc: String,
        icon: () => '',
        close: () => closes++,
        toast() {},
    };
    vm.runInNewContext(
        fs.readFileSync(path.join(__dirname, '../planning-ui.js'), 'utf8'),
        context,
    );
    return {
        api: context.window.ExpaduPlanning,
        shared,
        workspace,
        submit,
        editContext,
        feedback,
        choice,
        form,
        error,
        container,
        created,
        closes: () => closes,
    };
}
const place = { id: 'visit', type: 'place', title: 'Park' };
function revealDayAction(agenda, id) {
    agenda.gestures.reveal({ dataset: { daySwipe: id } });
}
function dismissDayAction(agenda) {
    agenda.onkeydown({
        key: 'Escape',
        preventDefault() {},
        stopPropagation() {},
    });
}
function clickDayAction(agenda, attribute, id) {
    const button = {
        dataset: { dayRemove: id, confirmDayRemove: id },
        hasAttribute: (name) => name === attribute,
        closest: () => null,
    };
    agenda.onclick({ target: { closest: () => button } });
}
test('single taps do nothing when closed, dismiss when open, and keyboard activation reveals without deleting', () => {
    const h = setup();
    h.api.mount(
        {
            id: 'session',
            type: 'event',
            title: 'SchachXperten',
            date: '2026-09-19',
            start: '16:00',
            end: '17:30',
        },
        h.container,
    );
    const agenda = h.created.find(
        (node) => node.className === 'selection-agenda',
    );
    const card = { dataset: { daySwipe: 'session' } };
    const target = {
        closest: (selector) => (selector === '[data-day-swipe]' ? card : null),
    };
    agenda.onclick({ target, detail: 1 });
    assert.doesNotMatch(agenda.innerHTML, /data-confirm-day-remove=/);
    agenda.onkeydown({ target, key: 'Enter', preventDefault() {} });
    assert.match(agenda.innerHTML, /data-confirm-day-remove="session"/);
    assert.match(agenda.innerHTML, /is-preview/);
    agenda.onclick({ target, detail: 1 });
    assert.doesNotMatch(agenda.innerHTML, /data-confirm-day-remove=/);
    assert.match(agenda.innerHTML, /is-preview/);
    agenda.onclick({ target, detail: 0 });
    assert.match(agenda.innerHTML, /data-confirm-day-remove="session"/);
    assert.equal(h.form.elements.start.value, '16:00');
    assert.equal(h.shared.draft.length, 0);
});
test('revealing then deleting updates the day and Undo restores it without closing the place', () => {
    const h = setup([
        {
            ...place,
            scheduled: true,
            date: '2026-09-19',
            start: '18:00',
            end: '19:00',
        },
    ]);
    h.api.mount(place, h.container, () => assert.fail('must not add'));
    const agenda = h.created.find(
        (node) => node.className === 'selection-agenda',
    );
    h.form.elements.start.value = '18:00';
    h.form.elements.end.value = '19:00';
    revealDayAction(agenda, 'visit');
    assert.equal(h.shared.draft.length, 1, 'first tap must not remove');
    assert.match(agenda.innerHTML, /data-confirm-day-remove="visit"/);
    clickDayAction(agenda, 'data-confirm-day-remove', 'visit');
    assert.equal(h.shared.draft.length, 0);
    assert.equal(h.form.elements.start.value, '');
    assert.match(agenda.innerHTML, /0 activities/);
    assert.match(agenda.innerHTML, /data-undo-day-removal/);
    assert.doesNotMatch(agenda.innerHTML, /is-preview/);
    clickDayAction(agenda, 'data-undo-day-removal');
    assert.equal(h.shared.draft.length, 1);
    assert.match(agenda.innerHTML, /1 activity/);
    assert.doesNotMatch(agenda.innerHTML, /data-undo-day-removal/);
    assert.equal(h.closes(), 0);
});
test('Escape dismisses the revealed delete action without changing the activity or times', () => {
    const h = setup([
        {
            ...place,
            scheduled: true,
            date: '2026-09-19',
            start: '18:00',
            end: '19:00',
        },
    ]);
    h.api.mount(place, h.container);
    const agenda = h.created.find(
        (node) => node.className === 'selection-agenda',
    );
    h.form.elements.start.value = '18:00';
    h.form.elements.end.value = '19:00';
    revealDayAction(agenda, 'visit');
    assert.match(agenda.innerHTML, /is-revealed/);
    dismissDayAction(agenda);
    assert.equal(h.shared.draft.length, 1);
    assert.equal(h.form.elements.start.value, '18:00');
    assert.equal(h.form.elements.end.value, '19:00');
    assert.doesNotMatch(agenda.innerHTML, /data-confirm-day-remove/);
    clickDayAction(agenda, 'data-confirm-day-remove', 'visit');
    assert.equal(
        h.shared.draft.length,
        1,
        'an old confirmation cannot remove after Cancel',
    );
});
test('changing days dismisses a pending removal and cannot remove from the previous day', () => {
    const h = setup([
        {
            ...place,
            scheduled: true,
            date: '2026-09-19',
            start: '18:00',
            end: '19:00',
        },
    ]);
    h.api.mount(place, h.container);
    const agenda = h.created.find(
        (node) => node.className === 'selection-agenda',
    );
    revealDayAction(agenda, 'visit');
    h.form.elements.date.value = '2026-09-20';
    h.form.onchange();
    assert.doesNotMatch(agenda.innerHTML, /data-confirm-day-remove/);
    clickDayAction(agenda, 'data-confirm-day-remove', 'visit');
    assert.equal(h.shared.draft.length, 1);
});
test('confirmation cannot remove an activity changed since the first tap', () => {
    const h = setup([
        {
            ...place,
            scheduled: true,
            date: '2026-09-19',
            start: '18:00',
            end: '19:00',
        },
    ]);
    h.api.mount(place, h.container);
    const agenda = h.created.find(
        (node) => node.className === 'selection-agenda',
    );
    revealDayAction(agenda, 'visit');
    h.shared.draft[0].start = '17:00';
    h.form.oninput();
    clickDayAction(agenda, 'data-confirm-day-remove', 'visit');
    assert.equal(h.shared.draft.length, 1);
    assert.match(agenda.innerHTML, /role="alert"/);
});
test('a preview requires a separate delete tap and dismissal preserves the chosen times', () => {
    const h = setup([
        {
            id: 'other',
            type: 'place',
            title: 'Other',
            scheduled: true,
            date: '2026-09-19',
            start: '13:00',
            end: '14:00',
        },
    ]);
    h.api.mount(place, h.container, () => assert.fail('must not add'));
    const agenda = h.created.find(
        (node) => node.className === 'selection-agenda',
    );
    h.form.elements.start.value = '18:00';
    h.form.elements.end.value = '19:00';
    h.form.oninput();
    const savedBefore = JSON.stringify([h.shared, h.workspace]);
    revealDayAction(agenda, 'visit');
    assert.match(agenda.innerHTML, /data-confirm-day-remove="visit"/);
    assert.equal(h.form.elements.start.value, '18:00');
    assert.equal(h.form.elements.end.value, '19:00');
    dismissDayAction(agenda);
    assert.doesNotMatch(agenda.innerHTML, /data-confirm-day-remove/);
    clickDayAction(agenda, 'data-confirm-day-remove', 'visit');
    assert.equal(h.form.elements.start.value, '18:00');
    assert.equal(h.form.elements.end.value, '19:00');
    revealDayAction(agenda, 'visit');
    clickDayAction(agenda, 'data-confirm-day-remove', 'visit');
    assert.equal(h.shared.draft.length, 1);
    assert.equal(h.form.elements.start.value, '');
    assert.equal(h.form.elements.end.value, '');
    assert.doesNotMatch(agenda.innerHTML, /is-preview/);
    assert.equal(JSON.stringify([h.shared, h.workspace]), savedBefore);
});

test('changing a preview time invalidates the pending removal', () => {
    const h = setup();
    h.form.elements.attendance.value = 'visit';
    h.api.mount(
        {
            ...place,
            type: 'event',
            date: '2026-09-19',
            start: '10:00',
            end: '18:00',
            attendancePolicy: 'window',
        },
        h.container,
    );
    const agenda = h.created.find(
        (node) => node.className === 'selection-agenda',
    );
    h.form.elements.start.value = '14:00';
    h.form.elements.end.value = '15:00';
    h.form.oninput();
    revealDayAction(agenda, 'visit');
    assert.match(agenda.innerHTML, /data-confirm-day-remove="visit"/);
    h.form.elements.start.value = '16:00';
    h.form.elements.end.value = '17:00';
    h.form.oninput();
    assert.doesNotMatch(agenda.innerHTML, /data-confirm-day-remove/);
    clickDayAction(agenda, 'data-confirm-day-remove', 'visit');
    assert.equal(h.form.elements.start.value, '16:00');
    assert.equal(h.form.elements.end.value, '17:00');
    assert.equal(h.shared.draft.length, 0);
});
test('events keep their listed session by default without suggestions or editable time fields', () => {
    for (const policy of [undefined, 'unknown', 'fixed']) {
        const h = setup();
        h.api.mount(
            {
                id: 'session',
                type: 'event',
                title: 'Workshop',
                date: '2026-09-19',
                start: '16:00',
                end: '17:30',
                attendancePolicy: policy,
            },
            h.container,
        );
        assert.equal(h.form.elements.attendance.value, 'session');
        assert.equal(h.form.elements.start.value, '16:00');
        assert.equal(h.form.elements.end.value, '17:30');
        assert.equal(
            h.created.some((node) => node.className === 'visit-suggestions'),
            false,
        );
        assert.doesNotMatch(h.container.innerHTML, /data-time-input/);
        assert.match(
            h.container.innerHTML,
            /Starts[\s\S]*16:00[\s\S]*Ends[\s\S]*17:30/,
        );
        assert.equal(
            h.shared.draft.length,
            0,
            'showing the session is only a preview',
        );
    }
});
test('only an explicit visiting window offers suggestions and editable attendance times', () => {
    const h = setup();
    h.api.mount(
        {
            id: 'window',
            type: 'event',
            title: 'Flexible exhibition',
            date: '2026-09-19',
            start: '10:00',
            end: '18:00',
            attendancePolicy: 'window',
        },
        h.container,
    );
    assert.equal(h.form.elements.attendance.value, 'visit');
    assert.equal(h.form.elements.start.value, '');
    assert.equal(h.form.elements.end.value, '');
    assert.equal(h.form.elements.start.readOnly, false);
    assert.match(h.container.innerHTML, /data-time-input/);
    const suggestions = h.created.find(
        (node) => node.className === 'visit-suggestions',
    );
    assert.match(suggestions.innerHTML, /14:00–15:00/);
    suggestions.onclick({
        target: {
            closest: () => ({ dataset: { start: '14:00', end: '15:00' } }),
        },
    });
    assert.equal(h.form.elements.start.value, '14:00');
    assert.equal(h.form.elements.end.value, '15:00');
});
test('an unlisted end asks for an expected finish without changing the event start', () => {
    const h = setup();
    h.api.mount(
        {
            id: 'film',
            type: 'event',
            title: 'Film',
            date: '2026-09-19',
            start: '19:00',
            end: null,
        },
        h.container,
    );
    assert.equal(h.form.elements.start.value, '19:00');
    assert.equal(h.form.elements.start.readOnly, true);
    assert.equal(h.form.elements.end.value, '');
    assert.equal(h.form.elements.end.readOnly, false);
    assert.match(h.container.innerHTML, /Expected end/);
    assert.equal(
        h.created.some((node) => node.className === 'visit-suggestions'),
        false,
    );
});
test('choosing a suggested time fills both fields without adding the visit', () => {
    const h = setup();
    h.api.mount(place, h.container, () =>
        assert.fail('must not add automatically'),
    );
    const suggestions = h.created.find(
        (node) => node.className === 'visit-suggestions',
    );
    assert.match(suggestions.innerHTML, /10:00–11:00/);
    suggestions.onclick({
        target: {
            closest: () => ({ dataset: { start: '14:00', end: '15:00' } }),
        },
    });
    assert.equal(h.form.elements.start.value, '14:00');
    assert.equal(h.form.elements.end.value, '15:00');
    assert.equal(h.shared.draft.length, 0);
    const agenda = h.created.find(
        (node) => node.className === 'selection-agenda',
    );
    assert.match(agenda.innerHTML, /is-preview/);
    assert.match(agenda.innerHTML, /14:00/);
    h.form.elements.start.value = '18:00';
    h.form.elements.end.value = '19:00';
    h.form.oninput();
    assert.match(agenda.innerHTML, /18:00/);
    assert.doesNotMatch(agenda.innerHTML, /14:00/);
    h.form.elements.date.value = '2026-09-21';
    h.form.onchange();
    assert.equal(
        h.form.elements.start.value,
        '18:00',
        'changing date preserves a manually editable choice',
    );
});
test('inline addition keeps its parent open and undo removes only that visit', () => {
    const h = setup();
    let undo;
    h.api.mount(place, h.container, (_selected, action) => (undo = action));
    h.form.onsubmit({ preventDefault() {} });
    assert.equal(h.shared.draft.length, 1);
    assert.equal(h.shared.draft[0].start, '18:00');
    assert.equal(h.closes(), 0);
    undo();
    assert.equal(h.shared.draft.length, 0);
});
test('scheduling an existing unscheduled idea upgrades it and undo restores the original idea', () => {
    const h = setup([place]);
    let undo;
    h.api.mount(place, h.container, (_selected, action) => (undo = action));
    h.form.onsubmit({ preventDefault() {} });
    assert.equal(h.shared.draft.length, 1);
    assert.equal(h.shared.draft[0].scheduled, true);
    undo();
    assert.equal(h.shared.draft.length, 1);
    assert.equal(h.shared.draft[0].scheduled, undefined);
});
test('overlap errors stay inside the form without adding or closing anything', () => {
    const h = setup([
        {
            id: 'existing',
            type: 'place',
            title: 'Existing visit',
            scheduled: true,
            date: '2026-09-19',
            start: '18:30',
            end: '19:30',
        },
    ]);
    let added = false;
    h.api.mount(place, h.container, () => (added = true));
    h.form.onsubmit({ preventDefault() {} });
    assert.equal(added, false);
    assert.equal(h.error.hidden, false);
    assert.match(h.error.textContent, /overlaps Existing visit/);
    assert.equal(h.error.focused, true);
    assert.equal(h.error.revealed, true);
    assert.equal(h.shared.draft.length, 1);
    assert.equal(h.closes(), 0);
});
test('an existing visit loads its times and saves in place with an undo receipt', () => {
    const h = setup();
    h.workspace.plans = [
        {
            id: 'day',
            date: '2026-09-19',
            revision: 1,
            items: [{ ...place, start: '13:00', end: '14:00' }],
        },
    ];
    h.api.mount(place, h.container, () =>
        assert.fail('editing stays in the form'),
    );
    assert.equal(h.form.elements.start.value, '13:00');
    assert.equal(h.form.elements.end.value, '14:00');
    assert.equal(h.submit.textContent, 'Save changes');
    assert.equal(
        h.submit.disabled,
        true,
        'unchanged times cannot create another revision',
    );
    assert.match(h.editContext.textContent, /13:00–14:00/);
    h.form.elements.start.value = '18:00';
    h.form.elements.end.value = '19:00';
    h.form.oninput();
    assert.equal(h.submit.disabled, false);
    h.form.onsubmit({ preventDefault() {} });
    assert.equal(h.workspace.plans[0].items[0].start, '18:00');
    assert.equal(h.workspace.plans[0].items.length, 1);
    assert.equal(h.shared.draft.length, 0);
    assert.match(h.feedback.innerHTML, /Visit updated/);
    assert.equal(h.submit.disabled, true);
    assert.equal(h.closes(), 0);
    h.feedback.onclick({ target: { closest: () => ({}) } });
    assert.equal(h.workspace.plans[0].items[0].start, '13:00');
});
test('changing dates switches editing mode and a stale edit cannot overwrite a newer visit', () => {
    const h = setup([
        {
            ...place,
            scheduled: true,
            date: '2026-09-19',
            start: '13:00',
            end: '14:00',
        },
    ]);
    h.api.mount(place, h.container);
    h.form.elements.date.value = '2026-09-20';
    h.form.onchange();
    assert.equal(h.submit.textContent, 'Add to proposal');
    assert.equal(h.form.elements.start.value, '');
    h.form.elements.date.value = '2026-09-19';
    h.form.onchange();
    assert.equal(h.submit.textContent, 'Save changes');
    assert.equal(h.form.elements.start.value, '13:00');
    h.shared.draft[0].start = '12:00';
    h.form.oninput();
    h.form.onsubmit({ preventDefault() {} });
    assert.match(h.error.textContent, /day changed/);
    assert.equal(h.shared.draft[0].start, '12:00');
});
test('removing and restoring another activity keeps the unsaved visit times', () => {
    const h = setup([
        {
            ...place,
            scheduled: true,
            date: '2026-09-19',
            start: '13:00',
            end: '14:00',
        },
        {
            id: 'other',
            title: 'Other',
            scheduled: true,
            date: '2026-09-19',
            start: '15:00',
            end: '16:00',
        },
    ]);
    h.api.mount(place, h.container);
    h.form.elements.start.value = '18:00';
    h.form.elements.end.value = '19:00';
    h.form.oninput();
    const agenda = h.created.find(
        (node) => node.className === 'selection-agenda',
    );
    revealDayAction(agenda, 'other');
    clickDayAction(agenda, 'data-confirm-day-remove', 'other');
    assert.equal(h.form.elements.start.value, '18:00');
    clickDayAction(agenda, 'data-undo-day-removal');
    assert.equal(h.form.elements.start.value, '18:00');
});

test('an event with an unknown finish keeps its start and the entered expected end', () => {
    const h = setup();
    h.form.elements.attendance.value = 'visit';
    h.api.mount(
        {
            id: 'film',
            type: 'event',
            title: 'Film',
            date: '2026-09-19',
            start: '19:00',
            end: null,
            attendancePolicy: 'unknown',
        },
        h.container,
    );
    assert.equal(h.form.elements.start.readOnly, true);
    assert.equal(h.form.elements.end.readOnly, false);
    h.form.elements.end.value = '21:30';
    h.form.onchange({ target: { name: 'end' } });
    assert.equal(h.form.elements.start.value, '19:00');
    assert.equal(h.form.elements.end.value, '21:30');
});
test('explicit visiting windows show bounded suggestions without implying a booking', () => {
    const h = setup();
    h.form.elements.attendance.value = 'visit';
    h.api.mount(
        {
            id: 'event-visit',
            type: 'event',
            title: 'Exhibition',
            date: '2026-09-19',
            start: '10:00',
            end: '18:00',
            attendancePolicy: 'window',
        },
        h.container,
    );
    const agenda = h.created.find((x) => x.className === 'selection-agenda');
    const suggestions = h.created.find(
        (x) => x.className === 'visit-suggestions',
    );
    assert.match(agenda.innerHTML, /Nothing planned here yet/);
    assert.equal(h.form.elements.start.value, '');
    assert.equal(h.form.elements.end.value, '');
    assert.doesNotMatch(
        h.container.innerHTML,
        /flexibleConfirmed|type="radio"/,
    );
    assert.match(h.container.innerHTML, /Plan only · admission isn’t booked/);
    assert.equal(suggestions.hidden, false);
    assert.match(suggestions.innerHTML, /10:00–11:00/);
    assert.doesNotMatch(suggestions.innerHTML, /18:00–19:00/);
});

for (const policy of ['fixed', 'unknown', undefined])
    test(`the ${policy || 'default'} event preview supports confirmation and Undo without changing listed times`, () => {
        const h = setup();
        const event = {
            id: 'session',
            type: 'event',
            title: 'SchachXperten',
            date: '2026-09-19',
            start: '16:00',
            end: '17:30',
            attendancePolicy: policy,
        };
        const original = JSON.stringify(event);
        const stored = JSON.stringify([h.shared, h.workspace]);
        h.api.mount(event, h.container);
        const agenda = h.created.find(
            (x) => x.className === 'selection-agenda',
        );
        assert.match(agenda.innerHTML, /data-day-swipe="session"/);
        assert.doesNotMatch(
            agenda.innerHTML,
            /data-day-remove=|data-confirm-day-remove=/,
        );
        revealDayAction(agenda, 'session');
        assert.match(agenda.innerHTML, /data-confirm-day-remove="session"/);
        assert.match(agenda.innerHTML, /is-preview/);
        dismissDayAction(agenda);
        assert.match(agenda.innerHTML, /data-day-swipe="session"/);
        revealDayAction(agenda, 'session');
        clickDayAction(agenda, 'data-confirm-day-remove', 'session');
        assert.doesNotMatch(agenda.innerHTML, /is-preview/);
        assert.match(agenda.innerHTML, /Preview removed/);
        assert.match(agenda.innerHTML, /data-restore-day-preview/);
        assert.equal(h.form.elements.start.value, '16:00');
        assert.equal(h.form.elements.end.value, '17:30');
        assert.equal(h.form.elements.start.readOnly, true);
        assert.equal(h.form.elements.end.readOnly, true);
        h.form.oninput();
        h.form.onchange();
        assert.doesNotMatch(agenda.innerHTML, /is-preview/);
        clickDayAction(agenda, 'data-restore-day-preview');
        assert.match(agenda.innerHTML, /is-preview/);
        assert.match(agenda.innerHTML, /data-day-swipe="session"/);
        assert.equal(JSON.stringify(event), original);
        assert.equal(JSON.stringify([h.shared, h.workspace]), stored);
        assert.equal(
            h.created.some((node) => node.className === 'visit-suggestions'),
            false,
        );
    });
test('explicitly adding after removing a fixed preview still uses the listed schedule', () => {
    const h = setup();
    const event = {
        id: 'session',
        type: 'event',
        occurrence: 'session|2026-09-19T16:00',
        title: 'SchachXperten',
        date: '2026-09-19',
        start: '16:00',
        end: '17:30',
    };
    h.choice.attendance = 'session';
    h.api.mount(event, h.container);
    const agenda = h.created.find((x) => x.className === 'selection-agenda');
    revealDayAction(agenda, 'session');
    clickDayAction(agenda, 'data-confirm-day-remove', 'session');
    assert.match(agenda.innerHTML, /Preview removed/);
    assert.equal(h.shared.draft.length, 0);
    h.form.onsubmit({ preventDefault() {} });
    assert.equal(h.shared.draft.length, 1);
    assert.equal(h.shared.draft[0].start, '16:00');
    assert.equal(h.shared.draft[0].end, '17:30');
});

test('arranged appointments keep entered times and do not offer leisure suggestions', () => {
    const h = setup();
    let result;
    h.api.mount(
        {
            id: 'arranged',
            type: 'appointment',
            title: 'My appointment',
            fixed: true,
            personal: true,
        },
        h.container,
        (selected) => (result = selected),
    );
    assert.equal(
        h.created.some((node) => node.className === 'visit-suggestions'),
        false,
    );
    h.choice.start = '09:25';
    h.choice.end = '10:10';
    h.form.onsubmit({ preventDefault() {} });
    assert.equal(result.start, '09:25');
    assert.equal(result.end, '10:10');
    assert.equal(result.fixed, true);
    assert.match(
        h.container.innerHTML,
        /does not book or change the appointment/,
    );
    assert.doesNotMatch(h.container.innerHTML, /Opening hours/);
});
