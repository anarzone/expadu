const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const sandbox = {};
// A trip's way is Travel's own list and line badge (departures-next).
for (const file of [
    '../../departures-next/labels.js',
    '../../departures-next/way.js',
    '../conversation-view.js',
])
    vm.runInNewContext(
        fs.readFileSync(require.resolve(file), 'utf8'),
        sandbox,
    );
const render = sandbox.StudioConversationView.entry;
function context() {
    const plan = { date: '2026-09-19', items: [] };
    return {
        state: {
            proposal: plan,
            plans: [],
            conversation: {
                activeDate: plan.date,
                versions: [
                    { id: 'v1', label: 'Initial', status: 'proposed', plan },
                ],
            },
        },
        escape: String,
        icon: () => '',
        card: () => '',
        timeline: (_p, editable) => (editable ? 'EDITABLE' : 'READONLY'),
        proposal: () => '<button>Apply</button>',
        dateLabel: String,
        place: (id) => ({ id, title: 'Park', label: 'Park', area: 'Cologne' }),
    };
}
test('earlier plan references expose read-only snapshots instead of stale apply actions', () => {
    const ctx = context();
    ctx.state.conversation.activeDate = null;
    const html = render(
        {
            id: 'p1',
            role: 'assistant',
            kind: 'plan',
            planDate: '2026-09-19',
            versionId: 'v1',
        },
        ctx,
    );
    assert.match(html, /READONLY/);
    assert.doesNotMatch(html, /<button>Apply/);
    assert.match(html, /Open current plan/);
});
test('replacement choices are inactive after changing the draft or clearing context', () => {
    const ctx = context();
    const entry = {
        id: 'r1',
        role: 'assistant',
        kind: 'replacements',
        placeIds: ['walk'],
        proposalToken: JSON.stringify(ctx.state.proposal),
    };
    assert.match(render(entry, ctx), /Browse more places/);
    ctx.state.conversation.activeDate = null;
    assert.doesNotMatch(render(entry, ctx), /Browse more places/);
    assert.match(render(entry, ctx), /disabled/);
    ctx.state.conversation.activeDate = '2026-09-19';
    ctx.state.proposal.items.push({ id: 'walk' });
    assert.match(render(entry, ctx), /disabled/);
});

test('historical plan cards never become live when the same day is active', () => {
    const ctx = context();
    const html = render(
        {
            id: 'old',
            role: 'assistant',
            kind: 'plan-history',
            planDate: '2026-09-19',
            versionId: 'v1',
        },
        ctx,
    );
    assert.match(html, /READONLY/);
    assert.match(html, /Earlier plan/);
    assert.match(html, /Open current plan/);
    assert.doesNotMatch(html, /Active plan|<button>Apply/);
});

test('legacy answer snapshots offer a clear path to the current plan', () => {
    const ctx = context();
    const html = render(
        {
            id: 'old-answer',
            role: 'assistant',
            kind: 'answer',
            plan: ctx.state.proposal,
        },
        ctx,
    );
    assert.match(html, /Earlier plan snapshot/);
    assert.match(html, /Open current plan/);
    ctx.historical = true;
    assert.doesNotMatch(
        render(
            {
                id: 'old-answer',
                role: 'assistant',
                kind: 'answer',
                plan: ctx.state.proposal,
            },
            ctx,
        ),
        /data-open-plan/,
    );
});

test('confirmed plans offer scrollable replacements without a draft and stale choices collapse', () => {
    const ctx = context();
    const plan = ctx.state.proposal;
    ctx.state.proposal = null;
    ctx.state.plans = [plan];
    const entry = {
        id: 'choices',
        role: 'assistant',
        kind: 'replacements',
        planDate: plan.date,
        proposalToken: JSON.stringify(plan),
        placeIds: ['a', 'b', 'c', 'd', 'e'],
    };
    const html = render(entry, ctx);
    assert.match(html, /5 alternatives/);
    assert.match(
        html,
        /role="region" aria-label="Alternative places" tabindex="0"/,
    );
    assert.match(html, /Browse more places/);
    assert.doesNotMatch(html, /disabled|Previous alternatives/);
    plan.items.push({ id: 'changed' });
    assert.match(render(entry, ctx), /Previous alternatives/);
    assert.doesNotMatch(render(entry, ctx), /Browse more places/);
});

test('cancelled day exposes undo only for the latest cancellation', () => {
    const ctx = context();
    ctx.state.proposal = null;
    ctx.state.conversation.lastApplied = {
        date: '2026-09-19',
        after: null,
        cancellationEntryId: 'cancel',
    };
    const e = {
        id: 'cancel',
        role: 'assistant',
        kind: 'plan-cancelled',
        planDate: '2026-09-19',
    };
    assert.match(render(e, ctx), /Undo cancellation/);
    ctx.state.conversation.lastApplied.cancellationEntryId = 'newer';
    assert.doesNotMatch(render(e, ctx), /Undo cancellation/);
    ctx.state.conversation.lastApplied = null;
    assert.doesNotMatch(render(e, ctx), /Undo cancellation/);
});
test('service responses render their card inside the conversation', () => {
    const ctx = context();
    ctx.serviceCard = (e) => '<section>Service ' + e.kind + '</section>';
    assert.match(
        render({ id: 'event', role: 'assistant', kind: 'event' }, ctx),
        /Service event/,
    );
    assert.match(
        render({ id: 'journey', role: 'assistant', kind: 'journey' }, ctx),
        /Service journey/,
    );
});

test('pending answers say Thinking and keep the cancel action available', () => {
    vm.runInNewContext(
        fs.readFileSync(require.resolve('../../connected/loading.js'), 'utf8'),
        sandbox,
    );
    const html = render(
        {
            id: 'thinking',
            role: 'assistant',
            kind: 'pending',
            text: 'Preparing a sample response…',
        },
        context(),
    );
    assert.match(html, /Thinking…/);
    assert.doesNotMatch(html, /Preparing a sample response/);
    assert.match(html, /role="status"/);
    // No Cancel beside "Thinking…" (owner, 8 October).
    assert.doesNotMatch(html, /Cancel|cancel-loading/);
});

test('answers lead with a short verdict, then their own block (the approved design)', () => {
    const ctx = context();
    ctx.escape = (x) => String(x).replace(/</g, '&lt;');
    const trip = render(
        {
            id: 't',
            role: 'assistant',
            kind: 'trip',
            title: 'Leave now — it’s 13:10.',
            text: 'You’ll be there at 13:48 with 12 minutes to spare.',
            trip: {
                way: [
                    {
                        kind: 'walk',
                        time: '13:10',
                        title: 'Walk to Neumarkt',
                        sub: '4 min on foot',
                    },
                    {
                        kind: 'ride',
                        line: '4',
                        time: '13:14',
                        title: 'Tram 4 to Wiener Platz',
                        sub: 'From Neumarkt · get off at 13:42',
                    },
                ],
                href: '../departures-next/?from=Neumarkt',
            },
        },
        ctx,
    );
    // Verdict, your way as Travel draws it, then when you'll be there, then
    // Open in Travel.
    assert.match(
        trip,
        /<h3 class="chat-answer-title">Leave now — it’s 13:10\.<\/h3><section class="answer-way"[^>]*><h4 class="nav-way-title">Your way<\/h4><ol class="nav-way">[\s\S]*<\/ol><\/section><p>You’ll be there at 13:48/,
    );
    assert.ok(trip.indexOf('Open in Travel') > trip.indexOf('You’ll be there'));
    // Tram 4 in its KVB colour, as on Travel's trip.
    assert.match(
        trip,
        /<li class="is-ride" style="--line:#A01E5A"><time>13:14<\/time>[\s\S]*class="tl-line"[\s\S]*<span>Tram 4 to Wiener Platz<\/span>/,
    );
    assert.match(trip, /Open in Travel/);
    assert.match(trip, /Scheduled sample times, not live/);

    const checklist = render(
        {
            id: 'c',
            role: 'assistant',
            kind: 'checklist',
            title: '1 of 5 documents marked ready.',
            text: 'That’s from your own checklist.',
            record: {
                title: 'EU Blue Card',
                prepared: 1,
                total: 5,
                href: '../bureaucracy-next/',
            },
        },
        ctx,
    );
    assert.match(
        checklist,
        /answer-checklist" href="\.\.\/bureaucracy-next\/"/,
    );
    assert.match(checklist, /Your EU Blue Card checklist · your records/);
    // The checklist card comes before the explanation.
    assert.ok(
        checklist.indexOf('answer-checklist') <
            checklist.indexOf('That’s from your own checklist.'),
    );
    assert.match(checklist, /width:20%/);

    const facts = render(
        {
            id: 'f',
            role: 'assistant',
            kind: 'facts',
            title: 'Not listed.',
            facts: [
                { label: 'Accessibility', value: 'Not listed', known: false },
                { label: 'Starts', value: 'Today 20:00', known: true },
            ],
        },
        ctx,
    );
    assert.match(facts, /<dd class="is-unknown">Not listed<\/dd>/);
    assert.match(facts, /<dt>Starts<\/dt><dd>Today 20:00<\/dd>/);
    // Titles are escaped like any answer text.
    assert.match(
        render({ id: 'x', role: 'assistant', title: '<b>' }, ctx),
        /&lt;b>/,
    );
});
