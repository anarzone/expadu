const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
// The connected previews share one sample day: Wednesday 30 September 2026, 13:10.
const context = { console };
context.globalThis = context;
vm.createContext(context);
for (const file of ['../../connected/sample-day.js', '../model.js'])
    vm.runInContext(fs.readFileSync(require.resolve(file), 'utf8'), context);
const M = context.StudioModel;
const ask = (text) => M.respond(text, M.fresh());

test('Composer uses the shared sample day for today, tomorrow, weekdays and the weekend', () => {
    assert.equal(M.today(), '2026-09-30');
    assert.equal(M.fresh().date, '2026-10-03');
    assert.equal(ask('Any concerts tonight?').date, '2026-09-30');
    assert.equal(ask('Help me plan tomorrow').date, '2026-10-01');
    assert.equal(ask('Plan a rainy Sunday').date, '2026-10-04');
    assert.equal(ask('What’s on this weekend?').date, '2026-10-03');
});

test('everyday questions reach a helpful reply instead of the fallback', () => {
    assert.equal(ask('What can you do?').kind, 'help');
    assert.equal(ask('hi').kind, 'help');
    assert.equal(ask('hello xyz').kind, 'clarify');
    const tonight = ask('Any concerts tonight?');
    assert.deepEqual([tonight.kind, tonight.topic, tonight.evening], ['event', 'music', true]);
    assert.equal(ask('What’s on this weekend?').days, 2);
    assert.equal(ask('Any exhibitions this week?').days, 7);
    assert.equal(ask('Plan a rainy Sunday').kind, 'indoor');
    assert.deepEqual(
        [ask('I need a doctor who speaks English').kind, ask('I need a doctor who speaks English').service],
        ['service', 'doctors'],
    );
    assert.equal(ask('Something fun with kids on Saturday').category, 'Family');
    // Cafés are real sample places now; Wi-Fi isn't recorded, so Composer says so.
    const cafe = ask('Find a café where I can work for a few hours');
    assert.deepEqual([cafe.kind, cafe.place, cafe.wifi], ['nearby', 'cafe', true]);
});

test('a question about your plans never becomes a plan request', () => {
    assert.equal(ask('When is my next appointment?').kind, 'next');
    assert.equal(ask('When is my next appointment?').appointment, true);
    assert.equal(ask('What’s on my day?').kind, 'next');
    assert.notEqual(ask('What should I do first?').kind, 'proposal');
});

test('journey questions keep the places and arrival time you give', () => {
    assert.deepEqual(copy(ask('How do I get to Köln Hbf?')), { kind: 'journey', date: '2026-10-03', to: 'Köln Hbf' });
    const both = ask('How do I get from Neumarkt to Mülheim by 14:00?');
    assert.deepEqual([both.from, both.to, both.arriveBy], ['Neumarkt', 'Mülheim', '14:00']);
    assert.equal(ask('Is there a faster way?').kind, 'journey');
    assert.equal(ask('How do I get a Blue Card?').kind, 'bureaucracy');
});

test('plans you mention are kept, and today starts after the current time', () => {
    assert.deepEqual(
        copy(M.mentionedPlans('Help me plan Thursday 1 October. I already have Language class in Ehrenfeld at 18:00 and Dinner with Sam at 20:00.')),
        [
            { title: 'Language class', location: 'Ehrenfeld', start: '18:00', end: '19:00' },
            { title: 'Dinner with Sam', location: '', start: '20:00', end: '21:00' },
        ],
    );
    const s = M.fresh();
    s.reviewDay = {
        date: '2026-09-30',
        agenda: [{ id: 'a', title: 'Blue Card appointment', start: '14:00', end: '14:30', fixed: true }],
    };
    M.propose(s, ['walk'], '2026-09-30');
    assert.deepEqual(
        copy(s.proposal.items.map((x) => `${x.title} ${x.start}`)),
        ['Blue Card appointment 14:00', 'Blücherpark 14:30'],
    );
});

function copy(value) {
    return JSON.parse(JSON.stringify(value));
}

test('everyday places near a point: kind, where, and today unless a day is named', () => {
    const pharmacy = ask('Is there a pharmacy near my Blue Card appointment?');
    assert.equal(pharmacy.kind, 'nearby');
    assert.equal(pharmacy.place, 'pharmacy');
    assert.equal(pharmacy.near, 'wiener-platz');
    assert.equal(pharmacy.date, '2026-09-30');
    const cafe = ask('Find a café near Neumarkt that is open now');
    assert.equal(cafe.place, 'cafe');
    assert.equal(cafe.open, true);
    assert.equal(ask('somewhere to eat near Rheinauhafen').place, 'restaurant');
    assert.equal(ask('bakery after my appointment').after, true);
    assert.equal(ask('Where can I work from a café with wifi?').wifi, true);
    assert.equal(ask('café near Neumarkt on Saturday').date, '2026-10-03');
    // A park with no place stays general discovery; paperwork stays paperwork.
    assert.equal(ask('find a park').kind, 'discovery');
    assert.equal(ask('Help me with my Blue Card paperwork').kind, 'bureaucracy');
    assert.equal(ask('something for kids on Saturday').kind, 'discovery');
});

test('“When should I leave for my 14:00?” is the appointment’s trip', () => {
    const r = ask('When should I leave for my 14:00?');
    assert.deepEqual(
        copy([r.kind, r.from, r.to, r.arriveBy, r.appointmentId]),
        ['journey', 'Neumarkt', 'Mülheim', '14:00', 'today-review-appointment'],
    );
    assert.equal(
        ask('When should I leave for my appointment?').appointmentId,
        'today-review-appointment',
    );
    // Another time isn't the appointment.
    assert.equal(ask('When should I leave for my 18:00?').appointmentId, undefined);
});
test('green spaces and fast food are nearby kinds; green needs a place, like parks', () => {
    const green = ask('Somewhere green near my appointment?');
    assert.deepEqual(
        copy([green.kind, green.place, green.near]),
        ['nearby', 'green', 'wiener-platz'],
    );
    assert.equal(ask('woods near Ehrenfeld').place, 'green');
    assert.notEqual(ask('somewhere green this weekend').kind, 'nearby');
    const bite = ask('A quick bite near Wiener Platz after my appointment');
    assert.deepEqual(copy([bite.place, bite.after]), ['fastfood', true]);
    assert.equal(ask('Döner near Neumarkt').place, 'fastfood');
    assert.equal(ask('a library near Neumarkt with wifi').wifi, true);
});
