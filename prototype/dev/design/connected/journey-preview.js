/* A compact rendering of Departures' captured data, never a live route result. */
(function (root) {
    function render(model, mode, destination, esc, options = {}) {
        const route = model?.filtered({ mode })[0];
        if (!route) return '';
        if (options.compact)
            return `<section class="mini-departures event-route-preview" aria-label="Journey planning"><header><strong>Get there with Departures</strong><span>Preview</span></header><p>${options.anytime ? 'Find a journey to the venue.' : 'Find a journey that fits your arrival time.'}</p><div class="event-route-mode">${mode === 'transit' ? 'Transit connections' : mode === 'bike' ? 'Cycle route' : 'Walking route'} <span>to ${esc(destination)}</span></div><small>Live routes aren’t connected in this preview. The full planner contains clearly marked sample journeys.</small></section>`;
        const capture = model.capture;
        const sameDestination =
            String(destination).toLocaleLowerCase() ===
            String(capture.to).toLocaleLowerCase();
        return `<section class="mini-departures" aria-label="Example journey from Departures">
            <header><strong>From Departures</strong><span>Sample journey</span></header>
            <p class="mini-route-name">${esc(capture.from)} <span aria-hidden="true">→</span> ${esc(capture.to)}</p>
            <small class="mini-route-source">Captured ${esc(capture.date)} · not live. ${sameDestination ? 'This example has not been checked for your date or starting point.' : `This example is not a route to ${esc(destination)}.`}</small>
            <div class="mini-route-summary"><span><time>${esc(route.depart)}</time><span class="mini-time-dash">–</span><time>${esc(route.arrive)}</time></span><strong>${route.duration}<small> min</small></strong></div>
            <div class="mini-route-legs" aria-label="Journey stages">${route.legs.map((leg) => `<span class="mini-leg mini-leg-${esc(leg.mode)}" style="flex-grow:${leg.duration}">${leg.mode === 'transit' ? `Line ${esc(route.line)}` : leg.mode === 'bike' ? 'Cycle' : 'Walk'} <small>${leg.duration}m</small></span>`).join('')}</div>
            <details class="mini-route-steps"><summary>Journey steps</summary><ol>${model
                .tripSteps(route)
                .map(
                    (step) =>
                        `<li><time>${esc(step.time)}</time><span>${esc(step.title)}<small>${esc(step.detail)}</small></span></li>`,
                )
                .join('')}</ol></details>
            
        </section>`;
    }
    root.ExpaduJourneyPreview = { render };
})(globalThis);
