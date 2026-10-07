const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
function environment() {
    const scope = vm.createContext({ URLSearchParams });
    const load = (file) =>
        vm.runInContext(
            fs.readFileSync(path.join(__dirname, '..', file), 'utf8'),
            scope,
        );
    load('../connected/model.js');
    load('../connected/handoffs.js');
    load('model.js');
    load('conversation.js');
    load('integration.js');
    load('conversation-view.js');
    load('../departures-next/station-data.js');
    load('../departures-next/service-context.js');
    return scope;
}
function selectedRequest(scope) {
    const capture = scope.StationCapture;
    return {
        id: 'request:Neumarkt:Museum Ludwig',
        type: 'journey',
        title: 'Museum Ludwig',
        from: 'Neumarkt',
        to: 'Museum Ludwig',
        date: '2026-10-04',
        requestedTime: '22:19',
        mode: 'transit',
        preferences: { schedule: 'depart' },
        servicePreference: scope.ServiceContext.fromDeparture(
            capture.entries[1],
            capture.stop,
            capture,
        ),
    };
}

test('Composer retains service provenance as context and explains uncertainty instead of scheduling it', () => {
    const scope = environment();
    const item = selectedRequest(scope);
    const state = scope.StudioModel.fresh();
    scope.StudioIntegration.receive(
        state,
        {
            ...scope.ExpaduModel.fresh(),
            draft: [item],
        },
        scope.StudioModel,
        scope.StudioConversation,
    );
    assert.equal(state.proposal, null);
    assert.equal(state.plans.length, 0);
    const restored = JSON.parse(JSON.stringify(state));
    assert.equal(restored.contextItems[0].servicePreference.source, 'trias_rt');
    assert.equal(
        restored.contextItems[0].servicePreference.boardingPointId,
        null,
    );
    const entry = scope.StudioConversation.current(restored).entries.at(-1);
    const escape = (value) =>
        String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('"', '&quot;');
    const html = scope.StudioConversationView.entry(entry, {
        state: restored,
        escape,
        icon: () => '',
    });
    assert.match(html, /18.*Buchheim/);
    assert.match(html, /22:19/);
    assert.match(html, /rounded countdown/i);
    assert.match(html, /boarding point.*unconfirmed/i);
    assert.match(html, /not a confirmed route/i);
    assert.match(html, /4 Oct 2026/);
});

test('reviewing the request returns to the station study with an exact capture reference', () => {
    const scope = environment();
    const url = new URL(
        scope.ExpaduHandoffs.reviewURL(selectedRequest(scope)),
        'http://localhost/dev/design/composer-studio/',
    );
    assert.equal(url.pathname, '/dev/design/departures-next/');
    assert.equal(url.searchParams.get('service'), 'neumarkt-capture-2:0');
    assert.equal(
        url.searchParams.get('serviceCapture'),
        '2026-10-04T22:19:08+02:00',
    );
    assert.equal(url.searchParams.get('from'), 'Neumarkt');
    assert.equal(url.searchParams.get('to'), 'Museum Ludwig');
    assert.equal(url.searchParams.get('time'), '22:19');
    assert.equal(url.searchParams.get('route'), null);
});
