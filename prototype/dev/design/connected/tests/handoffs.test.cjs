const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm'),
    fs = require('node:fs');
const ctx = vm.createContext({ URLSearchParams });
vm.runInContext(
    fs.readFileSync(
        require('node:path').join(__dirname, '../handoffs.js'),
        'utf8',
    ),
    ctx,
);
const H = ctx.ExpaduHandoffs;
test('suggestions avoid occupied ranges and remain spread across the day', () => {
    const times = H.suggestTimes([
        { start: '09:00', end: '12:30' },
        { start: '14:00', end: '16:00' },
    ]);
    assert.equal(times.length, 3);
    for (const time of times) {
        assert.ok(time.start >= '12:30');
        assert.ok(time.end <= '14:00' || time.start >= '16:00');
    }
    assert.equal(new Set(times.map((t) => t.start)).size, 3);
});
test('suggestions respect the current-time boundary and full days', () => {
    assert.ok(
        H.suggestTimes([], 18 * 60 + 5).every((time) => time.start >= '18:30'),
    );
    assert.equal(H.suggestTimes([], 21 * 60).length, 0);
    assert.equal(H.suggestTimes([{ start: '09:00', end: '21:00' }]).length, 0);
});
test('an activity with an unknown end blocks later suggestions', () => {
    const times = H.suggestTimes([{ start: '11:00' }]);
    assert.ok(times.every((time) => time.end <= '11:00'));
});
const item = {
    id: 'event-a',
    type: 'event',
    title: 'Exhibition',
    date: '2026-09-19',
    start: '10:00',
    end: '18:00',
    attendancePolicy: 'window',
};
test('saving never schedules or changes a plan', () => {
    const s = { draft: [] };
    H.bookmark(s, item);
    assert.equal(s.draft.length, 0);
    assert.equal(s.savedIdeas.length, 1);
    H.bookmark(s, item);
    assert.equal(s.savedIdeas.length, 0);
});
test('attendance must be explicit and respect the occurrence', () => {
    assert.throws(
        () =>
            H.schedule(item, { date: item.date, start: '12:00', end: '13:00' }),
        /Choose/,
    );
    assert.throws(
        () =>
            H.schedule(item, {
                date: '2026-09-20',
                start: '12:00',
                end: '13:00',
                attendance: 'visit',
            }),
        /date/,
    );
    const x = H.schedule(item, {
        date: item.date,
        start: '12:00',
        end: '13:00',
        attendance: 'visit',
    });
    assert.equal(x.start, '12:00');
    assert.equal(x.listedStart, '10:00');
    assert.equal(x.listedEnd, '18:00');
    assert.throws(
        () =>
            H.schedule(item, {
                date: item.date,
                start: '09:00',
                end: '13:00',
                attendance: 'visit',
            }),
        /listed/,
    );
});
test('fixed attendance cannot silently shorten a listed session', () => {
    const x = H.schedule(item, {
        date: item.date,
        start: '12:00',
        end: '13:00',
        attendance: 'session',
    });
    assert.equal(x.start, '10:00');
    assert.equal(x.end, '18:00');
});
test('missing end time is explicit, and calendar dates are validated', () => {
    assert.throws(
        () =>
            H.schedule(
                { ...item, end: null },
                { date: item.date, attendance: 'session' },
            ),
        /times/,
    );
    assert.throws(
        () =>
            H.schedule(
                { ...item, type: 'appointment' },
                { date: '2026-02-30', start: '12:00', end: '13:00' },
            ),
        /date/,
    );
});
test('conflicts include confirmed plans and pending selections, not bookmarks', () => {
    const x = H.schedule(item, {
        date: item.date,
        attendance: 'visit',
        start: '12:00',
        end: '13:00',
    });
    const s = { draft: [], savedIdeas: [x] };
    const workspace = {
        plans: [
            {
                date: item.date,
                items: [
                    {
                        id: 'other',
                        title: 'Appointment',
                        start: '12:30',
                        end: '13:30',
                    },
                ],
            },
        ],
    };
    assert.match(H.conflict(s, workspace, x), /Appointment/);
    assert.equal(H.conflict(s, { plans: [] }, x), '');
    s.draft = [{ ...x, id: 'pending', title: 'Pending visit' }];
    assert.match(H.conflict(s, {}, x), /Pending visit/);
});
test('adjacent activities can be selected but a second unfinished day is blocked', () => {
    const x = { ...item, start: '12:00', end: '13:00' };
    assert.equal(
        H.conflict(
            { draft: [] },
            {
                plans: [
                    {
                        date: item.date,
                        items: [{ id: 'other', start: '13:00', end: '14:00' }],
                    },
                ],
            },
            x,
        ),
        '',
    );
    assert.match(
        H.conflict(
            { draft: [] },
            { proposal: { date: '2026-09-20', items: [] } },
            x,
        ),
        /proposal/,
    );
});
test('a fixed event conflict asks to review the day rather than move the event', () => {
    const selected = H.schedule(
        { ...item, attendancePolicy: 'fixed' },
        {
            date: item.date,
            attendance: 'session',
        },
    );
    const message = H.conflict(
        { draft: [] },
        {
            plans: [
                {
                    date: item.date,
                    items: [
                        {
                            id: 'other',
                            title: 'Appointment',
                            start: '12:00',
                            end: '13:00',
                        },
                    ],
                },
            ],
        },
        selected,
    );
    assert.match(message, /Appointment/);
    assert.match(message, /Review your day/);
    assert.doesNotMatch(message, /Choose another time/);
});
test('return links preserve chosen route and service category without external destinations', () => {
    assert.match(
        H.reviewURL({
            type: 'journey',
            id: 'r1',
            from: 'A',
            to: 'B',
            date: item.date,
            depart: '12:00',
        }),
        /route=r1/,
    );
    assert.match(
        H.reviewURL({ type: 'service', category: 'banks' }),
        /service=banks/,
    );
});
test('journey status rechecks changed activities, dates and preceding travel constraints', () => {
    const plan = { date: '2026-09-19' },
        target = { id: 'a', title: 'Museum', start: '14:00' },
        previous = { id: 'b', end: '13:00' };
    const route = {
        date: plan.date,
        activityId: 'a',
        targetStart: '14:00',
        targetLocation: 'Museum',
        to: 'Museum',
        previousId: 'b',
        previousEnd: '13:00',
        depart: '13:10',
        arrive: '13:40',
    };
    assert.match(H.journeyStatus(route, plan, target, previous), /Captured/);
    assert.match(
        H.journeyStatus(route, plan, { ...target, start: '15:00' }, previous),
        /Plan changed/,
    );
    assert.match(
        H.journeyStatus(
            { ...route, date: '2026-09-18' },
            plan,
            target,
            previous,
        ),
        /Different date/,
    );
    assert.match(
        H.journeyStatus({ ...route, depart: '12:50' }, plan, target, previous),
        /does not fit/,
    );
});

test('invalid month and blank appointment titles fail before queuing', () => {
    assert.throws(
        () =>
            H.schedule(
                { type: 'appointment', title: 'Visit' },
                { date: '2026-99-01', start: '12:00', end: '13:00' },
            ),
        /valid date/,
    );
    assert.throws(
        () =>
            H.schedule(
                { type: 'appointment', title: '  ' },
                { date: '2026-09-19', start: '12:00', end: '13:00' },
            ),
        /title/,
    );
});

test('Today skips completed days and selects the next upcoming confirmed activity', () => {
    const plans = [
        { date: '2026-09-18', items: [{ id: 'earlier', start: '10:00' }] },
        { date: '2026-09-19', items: [{ id: 'next', start: '12:00' }] },
    ];
    assert.equal(H.nextUpcoming(plans, '2026-09-18', '18:00').item.id, 'next');
    assert.equal(H.nextUpcoming([], '2026-09-18', '18:00'), null);
});

test('inline day review uses the current proposal instead of the older confirmed plan', () => {
    const workspace = {
        plans: [
            {
                date: '2026-09-19',
                items: [{ id: 'old', title: 'Old place', start: '13:00' }],
            },
        ],
        proposal: {
            date: '2026-09-19',
            items: [{ id: 'new', title: 'New place', start: '14:00' }],
        },
    };
    const list = H.dayItems({ draft: [] }, workspace, '2026-09-19');
    assert.equal(list.length, 1);
    assert.equal(list[0].id, 'new');
    assert.equal(list[0].planStatus, 'Proposal');
    assert.equal(workspace.proposal.items[0].planStatus, undefined);
});
test('inline day review includes pending visits once, orders times and ignores other dates and unscheduled ideas', () => {
    const shared = {
        draft: [
            {
                id: 'visit',
                date: '2026-09-19',
                start: '11:00',
                scheduled: true,
            },
            {
                id: 'show',
                type: 'event',
                date: '2026-09-19',
                start: '12:00',
                scheduled: true,
            },
            {
                id: 'other',
                date: '2026-09-20',
                start: '09:00',
                scheduled: true,
            },
            { id: 'idea', date: '2026-09-19', start: '10:00' },
        ],
    };
    const workspace = {
        plans: [
            {
                date: '2026-09-19',
                items: [
                    { id: 'event:show', start: '12:00' },
                    { id: 'park', start: '16:00' },
                ],
            },
        ],
    };
    const result = H.dayItems(shared, workspace, '2026-09-19');
    assert.equal(result.map((x) => x.id).join(','), 'visit,show,park');
    assert.equal(result[0].planStatus, 'Pending');
    assert.equal(result[2].planStatus, 'Confirmed');
    assert.equal(H.dayItems({ draft: [] }, workspace, '2026-09-21').length, 0);
});

test('shorter visits require an explicit visiting window; unknown policies keep the listed session', () => {
    const choice = {
        date: item.date,
        start: '12:00',
        end: '13:00',
        attendance: 'visit',
    };
    assert.throws(
        () => H.schedule({ ...item, attendancePolicy: 'fixed' }, choice),
        /fixed/,
    );
    for (const attendancePolicy of ['unknown', undefined]) {
        const unknown = { ...item, attendancePolicy };
        assert.throws(() => H.schedule(unknown, choice), /listed event time/);
        const planned = H.schedule(unknown, {
            ...choice,
            attendance: 'session',
        });
        assert.equal(planned.start, '10:00');
        assert.equal(planned.end, '18:00');
        assert.equal(planned.fixed, true);
        assert.equal(planned.entryUnconfirmed, true);
    }
    assert.equal(
        H.schedule({ ...item, attendancePolicy: 'window' }, choice)
            .entryUnconfirmed,
        false,
    );
    assert.equal(
        H.schedule({ ...item, attendancePolicy: 'window' }, choice).end,
        '13:00',
    );
});
test('an expected finish never replaces the listed start or invents an official end', () => {
    const selected = H.schedule(
        { ...item, start: '19:00', end: null, attendancePolicy: 'unknown' },
        {
            date: item.date,
            start: '20:00',
            end: '21:30',
            attendance: 'session',
        },
    );
    assert.equal(selected.start, '19:00');
    assert.equal(selected.end, '21:30');
    assert.equal(selected.listedEnd, null);
    assert.equal(selected.endEstimated, true);
});

test('event suggestions stay inside the listed window and avoid the current day activities', () => {
    const times = H.suggestTimes([{ start: '14:00', end: '15:00' }], 0, {
        from: '13:00',
        until: '17:00',
    });
    assert.ok(times.length > 0);
    assert.ok(times.every((x) => x.start >= '13:00' && x.end <= '17:00'));
    assert.ok(times.every((x) => x.end <= '14:00' || x.start >= '15:00'));
    assert.equal(
        H.suggestTimes([], 0, { from: '13:00', until: '13:45' }).length,
        0,
    );
});
