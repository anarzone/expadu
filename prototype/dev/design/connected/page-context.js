/* What a department page is showing, sent with a request from its Composer bar
   so Composer can answer "Which of these…?" or "Is there a faster way?". It is
   read when you send and used for that one request only. */
(function () {
    const short = (date) =>
        new Date(date + 'T12:00:00').toLocaleDateString('en-GB', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
        });
    const providers = {
        events() {
            const ids = [
                ...new Set(
                    [...document.querySelectorAll('main [data-plan-item]')].map(
                        (x) => x.dataset.planItem,
                    ),
                ),
            ];
            if (!ids.length) return null;
            const date = new URLSearchParams(location.search).get('date');
            return {
                page: 'events',
                label: `${date ? `Events on ${short(date)}` : 'Events'} · ${ids.length} listed`,
                eventIds: ids,
                date,
            };
        },
        travel() {
            const value = (name) =>
                document
                    .querySelector(`.journey-fields #${name}, input[name="${name}"]`)
                    ?.value.trim() || '';
            const from = value('from'),
                to = value('to');
            // The way picked in Plan a trip goes with it (it had its own
            // Composer card until 16 Oct 2026).
            const way =
                document
                    .querySelector('#results .plan-option.is-on')
                    ?.getAttribute('aria-label') || '';
            return from && to
                ? {
                      page: 'travel',
                      label: `Your journey · ${from} → ${to}${way ? ` · ${way}` : ''}`,
                      from,
                      to,
                      ...(way ? { way } : {}),
                  }
                : { page: 'travel', label: 'Travel' };
        },
        bureaucracy() {
            const Preview = window.BureaucracyPreview,
                Plan = window.BureaucracyPlan;
            let stored = null;
            try {
                stored = JSON.parse(
                    localStorage.getItem('expadu-bureaucracy-design-v1'),
                );
            } catch {
                /* The sample case below still applies. */
            }
            const state = Plan.hydrate(
                stored && Array.isArray(stored.docs)
                    ? { ...Preview.fresh(), ...stored }
                    : Preview.fresh(),
            );
            const action = Plan.projection(state).next?.[0];
            const record = action && window.BureaucracySource?.[action.record];
            return {
                page: 'paperwork',
                label: action ? `Paperwork · ${action.name}` : 'Paperwork',
                next:
                    action && record
                        ? {
                              name: action.name,
                              title: record.title,
                              url: `${ExpaduStore.routes.bureaucracy}?scenario=existing&person=self&view=detail&process=${encodeURIComponent(action.process)}`,
                          }
                        : null,
            };
        },
        services() {
            const chosen = document.querySelector(
                '[data-service][aria-pressed="true"]',
            );
            if (!chosen) return { page: 'services', label: 'Services' };
            const title =
                window.ExpaduModel?.categories?.find(
                    (x) => x.id === chosen.dataset.service,
                )?.title || chosen.dataset.service;
            return {
                page: 'services',
                label: `Services · ${title}`,
                service: chosen.dataset.service,
            };
        },
    };
    window.ExpaduPageContext = () => {
        const name =
            document.body.dataset.page ||
            (document.body.classList.contains('departures-page') ? 'travel' : '');
        try {
            return providers[name]?.() || null;
        } catch {
            return null;
        }
    };
})();
