/* Test fixture: no reads or writes to the user's stored plans. */
(function () {
    const state = ExpaduModel.fresh();
    state.brief.date = '2026-09-08';
    state.brief.from = 'Neumarkt';
    state.brief.mode = 'transit';
    const workspace = { plans: [] };
    const routes = {
        today: '../today-c/',
        places: '../places-next/',
        events: '../events-next/',
        departures: '../departures-next/',
        bureaucracy: '../bureaucracy-next/',
        services: '../services-next/',
        composer: '../composer-studio/',
    };
    window.ExpaduStore = {
        read: () => structuredClone(state),
        studio: () => structuredClone(workspace),
        routes,
        update(fn) {
            const result = fn(state);
            window.dispatchEvent(new Event('expadu:change'));
            return result;
        },
        updateDay(fn) {
            const result = fn(state, workspace);
            window.dispatchEvent(new Event('expadu:change'));
            return result;
        },
        isPersistent: () => true,
        go(page, params) {
            const notice = document.createElement('p');
            notice.id = 'test-handoff';
            notice.textContent = page + ' ' + JSON.stringify(params);
            document.querySelector('#shared-dialog')?.close();
            document.querySelector('#main').prepend(notice);
        },
        composerBack: () => null,
    };
})();
