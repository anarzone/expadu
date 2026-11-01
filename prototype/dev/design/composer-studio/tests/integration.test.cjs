const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const load = (file) => {
    const scope = { module: { exports: {} }, Date, JSON };
    vm.runInNewContext(fs.readFileSync(require.resolve(file), 'utf8'), scope);
    return scope.module.exports;
};
const M = load('../model.js'),
    C = load('../conversation.js'),
    B = load('../../connected/model.js'),
    H = load('../integration.js');
const fresh = () => ({ ...M.fresh(), date: '2026-09-19' });
const receive = (s, items) =>
    H.receive(s, { ...B.fresh(), draft: items }, M, C);

test('step help carries the exact source and return link without scheduling or replacing a typed request', () => {
    const s = fresh();
    s.prompt = 'Keep my unfinished question';
    M.propose(s, ['walk']);
    const before = JSON.stringify({
        plans: s.plans,
        proposal: s.proposal,
        prompt: s.prompt,
    });
    assert.equal(typeof H.receiveBureaucracyHelp, 'function');
    const sources = [
        {
            key: 'case.bc.first_application.submit',
            title: 'Exact catalogue step',
        },
    ];
    assert.equal(H.receiveBureaucracyHelp(s, 'blue-card:1', sources, C), true);
    const response = C.current(s).entries.at(-1);
    assert.equal(response.kind, 'bureaucracy');
    assert.equal(response.sourceKey, sources[0].key);
    assert.match(
        H.bureaucracyLink(response),
        /process=blue-card&step=blue-card%3A1$/,
    );
    assert.equal(
        JSON.stringify({
            plans: s.plans,
            proposal: s.proposal,
            prompt: s.prompt,
        }),
        before,
    );
    assert.equal('documents' in response, false);
    assert.equal(H.receiveBureaucracyHelp(s, 'health:99', sources, C), false);
    assert.equal(H.receiveBureaucracyHelp(s, 'bank:0', sources, C), false);
});
test('Today selections accumulate once without turning bookmarks into plans', () => {
    const s = fresh();
    s.saved = ['greenbelt'];
    const selected = [B.places[0], B.places[1]];
    receive(s, selected);
    assert.equal(s.proposal.items.length, 2);
    assert.equal(s.plans.length, 0);
    const entries = C.current(s).entries.length;
    receive(s, selected);
    assert.equal(s.proposal.items.length, 2);
    assert.equal(C.current(s).entries.length, entries);
    const onlySaved = fresh();
    H.receive(onlySaved, { ...B.fresh(), savedIdeas: selected }, M, C);
    assert.equal(onlySaved.proposal, null);
});
test('a selection revises a confirmed day without modifying its confirmed activities', () => {
    const s = fresh();
    M.propose(s, ['walk']);
    M.confirm(s);
    const before = JSON.stringify(s.plans);
    receive(s, [B.places.find((x) => x.id === 'greenbelt')]);
    assert.equal(JSON.stringify(s.plans), before);
    assert.equal(s.proposal.items.length, 2);
    assert.equal(s.proposal.baseId, s.plans[0].id);
});
test('events preserve captured dates and fixed times', () => {
    const s = fresh(),
        event = B.events[0];
    receive(s, [event]);
    assert.equal(s.proposal.date, event.date);
    const item = s.proposal.items[0];
    assert.equal(item.start, event.start);
    assert.equal(item.end, event.end);
    assert.equal(item.fixed, true);
    assert.equal(item.id, 'event:' + event.id);
});
test('another day stays pending instead of replacing the current proposal', () => {
    const s = fresh();
    M.propose(s, ['walk']);
    const before = JSON.stringify(s.proposal);
    const result = receive(s, [B.events[0]]);
    assert.equal(result.remaining.length, 1);
    assert.match(result.remaining[0].handoffError, /proposal/);
    assert.equal(JSON.stringify(s.proposal), before);
    // The conversation says so, so it's the newest thing on screen.
    assert.match(C.current(s).entries.at(-1).text, /couldn’t add it yet/);
});
test('a Travel trip request reads as what you asked for', () => {
    const s = fresh();
    receive(s, [
        {
            id: 'request:Neumarkt:Mülheim',
            type: 'journey',
            title: 'Mülheim',
            from: 'Neumarkt',
            to: 'Mülheim',
            date: '2026-09-30',
            requestedTime: '14:00',
            mode: 'transit',
            preferences: { schedule: 'arrive' },
        },
    ]);
    const [question, answer] = C.current(s).entries.slice(-2);
    assert.equal(question.role, 'user');
    assert.equal(
        question.text,
        'Help with my trip from Neumarkt to Mülheim, arriving by 14:00 on Wed 30 Sept.',
    );
    assert.match(answer.text, /^Here’s your trip to Mülheim/);
    assert.equal(answer.kind, 'department-context');
});
test('service, route and incomplete appointment handoffs remain context, never invented schedules', () => {
    const s = fresh();
    receive(s, [
        {
            type: 'service',
            id: 's',
            title: 'Translation',
            requirements: 'Certified copy',
        },
        {
            type: 'journey',
            id: 'j',
            title: 'To the park',
            from: 'Home',
            to: 'Park',
        },
        {
            type: 'appointment',
            id: 'a',
            title: 'Appointment',
            date: '2026-09-20',
            time: '10:00',
        },
    ]);
    assert.equal(s.proposal, null);
    assert.equal(s.contextItems.length, 3);
    assert.equal(
        C.current(s).entries.filter((e) => e.kind === 'department-context')
            .length,
        3,
    );
    assert.equal(s.handoffs.length, 0);
});

const A = load('../conversation-actions.js').create(M, C);
test('cancelling a day clears its blocked additions in both stores, preserving other days and bookmarks', () => {
    const s = fresh();
    receive(s, [B.events[0]]);
    A.commit(s);
    const blocked = {
        ...B.events[0],
        id: 'overlapping-event',
        title: 'Another event',
    };
    const otherDay = { ...blocked, id: 'other-day', date: '2026-09-21' };
    M.propose(s, [], s.plans[0].date, s.plans[0].id);
    receive(s, [blocked, otherDay]);
    assert.equal(s.handoffs.length, 2);
    const shared = {
        ...B.fresh(),
        draft: JSON.parse(JSON.stringify(s.handoffs)),
        savedIdeas: [blocked],
    };
    s.saved = ['event:overlapping-event'];
    const day = s.plans[0];
    M.propose(s, [], day.date, day.id);
    s.proposal.items = [];
    A.commit(s, 'Cancelled day');
    H.clearCancelledHandoffs(s, shared);
    assert.equal(s.handoffs.length, 1);
    assert.equal(s.handoffs[0].id, 'other-day');
    assert.equal(shared.draft.length, 1);
    assert.equal(shared.draft[0].id, 'other-day');
    assert.equal(s.saved.length, 1);
    assert.equal(shared.savedIdeas.length, 1);
    assert.equal(s.plans.length, 0);
    assert.ok(s.conversation.lastApplied.cancellationEntryId);
    // Persisted cleanup must not block a deliberate new addition after cancellation.
    const restored = JSON.parse(JSON.stringify(s));
    shared.draft.push(blocked);
    H.clearCancelledHandoffs(restored, shared);
    assert.equal(shared.draft.length, 2);
    A.undo(restored);
    assert.equal(restored.plans.length, 1);
});
test('reload repairs legacy pending selections for an already cancelled day', () => {
    const s = fresh();
    M.propose(s, ['walk']);
    A.commit(s);
    const day = s.plans[0];
    M.propose(s, [], day.date, day.id);
    s.proposal.items = [];
    A.commit(s, 'Cancelled day');
    s.handoffs = [{ type: 'place', id: 'greenbelt', handoffError: 'Overlap' }];
    const shared = {
        ...B.fresh(),
        draft: JSON.parse(JSON.stringify(s.handoffs)),
    };
    const restored = JSON.parse(JSON.stringify(s));
    H.clearCancelledHandoffs(restored, shared);
    assert.equal(restored.handoffs.length, 0);
    assert.equal(shared.draft.length, 0);
});
test('explicit department attendance and appointments preserve chosen times in a proposal', () => {
    const s = fresh();
    receive(s, [
        {
            ...B.events[0],
            scheduled: true,
            attendance: 'visit',
            fixed: false,
            start: '12:00',
            end: '13:00',
            listedStart: '10:00',
            listedEnd: '18:00',
        },
    ]);
    assert.equal(s.proposal.items[0].start, '12:00');
    assert.equal(s.proposal.items[0].fixed, false);
    assert.equal(s.plans.length, 0);
    const p = fresh();
    receive(p, [
        {
            id: 'service-confirmed',
            type: 'appointment',
            title: 'My appointment',
            date: p.date,
            scheduled: true,
            start: '10:00',
            end: '11:00',
            sourceType: 'service',
        },
    ]);
    assert.equal(p.proposal.items[0].sourceType, 'service');
    assert.equal(p.contextItems?.length || 0, 0);
});
test('a route linked to an activity becomes a reviewable proposal without overwriting the confirmed plan', () => {
    const s = fresh();
    M.propose(s, ['walk']);
    M.confirm(s);
    const before = JSON.stringify(s.plans);
    receive(s, [
        {
            id: 'route-a',
            type: 'journey',
            title: 'Route to park',
            activityId: 'walk',
            planDate: s.date,
            date: s.date,
            from: 'Station',
            to: 'Blücherpark',
            depart: '12:00',
            arrive: '12:45',
            legs: [{ mode: 'walk' }],
        },
    ]);
    assert.equal(JSON.stringify(s.plans), before);
    assert.equal(s.proposal.items[0].journey.id, 'route-a');
});

test('bureaucracy entries reopen their exact process and reject external or unknown links', () => {
    assert.equal(
        H.bureaucracyLink({ sourceKey: 'core.anmeldung' }),
        '../bureaucracy-next/?view=detail&process=address&step=address%3A0',
    );
    const sourceURL =
        '../bureaucracy-next/?view=detail&process=blue-card&step=blue-card%3A1';
    assert.equal(
        H.bureaucracyLink({ sourceType: 'bureaucracy', sourceURL }),
        sourceURL,
    );
    for (const sourceURL of [
        'https://other.example/',
        'javascript:alert(1)',
        '../bureaucracy-next/?view=detail&process=unknown',
    ])
        assert.equal(
            H.bureaucracyLink({ sourceType: 'bureaucracy', sourceURL }),
            '../bureaucracy-next/',
        );
    assert.equal(
        H.bureaucracyLink({ sourceType: 'place', sourceURL }),
        '../bureaucracy-next/',
    );
});

test('orientation handoff uses the same flow without importing answers or changing unfinished planning', () => {
    const s = fresh();
    s.prompt = 'Keep this unsent question';
    M.propose(s, ['walk']);
    s.handoffs = [{ id: 'keep-this', type: 'place' }];
    const before = JSON.stringify({
        prompt: s.prompt,
        proposal: s.proposal,
        plans: s.plans,
        handoffs: s.handoffs,
    });
    assert.equal(H.receiveBureaucracyGuide(s, 'self', C), true);
    const response = C.current(s).entries.at(-1);
    assert.equal(response.kind, 'bureaucracy');
    assert.equal(response.orientation, true);
    assert.equal('answers' in response, false);
    assert.equal(
        JSON.stringify({
            prompt: s.prompt,
            proposal: s.proposal,
            plans: s.plans,
            handoffs: s.handoffs,
        }),
        before,
    );
    assert.equal(H.receiveBureaucracyGuide(s, 'adult', C), false);
    assert.equal(H.receiveBureaucracyGuide(s, null, C), false);
});

test('family orientation carries only a subject reference and preserves the active personal day', () => {
    const s = fresh();
    s.prompt = 'Keep my draft';
    M.propose(s, ['walk']);
    const before = JSON.stringify({
        prompt: s.prompt,
        proposal: s.proposal,
        plans: s.plans,
    });
    assert.equal(H.receiveBureaucracyGuide(s, 'shared-adult', C), true);
    const entry = C.current(s).entries.at(-1);
    assert.equal(entry.orientationPerson, 'shared-adult');
    assert.equal('answers' in entry, false);
    assert.equal('documents' in entry, false);
    assert.equal(
        JSON.stringify({
            prompt: s.prompt,
            proposal: s.proposal,
            plans: s.plans,
        }),
        before,
    );
});

test('situation handoff pins its preview profile without copying facts or changing planning', () => {
    const s = fresh();
    s.prompt = 'My unfinished request';
    M.propose(s, ['walk']);
    const before = JSON.stringify({
        prompt: s.prompt,
        proposal: s.proposal,
        plans: s.plans,
    });
    assert.equal(
        H.receiveBureaucracyGuide(s, 'self', C, 'fresh-design-v1'),
        true,
    );
    const entry = C.current(s).entries.at(-1);
    assert.equal(entry.orientationProfile, 'fresh-design-v1');
    assert.equal('facts' in entry, false);
    assert.equal(
        JSON.stringify({
            prompt: s.prompt,
            proposal: s.proposal,
            plans: s.plans,
        }),
        before,
    );
});
