/* Daily brief alternative. Presentation only: shared records remain in their stores. */
(function (root) {
    root.TodayBriefPresentation = ({
        M,
        S,
        E,
        I,
        getDay,
        eventCard,
        shortDate,
    }) => {
        const dateLabel = (
            date,
            options = { day: 'numeric', month: 'short' },
        ) => new Date(`${date}T12:00:00`).toLocaleDateString('en-GB', options);
        const processURL = (id) =>
            `${S.routes.bureaucracy}?scenario=existing&person=self&view=detail&process=${encodeURIComponent(id)}`;

        function identity(item, small = false) {
            const day = getDay();
            return `<div class="agenda-identity ${small ? 'brief-small-plan' : ''}" data-day-swipe="${E(item.id)}" aria-expanded="false">
                <div class="identity-main"><time class="appointment-time" datetime="${day.date}T${item.start}">${item.start}</time><div class="appointment-copy"><h3 class="appointment-title">${E(item.title)}</h3><p class="appointment-location">${E(item.location)}</p><span class="status-pill brief-plan-status">${E(M.status(item))}</span></div></div>
                <details class="appointment-options"><summary aria-label="Options for ${E(item.title)}">${I('dots')}</summary><div class="appointment-menu"><button data-today-details="${E(item.id)}">Details</button><button data-today-reveal="${E(item.id)}">Remove</button></div></details>
                <div class="delete-reveal" hidden><button data-today-delete="${E(item.id)}" aria-label="Delete ${E(item.title)} from this sample day">${I('trash')}</button><span>Delete</span></div>
            </div>`;
        }
        function route(item) {
            const j = item.journey;
            if (!j) return '';
            const t = M.travel(item),
                id = E(item.id),
                delay = !t.stale && j.delay > 0;
            return `<div class="brief-journey ${delay ? 'has-delay' : ''}">
                ${delay ? `<div class="brief-alert"><strong>${j.delay} min delay</strong><span>${t.lateMinutes > 0 ? `Est. ${t.lateMinutes} min late at your appointment` : t.destinationArrival ? `Est. at appointment ${t.destinationArrival}` : 'Arrival at appointment needs checking'}</span><small>Simulated</small></div>` : ''}
                <button class="brief-route" data-today-route="${id}" aria-label="Journey to ${E(item.title)}: ${t.stale ? 'status unavailable' : `departs ${t.departure}, at stop ${t.stationArrival}`}" aria-haspopup="dialog">
                    <span class="brief-line">${I('train')}<b>${E(j.line)}</b></span>
                    ${t.stale ? '<span class="brief-route-unknown"><strong>Check this journey</strong><small>Live status unavailable</small></span>' : `<span class="brief-stop"><small>${E(j.from)}</small><strong>${delay ? `<del>${j.depart}</del>` : ''}${E(t.departure)}</strong></span><span class="route-connector" aria-hidden="true"><i></i></span><span class="brief-stop"><small>${E(j.to)}</small><strong>${delay ? `<del>${j.arrive}</del>` : ''}${E(t.stationArrival)}</strong></span>`}
                </button>
                <div class="brief-route-foot"><span>${t.destinationArrival ? `${j.walkMinutes} min walk · ${delay ? 'est. ' : ''}at appointment <b>${t.destinationArrival}</b>` : 'Arrival at appointment needs checking'}</span>${!delay && !t.stale ? '<small>Scheduled · not live</small>' : ''}</div>
                ${delay || t.stale ? `<div class="recovery-row"><button class="outline-action" data-today-alternatives="${id}" aria-expanded="false" aria-controls="alternatives-${id}">Alternatives</button><button class="outline-action contextual" data-today-context="${id}" aria-label="Ask Composer about this journey">${I('spark')}</button></div><div class="route-alternatives" id="alternatives-${id}" hidden><p>Check another connection for your ${item.start} appointment.</p><button class="text-action" data-today-travel="${id}">${I('train')}Find connections</button></div>` : ''}
            </div>`;
        }
        function weather() {
            return `<button class="brief-weather" data-today-weather aria-label="Open the sample ${E(getDay().city)} forecast"><span class="brief-weather-place">${E(getDay().city)} <small>Sample forecast</small></span><span class="brief-weather-now">${I('sun')}<strong>21°</strong><span>Sunny<br><small>This afternoon</small></span></span><span class="brief-weather-hours"><span>17:00 <b>20°</b></span><i></i><span>20:00 <b>18°</b></span></span></button>`;
        }
        function brief() {
            const day = getDay();
            const items = [...day.agenda].sort((a, b) =>
                a.start.localeCompare(b.start),
            );
            return `<section class="daily-brief" aria-labelledby="brief-title"><div class="brief-personal"><div class="brief-section-head"><h2 id="brief-title">Your day${items.length > 1 ? `<small>${items.length} plans</small>` : ''}</h2><button class="text-action add-plan" data-today-add>${I('plus')}Add a plan</button></div>
                ${items.length ? `<div class="brief-timeline" role="list" aria-label="Today's plans">${items.map((item, index) => `<article class="brief-appointment brief-timeline-item ${index ? 'is-secondary' : ''}" role="listitem" data-activity-id="${E(item.id)}">${identity(item, index > 0)}${route(item)}</article>`).join('')}</div>` : `<div class="brief-open-day"><strong>Go at your own pace.</strong><span>No plans yet.</span><button class="outline-action" data-today-prompt="day">${I('spark')}Find my starting point</button></div>`}
                <div class="day-feedback" role="status" ${day.removed.length ? '' : 'hidden'}>${day.removed.length ? `<span>${E(day.removed.at(-1).item.title)} removed</span><button data-today-undo>Undo</button><button data-today-dismiss-removal aria-label="Dismiss removal notice">${I('close')}</button>` : ''}</div>
            </div>${weather()}</section>`;
        }
        function smallHelp() {
            const focus = M.paperworkFocus(getDay().paperwork);
            return `<aside class="brief-help" aria-labelledby="help-title"><div class="brief-help-intro"><span class="brief-composer-symbol">${I('spark')}</span><div><h2 id="help-title">Make today yours.</h2><p>A little help, when you need it.</p></div></div><div class="brief-prompts"><button data-today-prompt="day">Review my day</button><button data-today-prompt="tomorrow">Think about tomorrow</button></div>
                ${focus ? `<a class="brief-paperwork" href="${processURL(focus.id)}" aria-label="Continue your ${E(focus.title)} checklist"><span class="brief-paperwork-icon">${I('document')}</span><span class="brief-paperwork-copy"><small>One thing to pick up · Sample</small><strong>${E(focus.title)}</strong><span>${E(focus.nextAction || 'Review your checklist')}</span></span><span class="brief-document-count"><b>${focus.prepared}<small>/${focus.total}</small></b><span>ready</span></span></a>` : ''}
                <div class="brief-city"><h3>City help</h3><div><a href="${S.routes.departures}">${I('train')}Plan a journey</a><a href="${S.routes.services}?service=doctors">${I('health')}Find a doctor</a></div></div>
            </aside>`;
        }
        function discovery(items, highlight = false) {
            return `<section class="brief-discovery ${highlight ? 'brief-highlight' : ''}" aria-labelledby="discovery-title"><div class="brief-section-head"><h2 id="discovery-title">${highlight ? 'Something special today' : `A little ${E(getDay().city)}`}</h2><a class="text-action" href="${S.routes.events}">All events</a></div><div class="event-rail" aria-label="Sample events. Swipe to browse." tabindex="0">${items.map((item) => eventCard(item, highlight && item.id === getDay().highlight?.id)).join('')}</div><div class="brief-rail-position" aria-label="Choose an event">${items.map((item, i) => `<button data-brief-slide="${i}" aria-label="Show ${E(item.title)}" aria-current="${i === 0 ? 'true' : 'false'}"><span></span></button>`).join('')}</div></section>`;
        }
        function upcoming() {
            const day = getDay(),
                items = day.upcoming
                    .filter((item) => item.date > day.date)
                    .slice(0, 2);
            return `<section class="brief-upcoming" aria-labelledby="later-upcoming"><div class="brief-later-head"><h3 id="later-upcoming">Coming up</h3><span>${items.length} plans</span></div>${items.map((item) => `<button class="brief-later-row" data-today-details="${E(item.id)}"><span class="brief-date"><small>${E(dateLabel(item.date, { month: 'short' }))}</small><b>${Number(item.date.slice(-2))}</b></span><span class="brief-row-copy"><strong>${E(item.title)}</strong><small>${E(item.location)}</small></span><time datetime="${item.date}T${item.start}">${item.start}</time></button>`).join('')}</section>`;
        }
        function savedIdeas(content) {
            const items = content.saved.slice(-2).reverse();
            return `<section class="saved-ideas brief-saved" aria-labelledby="later-saved"><div class="brief-later-head"><h3 id="later-saved">Saved ideas</h3><button class="text-action" data-today-workspace="saved">${items.length ? `View all ${content.saved.length}` : 'View saved'}</button></div>${items.length ? items.map((item) => `<div class="brief-later-row"><button class="brief-saved-open" data-today-kept="${E(TodayContinuity.key(item))}"><span class="brief-saved-symbol">${I(item.type === 'event' ? 'calendar' : 'pin')}</span><span class="brief-row-copy"><strong>${E(item.title)}</strong><small>${E([item.type === 'event' ? shortDate(item.date) : item.label || 'Place', item.venue || item.area || item.location].filter(Boolean).join(' · '))}</small></span></button><button class="brief-kept-plan" data-today-kept-plan="${E(TodayContinuity.key(item))}" aria-label="Plan ${E(item.title)}">${I('calendar-plus')}</button></div>`).join('') : `<div class="brief-saved-empty">${I('bookmark')}<span><strong>Found something you like?</strong><small>Save it. Come back when it suits you.</small></span></div>`}</section>`;
        }
        function continuation(content) {
            const c = content.composer,
                journey = JourneyModel.capture;
            return `<section class="brief-continue" aria-labelledby="later-continue"><div class="brief-later-head"><h3 id="later-continue">Pick up again</h3><button class="text-action" data-today-workspace="history">History</button></div>
                ${c ? `<button class="brief-later-row brief-resume" data-today-resume><span class="brief-resume-symbol">${I('spark')}</span><span class="brief-row-copy"><small>${E(c.status)}${c.date ? ` · ${E(shortDate(c.date))}` : ''}</small><strong>${E(c.title)}</strong><span class="brief-resume-label">${E(c.action)}</span></span></button>` : `<button class="brief-later-row brief-resume" data-today-prompt="day"><span class="brief-resume-symbol">${I('spark')}</span><span class="brief-row-copy"><strong>Start a conversation</strong><small>Your ideas can start here.</small></span></button>`}
                ${content.journeys
                    .slice(0, 1)
                    .map(
                        (item) =>
                            `<a class="brief-later-row brief-linked-record" href="${S.routes.departures}?route=${encodeURIComponent(item.id)}&date=${journey.date}&time=${journey.time}">${I('train')}<span class="brief-row-copy"><strong>${E(journey.from)} → ${E(journey.to)}</strong><small>Saved route · ${item.duration} min · Sample</small></span></a>`,
                    )
                    .join('')}
                ${content.requests
                    .slice(0, 1)
                    .map(
                        (item) =>
                            `<a class="brief-later-row brief-linked-record" href="${S.routes.services}?service=${encodeURIComponent(item.category)}">${I('grid')}<span class="brief-row-copy"><strong>${E(item.title)}</strong><small>Requirements saved · nothing booked</small></span></a>`,
                    )
                    .join('')}
            </section>`;
        }
        function render(content) {
            const day = getDay(),
                promoted = M.priority(day) === 'event';
            const greeting = `<header class="today-greeting"><div class="greeting-copy"><h1>Today, ${E(day.person)}<span>.</span></h1><p>${E(dateLabel(day.date, { weekday: 'short', day: 'numeric', month: 'short' }))} <span>Sample day</span></p></div><div class="greeting-tools"></div></header>`;
            return `${greeting}<div class="brief-canvas ${promoted ? 'is-event-led' : ''}">${promoted ? `<div class="brief-feature-band">${discovery(day.events, true)}${smallHelp()}</div>${brief()}` : `${brief()}<div class="brief-feature-band">${discovery(day.events)}${smallHelp()}</div>`}<section class="brief-later" aria-labelledby="later-title"><div class="brief-section-head"><h2 id="later-title">For later</h2><button class="text-action" data-today-workspace="plans">My plans</button></div><div class="brief-later-grid">${upcoming()}${savedIdeas(content)}<div id="continuity-slot">${continuation(content)}</div></div></section></div>`;
        }
        return { render, savedIdeas, continuation };
    };
})(globalThis);
