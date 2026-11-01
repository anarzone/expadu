/* Isolated browser review: nothing here reads or writes the user's saved state. */
(function () {
    const memory = new Map();
    const storage = {
        getItem: (key) => memory.get(String(key)) ?? null,
        setItem: (key, value) => memory.set(String(key), String(value)),
        removeItem: (key) => memory.delete(String(key)),
        clear: () => memory.clear(),
        key: (index) => [...memory.keys()][index] ?? null,
        get length() {
            return memory.size;
        },
    };
    Object.defineProperty(window, 'localStorage', { value: storage });
    Object.defineProperty(window, 'sessionStorage', { value: storage });
    const kind =
        new URLSearchParams(location.search).get('content') || 'returning';
    const shared = ExpaduModel.fresh();
    if (kind !== 'empty') {
        shared.savedIdeas = [ExpaduModel.places[0], ExpaduModel.events[0]];
        shared.serviceRequests = [
            {
                id: 'service-doctors',
                type: 'service',
                category: 'doctors',
                title: 'Find a doctor',
                requirements: { area: 'Ehrenfeld', language: 'English' },
            },
        ];
        storage.setItem(
            'expadu-departures-next-saved',
            JSON.stringify(['r18']),
        );
        storage.setItem(
            'expadu-composer-studio-v1',
            JSON.stringify({
                version: 1,
                saved: [],
                plans: [],
                prompt: '',
                bookmarksConnected: true,
                proposal: {
                    date: kind === 'today' ? '2026-09-30' : '2026-10-03',
                    baseId: null,
                    items: [
                        {
                            id: 'walk',
                            title: 'Blücherpark',
                            start: '13:00',
                            end: '14:00',
                        },
                        {
                            id: 'greenbelt',
                            title: 'Innerer Grüngürtel',
                            start: '16:00',
                            end: '17:00',
                        },
                    ],
                },
            }),
        );
    }
    storage.setItem('expadu-connected-design-v1', JSON.stringify(shared));
    document.addEventListener('DOMContentLoaded', () => {
        const note = document.createElement('p');
        note.style.cssText = 'font-size:12px;padding:6px 24px;color:#59534c;';
        note.innerHTML = `Isolated content preview · <a href="tests/fixtures/continuity.html?content=returning">Returning user</a> · <a href="tests/fixtures/continuity.html?content=empty">Fresh user</a>`;
        document.querySelector('#shell').before(note);
        if (location.pathname.endsWith('/friendly.html')) {
            const debug = document.createElement('details');
            debug.innerHTML = '<summary>Test day state</summary><output id="day-state"></output>';
            document.querySelector('footer').append(debug);
            const refresh = () => { const shared = ExpaduStore.read(); document.querySelector('#day-state').textContent = JSON.stringify({reviewDay: shared.reviewDay, items: ExpaduHandoffs.dayItems(shared, ExpaduStore.studio(), '2026-09-30')}); };
            window.addEventListener('expadu:change', refresh); refresh();
        }
        // Record navigation rather than carrying test data into the real workspace.
        ExpaduStore.go = (page, params = {}) => {
            const output = document.createElement('output');
            output.id = 'fixture-handoff';
            output.textContent = JSON.stringify({
                page,
                params,
                prompt: ExpaduStore.read().prompt,
                workspace: ExpaduStore.studio(),
            });
            output.style.cssText =
                'display:block;padding:16px;font:12px monospace;overflow-wrap:anywhere';
            document.querySelector('#fixture-handoff')?.remove();
            document.querySelector('#main').append(output);
        };
    });
})();
