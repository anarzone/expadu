const { test } = require('node:test'),
    assert = require('node:assert/strict'),
    fs = require('node:fs'),
    vm = require('node:vm');
const context = vm.createContext({});
vm.runInContext(
    fs.readFileSync(
        require('node:path').join(__dirname, '../model.js'),
        'utf8',
    ),
    context,
);
const M = context.ExpaduModel;
test('shared selections deduplicate by occurrence, not event title', () => {
    const s = M.fresh(),
        e = M.events[0];
    assert.equal(M.add(s, e), true);
    assert.equal(M.add(s, e), false);
    assert.equal(M.add(s, { ...e, occurrence: e.id + '|next-day' }), true);
    assert.equal(s.draft.length, 2);
});
test('saved day is a snapshot including prompt and exact requirements', () => {
    const s = M.fresh();
    s.prompt = 'quiet, no parks, only 30 minutes';
    s.brief.notes = 'Wheelchair access';
    M.add(s, M.places[0]);
    M.save(s);
    M.remove(s, M.key(M.places[0]));
    s.brief.notes = 'Changed';
    assert.equal(s.saved.items.length, 1);
    assert.equal(s.saved.brief.notes, 'Wheelchair access');
    assert.equal(M.dirty(s), true);
    M.editSaved(s);
    assert.equal(s.prompt, 'quiet, no parks, only 30 minutes');
    assert.equal(s.draft.length, 1);
    assert.equal(M.dirty(s), false);
});
test('move and remove/undo retain selection order without duplicates', () => {
    const s = M.fresh();
    M.places.slice(0, 3).forEach((x) => M.add(s, x));
    M.move(s, M.key(s.draft[2]), -1);
    const previous = s.draft.map(M.key),
        undo = M.remove(s, previous[1]);
    M.restore(s, undo);
    M.restore(s, undo);
    assert.deepEqual(s.draft.map(M.key), previous);
});
test('unknown quietness, exclusions and sport cannot silently become parks', () => {
    ['quiet', 'quiet, no parks', 'only 30 minutes', 'volleyball'].forEach((q) =>
        assert.equal(M.ideas(q).items.length, 0),
    );
    assert.equal(M.ideas('fresh air').items.length, 2);
});
test('overlap applies to the specific occurrence date; touching events do not overlap', () => {
    const a = M.events.find((e) => e.id === 'tandem'),
        b = M.events.find((e) => e.id === 'garden');
    assert.equal(M.overlaps([a, b]).length, 1);
    assert.equal(M.overlaps([a, { ...b, date: '2026-09-09' }]).length, 0);
    assert.equal(M.overlaps([a, { ...b, end: a.start }]).length, 0);
});
test('route and service context survive alongside other departments', () => {
    const s = M.fresh();
    M.add(s, M.places[0]);
    M.add(s, {
        id: 'request:doctor',
        type: 'service',
        title: 'Find a doctor',
        requirements: { language: 'English', insurance: 'Public' },
    });
    M.add(s, {
        id: 'r18',
        type: 'journey',
        title: 'Museum Ludwig',
        from: 'Köln Neumarkt',
        date: '2026-09-07',
        depart: '23:44',
    });
    M.save(s);
    assert.equal(s.saved.items[1].requirements.insurance, 'Public');
    assert.equal(s.saved.items[2].depart, '23:44');
});
