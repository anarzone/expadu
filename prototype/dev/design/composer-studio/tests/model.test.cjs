const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const context = { module: { exports: {} }, Date, JSON };
vm.runInNewContext(
    fs.readFileSync(require.resolve('../model.js'), 'utf8'),
    context,
);
const M = context.module.exports;
test('changing an activity time invalidates its journey and the following connection only', () => {
    const s = M.fresh();
    s.proposal = {
        date: '2026-10-03',
        items: [
            {
                id: 'first',
                title: 'First',
                start: '13:00',
                end: '14:00',
                journey: { status: 'scheduled' },
            },
            {
                id: 'second',
                title: 'Second',
                start: '15:00',
                end: '16:00',
                journey: { status: 'scheduled' },
            },
            {
                id: 'third',
                title: 'Third',
                start: '17:00',
                end: '18:00',
                journey: { status: 'scheduled' },
            },
        ],
    };
    M.setTime(s, 'first', '13:10', '14:10');
    assert.equal(s.proposal.items[0].journey.status, 'stale');
    assert.equal(s.proposal.items[1].journey.status, 'stale');
    assert.equal(s.proposal.items[2].journey.status, 'scheduled');
    M.setTime(s, 'third', '17:00', '18:00');
    assert.equal(s.proposal.items[2].journey.status, 'scheduled');
});
const filterPlaces = [
    {
        id: 'park',
        title: 'Grüngürtel',
        label: 'Park',
        category: 'Parks',
        area: 'Ehrenfeld',
    },
    {
        id: 'pitch',
        title: 'Pitch',
        label: 'Football',
        category: 'Sport',
        area: 'Merkenich',
    },
    {
        id: 'table',
        title: 'Table tennis',
        label: 'Table tennis',
        category: 'Sport',
        area: 'Merkenich',
    },
];
test('replacement category and Saved filters combine and exclude planned places', () => {
    const result = M.filterReplacementPlaces(
        filterPlaces,
        { items: [{ id: 'pitch' }] },
        ['park', 'pitch', 'table'],
        { category: 'Sport', savedOnly: true },
    );
    assert.equal(result.length, 1);
    assert.equal(result[0].id, 'table');
});
test('replacement search matches accents, categories and neighbourhoods', () => {
    const filter = (query) =>
        M.filterReplacementPlaces(filterPlaces, { items: [] }, [], { query });
    assert.equal(filter('grungurtel')[0].id, 'park');
    assert.equal(filter('Sport').length, 2);
    assert.equal(filter('Merkenich').length, 2);
});
test('empty replacement filters return no results without falling back to unrelated places', () => {
    assert.equal(
        M.filterReplacementPlaces(filterPlaces, { items: [] }, [], {
            savedOnly: true,
        }).length,
        0,
    );
    assert.equal(
        M.filterReplacementPlaces(filterPlaces, { items: [] }, [], {
            category: 'Parks',
            query: 'tennis',
        }).length,
        0,
    );
    assert.equal(
        M.filterReplacementPlaces(filterPlaces, { items: [] }, [], {}).length,
        3,
    );
});
test('replacement preserves timing and confirmed plans, and rejects duplicates or fixed activities', () => {
    const s = M.fresh();
    M.propose(s, ['walk', 'greenbelt', 'appointment'], '2026-10-03');
    const id = M.confirm(s);
    M.propose(s, [], '2026-10-03', id);
    const before = JSON.stringify(s.plans);
    const start = s.proposal.items[0].start;
    const end = s.proposal.items[0].end;
    assert.throws(() => M.replacePlace(s, 'walk', 'greenbelt'), /already/);
    assert.throws(
        () => M.replacePlace(s, 'appointment', 'pitch-ivens'),
        /fixed/,
    );
    assert.throws(() => M.replacePlace(s, 'walk', 'unknown'), /available/);
    M.replacePlace(s, 'walk', 'pitch-ivens');
    assert.equal(s.proposal.items[0].id, 'pitch-ivens');
    assert.equal(s.proposal.items[0].title, 'Bolzplatz · Ivenshofweg');
    assert.equal(s.proposal.items[0].start, start);
    assert.equal(s.proposal.items[0].end, end);
    assert.equal(s.proposal.items.length, 3);
    assert.equal(JSON.stringify(s.plans), before);
});
test('bookmarking never schedules an idea', () => {
    const s = M.fresh();
    M.bookmark(s, 'walk');
    assert.equal(JSON.stringify(s.saved), '["walk"]');
    assert.equal(s.proposal, null);
    assert.equal(s.plans.length, 0);
    M.bookmark(s, 'walk');
    assert.equal(s.saved.length, 0);
});
test('a proposal is separate until confirmation; repeated additions stay unique', () => {
    const s = M.fresh();
    M.propose(s, ['walk', 'walk'], '2026-09-19');
    assert.equal(s.proposal.items.length, 1);
    assert.equal(s.plans.length, 0);
    const id = M.confirm(s);
    assert.ok(id);
    assert.equal(s.proposal, null);
    assert.equal(s.plans.length, 1);
    M.propose(s, [], '2026-09-19', id);
    s.proposal.items[0].start = '16:00';
    s.proposal.items[0].end = '17:00';
    assert.equal(s.plans[0].items[0].start, '13:00');
    M.confirm(s);
    assert.equal(s.plans[0].items[0].start, '16:00');
    assert.equal(s.plans.length, 1);
});
test('overlaps and invalid durations cannot be confirmed', () => {
    const s = M.fresh();
    M.propose(s, ['walk', 'appointment'], '2026-09-19');
    s.proposal.items[0].start = '15:00';
    s.proposal.items[0].end = '16:00';
    assert.ok(M.conflicts(s.proposal).length);
    assert.throws(() => M.confirm(s));
    assert.equal(s.plans.length, 0);
    s.proposal.items[0].end = '14:00';
    assert.ok(M.conflicts(s.proposal).length);
});
test('sequential selections keep both places in the draft without confirming or replacing the response', () => {
    const s = M.fresh();
    s.response = { kind: 'discovery', request: 'Find parks' };
    s.prompt = 'Keep the afternoon relaxed';
    M.propose(s, ['walk'], '2026-10-03');
    s.proposal.items[0].start = '12:00';
    s.proposal.items[0].end = '13:00';
    M.propose(s, ['greenbelt'], '2026-10-03');
    M.propose(s, ['walk'], '2026-10-03');
    assert.equal(s.proposal.items.length, 2);
    assert.equal(s.proposal.items[0].start, '12:00');
    assert.equal(s.proposal.items[1].id, 'greenbelt');
    assert.equal(s.plans.length, 0);
    assert.equal(s.response.kind, 'discovery');
    assert.equal(s.prompt, 'Keep the afternoon relaxed');
    assert.equal(M.conflicts(s.proposal).length, 0);
});
test('revision preserves other dates and rejects stale versions', () => {
    const s = M.fresh();
    M.propose(s, ['walk'], '2026-09-19');
    const first = M.confirm(s);
    M.propose(s, ['greenbelt'], '2026-09-20');
    M.confirm(s);
    M.propose(s, [], '2026-09-19', first);
    s.plans[0].revision++;
    assert.throws(() => M.confirm(s), /changed/);
    assert.equal(s.plans[1].date, '2026-09-20');
});
test('questions and unknown requests do not create plans', () => {
    const s = M.fresh();
    assert.equal(
        M.respond('What have I planned for Saturday?', s).kind,
        'answer',
    );
    assert.equal(M.respond('Do I need a visa?', s).kind, 'bureaucracy');
    assert.equal(M.respond('Something quiet, no parks', s).kind, 'unavailable');
    assert.equal(M.respond('hello xyz', s).kind, 'clarify');
    assert.equal(s.proposal, null);
});
test('requests from Today keep their date and reach the right reply', () => {
    const s = M.fresh();
    s.date = '2026-10-10';
    const plan = M.respond('Help me plan my day on Wednesday 30 September.', s);
    assert.equal(plan.kind, 'proposal');
    assert.equal(plan.date, '2026-09-30');
    assert.equal(plan.appointment, false);
    assert.equal(
        M.respond('Help me plan Thursday 1 October. I already have Language class in Ehrenfeld at 18:00.', s).date,
        '2026-10-01',
    );
    // A journey to an appointment is not a paperwork question.
    const journey = M.respond(
        'How do I get to Mülheim by 14:00 on Wednesday 30 September? It’s for my Blue Card appointment.',
        s,
    );
    assert.equal(journey.kind, 'journey');
    assert.equal(journey.date, '2026-09-30');
    assert.equal(M.respond('How do I get a Blue Card?', s).kind, 'bureaucracy');
    assert.equal(
        M.respond('Help me settle in: which paperwork should I sort out first?', s).kind,
        'bureaucracy',
    );
    assert.equal(M.respond('Plan Saturday afternoon around my 15:00 appointment.', s).appointment, true);
    assert.equal(M.respond('October 3rd walk', s).date, '2026-10-03');
    assert.equal(M.respond('Plan 31 September', s).date, '2026-10-10');
});
test('explicit dates are retained and adjacent activities do not overlap', () => {
    const s = M.fresh();
    assert.equal(M.respond('Plan 2026-10-12', s).date, '2026-10-12');
    assert.equal(
        M.conflicts({
            items: [
                { title: 'A', start: '13:00', end: '14:00' },
                { title: 'B', start: '14:00', end: '15:00' },
            ],
        }).length,
        0,
    );
});
test('another day cannot overwrite an unfinished proposal', () => {
    const s = M.fresh();
    M.propose(s, ['walk'], '2026-09-19');
    assert.throws(
        () => M.propose(s, ['greenbelt'], '2026-09-20'),
        /Finish or discard/,
    );
    assert.equal(s.proposal.date, '2026-09-19');
    assert.equal(s.proposal.items[0].id, 'walk');
});
test('impossible dates and clock times are rejected', () => {
    const s = M.fresh();
    assert.throws(() => M.propose(s, ['walk'], '2026-02-30'), /valid date/);
    assert.equal(M.respond('Plan 2026-99-99', s).kind, 'clarify');
    M.propose(s, ['walk'], '2026-09-19');
    s.proposal.items[0].start = '24:30';
    s.proposal.items[0].end = '25:30';
    assert.throws(() => M.confirm(s), /valid times/);
});

test('time entry accepts 24-hour text and compact phone input', () => {
    assert.equal(M.normalizeTime('13:30'), '13:30');
    assert.equal(M.normalizeTime('1330'), '13:30');
    assert.equal(M.normalizeTime('930'), '09:30');
    assert.equal(M.normalizeTime('9:05'), '09:05');
    assert.equal(M.normalizeTime('0000'), '00:00');
    assert.equal(M.normalizeTime('2359'), '23:59');
});
test('time entry rejects invalid or incomplete values', () => {
    for (const value of [
        '',
        '13',
        '24:00',
        '12:60',
        '-1:00',
        '1:2',
        'noon',
        '12345',
    ]) {
        assert.equal(M.normalizeTime(value), null);
    }
});

test('time wheels expose independent hours and every minute, including boundaries', () => {
    const { hours, minutes } = M.timeWheelValues();
    assert.equal(hours.length, 24);
    assert.equal(minutes.length, 60);
    assert.equal(hours[0], '00');
    assert.equal(hours.at(-1), '23');
    assert.equal(minutes[0], '00');
    assert.equal(minutes.at(-1), '59');
    assert.equal(minutes[7], '07');
    for (const hour of hours)
        for (const minute of minutes) {
            assert.equal(
                M.normalizeTime(`${hour}:${minute}`),
                `${hour}:${minute}`,
            );
        }
});

test('successive additions use free slots without changing existing times', () => {
    const s = M.fresh();
    M.propose(s, ['walk', 'pitch-ivens', 'play-fahr', 'appointment']);
    assert.equal(
        s.proposal.items.find((x) => x.id === 'play-fahr').start,
        '17:00',
    );
    assert.equal(
        s.proposal.items.find((x) => x.id === 'appointment').start,
        '15:00',
    );
    assert.equal(M.conflicts(s.proposal).length, 0);
    const before = JSON.stringify(s.proposal.items);
    M.propose(s, ['table-kassel']);
    assert.equal(JSON.stringify(s.proposal.items.slice(0, 4)), before);
    assert.equal(s.proposal.items.at(-1).start, '18:00');
});
test('no-space and fixed-time conflicts reject the entire addition without mutation', () => {
    const s = M.fresh();
    M.propose(s, ['walk']);
    M.setTime(s, 'walk', '13:00', '23:59');
    const before = JSON.stringify(s);
    assert.throws(() => M.propose(s, ['play-fahr']), /no free/);
    assert.equal(JSON.stringify(s), before);
    assert.throws(() => M.propose(s, ['appointment']), /cannot be added/);
    assert.equal(JSON.stringify(s), before);
});
test('overlapping edits are rejected before changing the draft and adjacency is allowed', () => {
    const s = M.fresh();
    M.propose(s, ['walk', 'greenbelt', 'appointment']);
    const before = JSON.stringify(s);
    assert.throws(
        () => M.setTime(s, 'walk', '14:00', '16:30'),
        /already occupies/,
    );
    assert.equal(JSON.stringify(s), before);
    assert.throws(
        () => M.setTime(s, 'walk', '15:10', '15:20'),
        /already occupies/,
    );
    assert.equal(JSON.stringify(s), before);
    M.setTime(s, 'walk', '14:00', '15:00');
    assert.equal(M.conflicts(s.proposal).length, 0);
    assert.throws(() => M.setTime(s, 'appointment', '18:00', '19:00'), /fixed/);
});
test('move-later recommendations skip occupied times and preserve confirmed plans', () => {
    const s = M.fresh();
    M.propose(s, ['walk', 'greenbelt']);
    const id = M.confirm(s);
    const confirmed = JSON.stringify(s.plans);
    M.propose(s, [], s.date, id);
    M.moveLater(s, 'walk');
    assert.equal(s.proposal.items.find((x) => x.id === 'walk').start, '17:00');
    assert.equal(M.conflicts(s.proposal).length, 0);
    assert.equal(JSON.stringify(s.plans), confirmed);
});
test('legacy overlap warning states the duration and how to resolve it', () => {
    const s = M.fresh();
    M.propose(s, ['walk', 'greenbelt']);
    s.proposal.items[0].end = '17:00';
    assert.match(
        M.conflicts(s.proposal)[0],
        /overlap by 1 hour. Edit their times to continue/,
    );
});

test('personal appointments validate before creating a draft and can be edited', () => {
    const s = M.fresh();
    M.propose(s, ['walk']);
    M.confirm(s);
    const before = JSON.stringify(s);
    assert.throws(
        () =>
            M.addScheduled(
                s,
                {
                    id: 'personal-1',
                    title: 'Dentist',
                    start: '13:30',
                    end: '14:30',
                    type: 'appointment',
                },
                s.date,
            ),
        /occupies|overlap/,
    );
    assert.equal(JSON.stringify(s), before);
    M.addScheduled(
        s,
        {
            id: 'personal-1',
            title: 'Dentist',
            start: '15:00',
            end: '15:30',
            location: 'My clinic',
            type: 'appointment',
        },
        s.date,
    );
    M.setTime(s, 'personal-1', '15:30', '16:00');
    assert.equal(s.proposal.items[1].start, '15:30');
    assert.equal(s.plans[0].items.length, 1);
});
test('scheduled event stays on its occurrence date and its time cannot be changed', () => {
    const s = M.fresh();
    const event = {
        id: 'event-demo',
        title: 'Sample event',
        type: 'event',
        date: '2026-09-08',
        start: '16:00',
        end: '17:30',
        fixed: true,
    };
    assert.throws(() => M.addScheduled(s, event, '2026-09-19'), /date/);
    assert.equal(s.proposal, null);
    M.addScheduled(s, event, event.date);
    assert.throws(() => M.setTime(s, event.id, '17:00', '18:00'), /fixed/);
    assert.equal(s.proposal.items[0].start, '16:00');
});

test('editing a visiting window rejects invalid attendance without changing the proposal', () => {
    const s = M.fresh();
    M.addScheduled(
        s,
        {
            id: 'visit',
            title: 'Museum visit',
            type: 'event',
            date: s.date,
            attendance: 'visit',
            listedStart: '10:00',
            listedEnd: '18:00',
            start: '12:00',
            end: '13:00',
        },
        s.date,
    );
    const before = JSON.stringify(s.proposal);
    assert.throws(
        () => M.setTime(s, 'visit', '09:00', '11:00'),
        /visiting hours/,
    );
    assert.equal(JSON.stringify(s.proposal), before);
});

test('reviewing changed source appointments updates a proposal once, never the confirmed plan', () => {
    const s = M.fresh();
    const item = {
        id: 'recorded-a',
        sourceId: 'a',
        title: 'Appointment',
        type: 'appointment',
        start: '12:00',
        end: '13:00',
    };
    M.addScheduled(s, item, s.date);
    M.confirm(s);
    const before = JSON.stringify(s.plans);
    M.updateRecordedAppointment(
        s,
        { ...item, start: '14:00', end: '15:00' },
        s.date,
    );
    assert.equal(s.proposal.items.length, 1);
    assert.equal(s.proposal.items[0].start, '14:00');
    assert.equal(JSON.stringify(s.plans), before);
    assert.throws(
        () => M.updateRecordedAppointment(s, item, '2027-01-01'),
        /another day/,
    );
    assert.equal(JSON.stringify(s.plans), before);
});
