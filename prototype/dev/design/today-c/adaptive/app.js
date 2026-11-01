/* Layout review only. Sample signals are never written to the shared plan. */
(function () {
    const S = ExpaduStore,
        I = ExpaduIcon;
    const rail = document.querySelector('.today-event-rail');
    const cards = [...rail.children];
    const featureHost = document.querySelector('#adaptive-feature');
    const connectionHost = document.querySelector('#today-connections');
    const glance = document.querySelector('.clarity-panel');
    const usualOrder = [...glance.children];
    const nav = document.querySelector('.adaptive-review');
    const options = ['everyday', 'event', 'delay'];
    const sampleFeature = {
        id: 'kunst-machen',
        important: true,
        date: '2026-09-08',
        startAt: '2026-09-08T16:00:00+02:00',
        endAt: '2026-09-08T17:30:00+02:00',
    };
    const sampleDelay = {
        id: 'sample-delay',
        routeId: 'usual-route',
        active: true,
        expiresAt: '2026-09-08T16:00:00+02:00',
    };
    let mode = 'everyday';
    let connectionMarkup = connectionHost.innerHTML;
    // The review sits one level below Today; shared destinations keep their normal paths.
    for (const [key, path] of Object.entries(S.routes)) {
        S.routes[key] = new URL(path, document.baseURI).href;
    }
    function delayMarkup() {
        return `<div class="adaptive-route-alert">
            <div class="adaptive-alert-heading"><span class="adaptive-line">${I('train')}18</span><span>Your usual route</span><span class="adaptive-sample">Sample delay</span></div>
            <h3 class="adaptive-delay-title">12 min <span>late</span></h3>
            <p class="adaptive-route-name">Neumarkt ${I('arrow')} Dom/Hbf</p>
            <div class="adaptive-departure-times"><span><small>Scheduled</small><s>15:42</s></span>${I('arrow')}<span><small>Expected</small><strong>15:54</strong></span></div>
            <div class="adaptive-route-actions"><button data-adaptive-action="routes">See routes ${I('arrow')}</button><button class="adaptive-ask" data-adaptive-action="composer" aria-label="Ask Composer about this route" title="Ask Composer">${I('spark')}</button></div>
            <details class="adaptive-alert-source"><summary>${I('info')}Example update · not live</summary><p>A sample interruption on a saved route. Departure times are illustrative. Open the route planner to check alternatives.</p></details>
        </div>`;
    }
    function syncOverview() {
        if (!connectionHost.querySelector('.adaptive-route-alert'))
            connectionMarkup = connectionHost.innerHTML;
        if (mode === 'delay') {
            glance.prepend(connectionHost);
            if (!connectionHost.querySelector('.adaptive-route-alert'))
                connectionHost.innerHTML = delayMarkup();
        } else {
            glance.append(...usualOrder);
            if (connectionHost.querySelector('.adaptive-route-alert'))
                connectionHost.innerHTML = connectionMarkup;
        }
    }
    function render(scenario, updateHistory = false) {
        if (!options.includes(scenario)) scenario = 'everyday';
        const chosen = TodayAdaptive.select({
            now: '2026-09-08T15:30:00+02:00',
            day: '2026-09-08',
            routeId: 'usual-route',
            feature: scenario === 'everyday' ? null : sampleFeature,
            delay: scenario === 'delay' ? sampleDelay : null,
        });
        mode = chosen.layout;
        document.body.dataset.todayLayout = mode;
        rail.append(...cards);
        const feature = cards.find(
            (card) => card.dataset.eventCard === chosen.featureId,
        );
        featureHost.hidden = !feature;
        if (feature) featureHost.append(feature);
        syncOverview();
        for (const button of nav.querySelectorAll('[data-scenario]')) {
            button.setAttribute(
                'aria-pressed',
                String(button.dataset.scenario === scenario),
            );
        }
        document.querySelector('#day-date').textContent =
            'Layout examples · 8 September 2026';
        if (updateHistory) {
            const url = new URL(location.href);
            url.searchParams.set('view', scenario);
            history.pushState({}, '', url);
        }
        rail.scrollLeft = 0;
        if (
            updateHistory &&
            !matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            document
                .querySelector('.adaptive-top')
                .animate([{ opacity: 0.65 }, { opacity: 1 }], {
                    duration: 180,
                    easing: 'ease-out',
                });
        }
    }
    nav.addEventListener('click', (event) => {
        const button = event.target.closest('[data-scenario]');
        if (button) render(button.dataset.scenario, true);
    });
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-adaptive-action]');
        if (!button) return;
        if (button.dataset.adaptiveAction === 'routes') {
            S.go('departures', {
                from: 'Köln Neumarkt',
                to: 'Köln Dom/Hbf',
                date: '2026-09-08',
                time: '15:30',
            });
        }
        if (button.dataset.adaptiveAction === 'composer') {
            const field = document.querySelector('#request');
            const request =
                'Help me check alternatives from Köln Neumarkt to Köln Dom/Hbf. The Today layout shows an illustrative 12-minute delay; check the current route status.';
            if (!field.value.trim()) field.value = request;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            if (S.isComposerMinimized?.() || S.canReturnToComposer?.()) {
                // A fresh handoff carries this draft into the existing workspace.
                S.go('composer');
            } else document.querySelector('.composer-launcher').click();
        }
    });
    for (const name of ['expadu:change', 'storage', 'pageshow'])
        window.addEventListener(name, syncOverview);
    window.addEventListener('popstate', () =>
        render(new URLSearchParams(location.search).get('view')),
    );
    for (const node of document.querySelectorAll(
        '.adaptive-review [data-icon], .adaptive-feature-label [data-icon]',
    ))
        node.innerHTML = I(node.dataset.icon);
    render(new URLSearchParams(location.search).get('view'));
})();
