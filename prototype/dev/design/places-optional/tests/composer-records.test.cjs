const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const place = require('../sample.json').places[0];
test('Composer schedules a saved source place beyond its built-in fixtures', () => {
    const context = vm.createContext({ ExpaduModel: { places: [place] } });
    vm.runInContext(
        fs.readFileSync(
            path.resolve(__dirname, '../../composer-studio/model.js'),
            'utf8',
        ),
        context,
    );
    const model = context.StudioModel;
    const state = model.fresh();
    model.propose(state, [place.id], '2026-10-04');
    assert.equal(state.proposal.items.length, 1);
    assert.equal(state.proposal.items[0].title, place.title);
    assert.equal(state.proposal.items[0].sourceUrl, place.sourceUrl);
    assert.equal(state.proposal.items[0].lat, place.lat);
    assert.equal(state.plans.length, 0);
});
