/* Working visual preview. Scenario records stay local to this page's session. */
(function () {
    const M = TodayNext,
        S = ExpaduStore,
        U = ExpaduUI,
        I = TodayIcon,
        E = U.esc;
    const main = document.querySelector('#main'),
        scroller = document.querySelector('#page-scroll');
    const picker = document.querySelector('#scenario');
    const reviewControls = document.querySelector('.review-bar');
    const allowed = [...picker.options].map((option) => option.value);
    const requested = new URLSearchParams(location.search).get('view');
    let view = allowed.includes(requested) ? requested : 'everyday';
    let day,
        revealed = null,
        pendingContext = null,
        connectedSignature = '',
        detailRecord = null,
        editingPlan = null,
        syncingDay = false;
    const reviewKey =
        document.body.dataset.reviewKey || 'expadu-today-next-review';
    const todayHome = document.body.dataset.todayHome || './';
    const columnFlow = document.body.dataset.todayLayout === 'flow';
    let renderedContinuation = null;
    let contentPriority = null,
        recentCompletion = null;
    const sessionKey = (name) => `${reviewKey}-${name}`;
    // Where the tram leg ends. Saved copies of the sample appointment can
    // predate its stop name, so it comes from the shared sample day then.
    function alightStop(j) {
        const sample = window.ExpaduSampleDay?.appointment;
        return (
            j.alightStop ||
            (j.activityId && j.activityId === sample?.id
                ? sample.journey?.alightStop
                : '') ||
            j.to
        );
    }
    function load(name) {
        const base = M.scenario(name);
        const fresh = window.TodayContent ? TodayContent.prepare(base) : base;
        try {
            const saved = JSON.parse(sessionStorage.getItem(sessionKey(name)));
            if (
                saved?.version === 1 &&
                Array.isArray(saved.agenda) &&
                Array.isArray(saved.removed)
            ) {
                fresh.agenda = saved.agenda;
                fresh.removed = saved.removed;
                if (Array.isArray(saved.preparations))
                    fresh.preparations = saved.preparations;
            }
        } catch {
            /* The preview is usable without browser storage. */
        }
        return fresh;
    }
    function persist() {
        try {
            sessionStorage.setItem(
                sessionKey(view),
                JSON.stringify({
                    version: 1,
                    agenda: day.agenda,
                    removed: day.removed,
                    preparations: day.preparations,
                    scroll: scroller.scrollTop,
                }),
            );
        } catch {
            /* Keep this review in memory. */
        }
    }
    day = load(view);
    picker.value = view;

    function initializeDay(reset = false) {
        const source = `${reviewKey}:${view}`;
        const stored = S.read().reviewDay;
        if (!reset && stored?.source === source && stored.date === day.date) {
            day.agenda = structuredClone(stored.agenda);
        } else {
            syncingDay = true;
            S.update((shared) => {
                shared.reviewDay = {
                    source,
                    date: day.date,
                    agenda: day.agenda.map((item) => ({
                        ...item,
                        date: day.date,
                        type: item.type || 'appointment',
                        personal: item.type !== 'event',
                        sample: true,
                    })),
                };
                // A request Today wrote for another sample day does not follow
                // you here; anything you typed yourself stays.
                const suggested = shared.todaySuggestion;
                if (suggested && shared.prompt?.includes(suggested))
                    shared.prompt = shared.prompt.replace(suggested, '').trim();
                delete shared.todaySuggestion;
            });
            syncingDay = false;
            const field = document.querySelector('#request');
            if (field && field.value !== (S.read().prompt || '')) {
                field.value = S.read().prompt || '';
                field.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
        syncDay();
    }
    function syncDay() {
        const shared = S.read(),
            workspace = S.studio();
        const plan =
            workspace?.proposal?.date === day.date
                ? workspace.proposal
                : workspace?.plans?.find((entry) => entry.date === day.date);
        const primary =
            plan?.items ||
            ExpaduHandoffs.reviewItems(shared, workspace, day.date);
        const completed = [
            ...primary,
            ...(shared.draft || []).filter((item) => item.date === day.date),
        ].filter(
            (item, index, items) =>
                item.completed &&
                items.findIndex((entry) => entry.id === item.id) === index,
        );
        const items = [
            ...ExpaduHandoffs.dayItems(shared, workspace, day.date),
            ...completed,
        ].map((item) => ({
            ...item,
            location: item.location || item.venue || 'Location not set',
        }));
        if (JSON.stringify(items) === JSON.stringify(day.agenda)) return false;
        day.agenda = items;
        return true;
    }
    function changeDay(change) {
        syncingDay = true;
        try {
            return S.updateDay(change);
        } finally {
            syncingDay = false;
            syncDay();
            persist();
        }
    }
    function setDone(item, completed) {
        changeDay((shared, workspace) => {
            const plan =
                workspace?.proposal?.date === day.date
                    ? workspace.proposal
                    : workspace?.plans?.find(
                          (entry) => entry.date === day.date,
                      );
            for (const list of [
                shared.reviewDay?.date === day.date
                    ? shared.reviewDay.agenda
                    : [],
                plan?.items,
                (shared.draft || []).filter((entry) => entry.date === day.date),
            ])
                (list || [])
                    .filter((entry) => entry.id === item.id)
                    .forEach((entry) => {
                        entry.completed = completed;
                    });
        });
    }
    initializeDay();

    // One vector icon set and one labelled header across every scenario.
    // Current previews ship that final structure in HTML; retain the legacy
    // enhancement only for older alternatives that still start with an empty shell.
    if (!document.querySelector('.app-header[data-static-header]')) {
        const navLabels = {
            today: 'Today',
            events: 'Events',
            bureaucracy: 'Paperwork',
            departures: 'Travel',
            services: 'Services',
        };
        const navIcons = {
            today: 'home',
            events: 'calendar',
            bureaucracy: 'document',
            departures: 'train',
            services: 'grid',
        };
        document.querySelectorAll('.service-nav a').forEach((a) => {
            a.innerHTML = `${I(navIcons[a.dataset.page])}<span>${navLabels[a.dataset.page]}</span>`;
            if (a.dataset.page === 'today') a.href = todayHome;
        });
        document.querySelector('.brand').href = todayHome;
        document.querySelector('.city').innerHTML =
            `${I('pin')}<span>Cologne</span>${I('down')}`;
        document.querySelector('.city').setAttribute('aria-label', 'Cologne');
        document.querySelector('.my-day').innerHTML =
            `${I('bookmark')}<span>Saved</span><b data-count hidden>0</b>`;
        document
            .querySelector('.app-header')
            .insertAdjacentHTML(
                'beforeend',
                '<button class="account-button" aria-label="Your account" data-today-account><span>A</span></button>',
            );
        const header = document.querySelector('.app-header');
        const utilities = document.createElement('div');
        utilities.className = 'header-utilities';
        utilities.append(
            header.querySelector('.header-context'),
            header.querySelector('.my-day'),
            header.querySelector('.account-button'),
        );
        header.append(document.querySelector('.service-nav'), utilities);
    }
    U.sync();

    const eventById = (id) => day.events.find((item) => item.id === id);
    const saved = (item) =>
        (S.read().savedIdeas || []).some(
            (x) => ExpaduHandoffs.key(x) === ExpaduHandoffs.key(item),
        );
    // Events wear the Events posters set from their listing, no photo
    // (owner decision, 9 Oct 2026): the art in a card's picture slot and
    // as the event sheet's cover.
    const photo = (item) => ExpaduPosterArt.art(item, 'pp-art--slot');
    const originalImage = ExpaduEventFestival.image;
    ExpaduEventFestival.image = (item, eager) =>
        item.id.startsWith('today-review-')
            ? ExpaduPosterArt.art(item, 'pp-art--cover')
            : originalImage(item, eager);
    // The sheet names its artwork; say what this one is, as Events does.
    new MutationObserver(() => {
        const sheet = document.querySelector('.event-sheet');
        if (!sheet || sheet.dataset.posterCredit) return;
        if (!sheet.querySelector('.event-ticket .pp-art')) return;
        sheet.dataset.posterCredit = 'true';
        const row = [...sheet.querySelectorAll('.event-source dt')].find(
            (dt) => dt.textContent === 'Artwork',
        );
        if (row)
            row.nextElementSibling.textContent =
                'Poster set by Expadu from the listing, not an official poster';
    }).observe(document.body, { childList: true, subtree: true });
    const swatch = (label, type = '') =>
        `<span class="status-pill ${type}">${E(label)}</span>`;
    const shortDate = (date) =>
        TodayContinuity.validDate(date)
            ? new Date(`${date}T12:00:00`).toLocaleDateString('en-GB', {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              })
            : '';
    function connectedContent() {
        let savedJourneys = [];
        try {
            savedJourneys = JSON.parse(
                localStorage.getItem('expadu-departures-next-saved'),
            );
        } catch {
            /* Missing storage is an empty collection. */
        }
        return TodayContinuity.project({
            shared: S.read(),
            workspace: S.studio(),
            savedJourneys,
            journeyModel: JourneyModel,
            categories: ExpaduModel.categories,
        });
    }
    const enrichment = window.TodayEnrichment?.create({
        S,
        I,
        E,
        main,
        getDay: () => day,
        shortDate,
        saved,
    });

    function eventCard(item, hero = false) {
        return `<article class="event-card is-poster pp--${ExpaduPosterArt.ink(item)}" data-event-card="${E(item.id)}">
            <button class="event-open" data-today-event="${E(item.id)}" aria-label="Explore ${E(item.title)}">${photo(item)}<span class="event-copy"><strong>${E(item.title)}</strong><small>${item.start}–${item.end}</small><small>${E(item.venue)}</small></span></button>
            ${hero ? '<span class="event-badge">Today’s highlight · Sample</span>' : ''}
            <button class="event-save" data-today-save="${E(item.id)}" aria-pressed="${saved(item)}" aria-label="${saved(item) ? 'Unsave' : 'Save'} ${E(item.title)}" title="Save for later">${I('bookmark')}</button>
            <button class="event-plan" data-today-plan="${E(item.id)}" aria-label="Plan to go to ${E(item.title)}" title="Plan to go">${I('calendar-plus')}</button>
        </article>`;
    }
    const inMinutes = (value) =>
        value <= 0 ? 'now' : value < 60 ? `in ${value} min` : '';
    // The tram to take leads (leaveBy's take): the earlier one when the
    // planned one is too tight, as Travel and Composer lead.
    function leaveLine(item) {
        const plan = window.TodayContent?.leaveBy(item, M.minutes(day.time));
        if (!plan) return { caption: '', advice: '', spare: '', take: null };
        const take = plan.take;
        const when = inMinutes(take.leaveIn);
        const caption = `<span class="route-leave">${I('walk')}<strong>${take.leaveIn <= 0 ? 'Leave now' : `Leave by ${take.leave}`}</strong>${when && take.leaveIn > 0 ? `<small>${when}</small>` : ''}</span>`;
        const spare =
            take.spare !== null && take.spare >= 0
                ? ` · ${take.spare} min to spare`
                : '';
        const advice = plan.tight
            ? `<p class="route-advice" role="note">${plan.earlier ? `The ${E(M.travel(item).departure)} in your plan leaves only ${plan.spare} min to spare.` : `<strong>Only ${plan.spare} min to spare.</strong> Allow about ${plan.margin} min for finding the room${item.kind === 'official' ? ' and security checks' : ''}.`}</p>`
            : '';
        return { caption, advice, spare, take: plan.earlier ? take : null };
    }
    function alternativesList(item) {
        const j = item.journey,
            options = j.alternatives || [];
        if (!options.length)
            return `<p>Arrive at ${E(item.location)} by ${item.start}. Check routes from ${E(j.from)}.</p><button class="text-action" data-today-travel="${E(item.id)}">${I('train')}Check connections</button>`;
        const target = M.minutes(item.start);
        return `<ul class="alternative-list">${options
            .map((option) => {
                const late = M.minutes(option.arrive) - target;
                const query = new URLSearchParams({
                    from: j.from,
                    to: item.location,
                    date: day.date,
                    arrive: item.start,
                    activity: item.id,
                    planDate: day.date,
                });
                return `<li><a class="alternative" href="${S.routes.departures}?${query}"><span class="alternative-mode">${I(option.mode)}</span><span class="alternative-copy"><strong>${E(option.label)}</strong><small>${E(option.detail)} · leave ${E(option.leave)}</small></span><span class="alternative-arrival ${late > 0 ? 'is-late' : ''}"><b>${E(option.arrive)}</b><small>${late > 0 ? `${late} min late` : `${-late} min early`}</small></span></a></li>`;
            })
            .join(
                '',
            )}</ul><p class="alternative-note">Sample alternatives · not live. Open one to check it in Travel.</p>`;
    }
    function route(item) {
        const j = item.journey;
        if (!j) return '';
        const travel = M.travel(item),
            id = E(item.id);
        const delayed = !travel.stale && j.delay > 0;
        // When the planned service makes you late, the choices lead instead.
        const recover = delayed && travel.lateMinutes > 0;
        const leave =
            travel.stale || recover
                ? { caption: '', advice: '', spare: '', take: null }
                : leaveLine(item);
        const departs = leave.take?.depart || travel.departure,
            atStop = leave.take?.arrive || travel.stationArrival,
            atAppointment =
                leave.take?.atAppointment || travel.destinationArrival;
        const times = travel.stale
            ? `<div class="route-unknown">${I('train')}<span><strong>Check this journey</strong><small>Recheck for your current plan</small></span></div>`
            : `<div class="route-times">${I('train')}<span class="route-time"><small>Departs</small>${delayed ? `<del>${j.depart}</del>` : ''}<b>${E(departs || '—')}</b></span><span class="route-connector" aria-hidden="true"><i></i></span><span class="route-time"><small>At stop</small>${delayed ? `<del>${j.arrive}</del>` : ''}<b>${E(atStop || '—')}</b></span></div>`;
        return `<button class="route-summary" data-today-route="${id}" aria-label="Journey to ${E(item.title)}: ${travel.stale ? 'status unavailable' : `departs ${departs}, arrives at stop ${atStop}`}" aria-haspopup="dialog">
            ${leave.caption}<span class="route-caption">${delayed ? swatch(`${j.delay} min delay`, 'warning') + swatch('Simulated', 'sample') : ''}<strong>${travel.stale ? `Journey to ${E(item.location)}` : `Line ${E(j.line)} · ${E(j.from)} → ${E(alightStop(j))}`}</strong></span>
            ${times}<span class="route-foot">${travel.destinationArrival ? `${j.walkMinutes} min walk · <strong>${delayed ? 'Est. ' : ''}at appointment ${atAppointment}</strong>${leave.spare}` : 'Arrival at appointment needs checking'}</span>
            ${!delayed && !travel.stale ? '<span class="route-foot">Scheduled · no live status</span>' : ''}
        </button>${leave.advice}${
            travel.stale
                ? `<div class="recovery-row"><button class="outline-action" data-today-travel="${id}">Find a route</button></div>`
                : delayed
                  ? `<div class="recovery-row"><button class="outline-action" data-today-alternatives="${id}" aria-expanded="${recover}" aria-controls="alternatives-${id}">Alternatives${I('down')}</button></div><div class="inline-alternatives" id="alternatives-${id}" ${recover ? '' : 'hidden'}>${recover ? `<p class="alternative-lead">You may arrive ${travel.lateMinutes} min late. These could get you there on time:</p>` : ''}${alternativesList(item)}</div>`
                  : ''
        }`;
    }
    function paperworkLink(item) {
        const record = item.process
            ? day.paperwork.find((entry) => entry.id === item.process)
            : null;
        if (!record) return '';
        const ready = Number.isInteger(record.prepared)
            ? `${record.prepared} of ${record.total} documents ready`
            : 'Open your checklist';
        return `<a class="agenda-paperwork" href="${processURL(record.id)}" aria-label="What to bring for ${E(record.title)}: ${ready}">${I('document')}<span><small>What to bring · ${E(record.title)}</small><strong>${ready}</strong></span>${I('arrow')}</a>`;
    }
    function planLink(link) {
        const query = new URLSearchParams({
            from: link.from.location,
            to: link.to.location,
            date: day.date,
            arrive: link.to.start,
            activity: link.to.id,
            planDate: day.date,
        });
        const route = `${E(link.from.location)} → ${E(link.to.location)}${link.estimate === null ? '' : ' · sample estimate'}`;
        // Earliest workable start, rounded up to the next five minutes.
        const earliest =
            link.estimate === null
                ? null
                : Math.ceil(
                      (M.minutes(link.from.end || link.from.start) +
                          link.estimate) /
                          5,
                  ) * 5;
        const earliestLabel =
            earliest !== null && earliest < 1440
                ? `${String(Math.floor(earliest / 60)).padStart(2, '0')}:${String(earliest % 60).padStart(2, '0')}`
                : null;
        const message = {
            ok: `About ${link.estimate} min · ${link.gap - link.estimate} min to spare`,
            tight: `About ${link.estimate} min · only ${Math.max(0, link.gap - link.estimate)} min to spare`,
            conflict: `Needs about ${link.estimate} min, you have ${Math.max(0, link.gap)}${earliestLabel ? ` · start ${E(link.to.title)} at ${earliestLabel} or later` : ''}`,
            unknown:
                link.gap > 0
                    ? `${link.gap} min between plans · travel time not checked`
                    : 'These plans overlap',
        }[link.status];
        return `<a class="plan-link is-${link.status}" href="${S.routes.departures}?${query}"><span class="plan-link-icon">${I(link.status === 'conflict' ? 'info' : 'train')}</span><span><small>${route}</small><strong>${message}</strong></span><span class="plan-link-action">${link.status === 'ok' ? 'Route' : 'Check route'}</span></a>`;
    }
    function activity(item, index) {
        const travel = M.travel(item),
            id = E(item.id);
        return `<article class="agenda-item ${index ? 'is-secondary' : ''}" data-activity-id="${id}">
            <div class="agenda-identity" data-day-swipe="${id}" aria-expanded="false">
                <div class="identity-main"><time class="appointment-time" datetime="${day.date}T${item.start}">${item.start}</time><h3 class="appointment-title">${E(item.title)}</h3><p class="appointment-location">${E(item.location)}</p><div class="status-row">${swatch(['Pending', 'Proposal'].includes(item.planStatus) ? 'To review' : M.status(item))}${travel?.lateMinutes > 0 ? swatch(`Est. ${travel.lateMinutes} min late`, 'warning') : ''}</div>${paperworkLink(item)}</div>
                <details class="appointment-options"><summary aria-label="Options for ${E(item.title)}">${I('dots')}</summary><div class="appointment-menu"><button data-today-details="${id}">Details</button>${!item.fixed && item.type !== 'event' ? `<button data-today-edit="${id}">Edit plan</button>` : ''}${contentPriority ? `<button data-today-complete="${id}">Mark as done</button>` : ''}<button data-today-reveal="${id}">Remove</button></div></details>
                <div class="delete-reveal" hidden><button data-today-delete="${id}" aria-label="Delete ${E(item.title)} from this sample day">${I('trash')}</button><span>Delete</span></div>
            </div>${route(item)}
        </article>`;
    }
    function agenda() {
        const active = contentPriority?.active || day.agenda;
        const finished = contentPriority?.finished || [];
        const links = window.TodayContent
            ? TodayContent.connections(active)
            : {};
        return `<section class="day-panel ${active.length ? '' : 'has-no-plans'}" aria-labelledby="your-day"><div class="day-heading"><h2 id="your-day">Your day</h2><small>${dayLabel()}${active.length > 1 ? ` · ${active.length} plans` : ''}</small></div>
            ${
                active.length
                    ? `<div class="agenda">${active
                          .map((item, index) => {
                              const link = links[item.id];
                              return (
                                  (link ? planLink(link) : '') +
                                  activity(item, index)
                              );
                          })
                          .join('')}</div>`
                    : `<div class="day-empty"><strong>${contentPriority ? (finished.length ? 'No more plans added for today' : E(contentPriority.emptyLabel)) : 'Your day is open.'}</strong>${contentPriority ? '' : '<p>Start a plan, or explore at your pace.</p>'}</div>`
            }
            ${finished.length ? `<details class="earlier-plans"><summary>Earlier today <small>${finished.length}</small>${I('down')}</summary><div>${finished.map((item) => `<div class="earlier-plan"><button data-today-details="${E(item.id)}"><time>${E(item.start)}</time><span>${E(item.title)}<small>${item.completed ? 'Marked done' : 'Past scheduled time'}</small></span></button>${item.completed ? `<button class="text-action" data-today-uncomplete="${E(item.id)}" aria-label="Restore ${E(item.title)}">Restore</button>` : ''}</div>`).join('')}</div></details>` : ''}
            ${recentCompletion ? `<div class="day-feedback" role="status"><span>${E(recentCompletion.title)} marked done</span><button data-today-uncomplete="${E(recentCompletion.id)}">Undo</button></div>` : ''}
            <div class="day-feedback" role="status" ${day.removed.length ? '' : 'hidden'}>${day.removed.length ? `<span>${E(day.removed.at(-1).item.title)} removed</span><button data-today-undo>Undo</button><button data-today-dismiss-removal aria-label="Dismiss removal notice">${I('close')}</button>` : ''}</div>
            <div class="day-actions"><button class="text-action add-plan" data-today-add>${I('plus')}Add a plan</button></div>
        </section>`;
    }
    const processURL = (id) =>
        `${S.routes.bureaucracy}?scenario=existing&person=self&view=detail&process=${encodeURIComponent(id)}`;
    function paperwork() {
        const focus = M.paperworkFocus(day.paperwork);
        if (day.firstVisit)
            return `<section class="paperwork-panel paperwork-optional" aria-label="Optional settling-in help"><a href="${S.routes.bureaucracy}?person=self">${I('document')}<span><strong>Settling in?</strong><small>Get started when you’re ready</small></span></a></section>`;
        return `<section class="paperwork-panel" aria-labelledby="paperwork-title">
            <div class="section-head"><h2 id="paperwork-title">Paperwork</h2><div class="section-tools"><span class="record-label">Your records · Sample</span></div></div>
            ${
                focus
                    ? `<a class="paperwork-focus" href="${processURL(focus.id)}" aria-label="Continue your ${E(focus.title)} checklist">
                <div class="paperwork-copy"><h3>${E(focus.title)}</h3><p>${E(focus.nextAction || 'Review your checklist')}</p></div>
                <div class="document-progress" style="--progress:${Math.round((focus.prepared / focus.total) * 100)}%" role="img" aria-label="${focus.prepared} of ${focus.total} on your personal list ready"><strong>${focus.prepared}<small> / ${focus.total}</small></strong><span>ready</span></div>
            </a>`
                    : `<a class="paperwork-empty" href="${S.routes.bureaucracy}?person=self"><strong>Your starting point</strong><span>Open your overview</span></a>`
            }
            <div class="paperwork-followups">${day.paperwork
                .filter((record) => record.id !== focus?.id)
                .map(
                    (record) =>
                        `<a class="paperwork-row" href="${processURL(record.id)}">${I('document')}<span><strong>${E(record.title)}</strong><small>Review your checklist</small></span></a>`,
                )
                .join('')}
            <a class="situation-link" href="${S.routes.bureaucracy}?person=self&view=overview&guide=details">Your situation<span>Review details</span></a></div>
        </section>`;
    }
    function cityHelp() {
        if (enrichment) return enrichment.cityHelp();
        return `<section class="city-help" aria-labelledby="city-help-title"><div class="section-head"><h2 id="city-help-title">City help</h2></div>
            <button class="city-forecast" data-today-weather aria-label="Open the sample Cologne forecast"><span class="forecast-place">${E(day.city)}<small>Sample forecast</small></span><span class="forecast-now">${I('sun')}<strong>21°</strong></span><span class="forecast-hours"><span>17:00 <b>20°</b></span><span>20:00 <b>18°</b></span></span></button>
            <div class="city-services"><a href="${S.routes.services}?service=doctors">${I('health')}<span>Find a doctor</span></a><a href="${S.routes.departures}">${I('train')}<span>Plan a journey</span></a></div>
        </section>`;
    }
    function savedIdeas(content = connectedContent()) {
        const items = (
            window.TodayContent
                ? TodayContent.savedForToday(day, content.saved)
                : content.saved
        )
            .slice(-2)
            .reverse();
        if (!items.length) return '';
        return `<section class="saved-ideas" aria-labelledby="saved-ideas-title"><div class="section-head"><h2 id="saved-ideas-title">Saved for later</h2>${content.saved.length ? `<button class="saved-all" data-today-workspace="saved">View all <span>${content.saved.length}</span></button>` : ''}</div>
            ${
                items.length
                    ? items
                          .map((item) => {
                              const key = E(TodayContinuity.key(item));
                              const meta = [
                                  item.type === 'event'
                                      ? shortDate(item.date)
                                      : item.label || 'Place',
                                  item.venue || item.area || item.location,
                              ]
                                  .filter(Boolean)
                                  .join(' · ');
                              return `<div class="saved-idea"><button class="saved-title" data-today-kept="${key}"><span class="saved-monogram" aria-hidden="true">${I(item.type === 'event' ? 'calendar' : 'pin')}</span><span>${E(item.title)}<small>${E(meta || 'Saved idea')}</small></span></button><button class="icon-button" data-today-kept-plan="${key}" aria-label="Plan ${E(item.title)}" title="Add to a plan">${I('calendar-plus')}</button></div>`;
                          })
                          .join('')
                    : `<div class="saved-empty-inline"><span>Keep an idea for another day.</span><a href="${S.routes.events}">Explore events</a></div>`
            }
        </section>`;
    }
    function continuity(content = connectedContent(), columns = false) {
        const c = content.composer;
        const todayDecision = window.TodayContent?.priority(
            day,
            content,
        ).todayDecision;
        const journey = JourneyModel.capture;
        const starters = `<div class="next-starters"><button data-today-add>${I('plus')}Add a plan</button><a href="${S.routes.departures}">${I('train')}Plan a journey</a><a href="${S.routes.services}">${I('grid')}Find a service</a></div>`;
        if (!content.hasContinuation && !columns) return '';
        const composer = c
            ? `<article class="continue-composer"><div class="continue-head"><span class="continue-kind">${I('spark')}Composer</span><span class="continue-state">${E(c.status)}</span></div>
            <div class="continue-summary"><h3>${E(todayDecision ? 'Finish today’s plan' : c.title)}</h3>${c.date ? `<p>${E(shortDate(c.date))}${c.items.length ? ` · ${c.items.length === 1 ? '1 activity' : `${c.items.length} activities`}` : ''}</p>` : ''}</div>
            ${enrichment ? enrichment.excerpt(c) : c.excerpt ? `<p class="continue-excerpt">${E(c.excerpt)}</p>` : ''}
            ${
                c.items.length
                    ? `<ol class="continue-items">${[...c.items]
                          .sort((a, b) =>
                              (a.start || '99:99').localeCompare(
                                  b.start || '99:99',
                              ),
                          )
                          .slice(0, 3)
                          .map(
                              (item) =>
                                  `<li><span class="continue-item-dot" aria-hidden="true"></span><strong>${E(item.title)}</strong>${item.start ? `<time>${E(item.start)}</time>` : '<small>Time to choose</small>'}</li>`,
                          )
                          .join('')}</ol>`
                    : ''
            }
            ${c.items.length > 3 || (c.pending && !enrichment) ? `<p class="continue-more">${c.items.length > 3 ? `+${c.items.length - 3} more in your plan` : ''}${c.pending && !enrichment ? ` ${c.pending} more ${c.pending === 1 ? 'selection' : 'selections'} to review` : ''}</p>` : ''}
            ${enrichment?.decision(c) || ''}
            <div class="continue-actions"><button class="outline-action" data-today-resume>${E(c.action)}</button><button class="text-action" data-today-workspace="history">History</button>${columns ? '<button class="text-action" data-today-workspace="plans">My plans</button>' : ''}</div>
        </article>`
            : '';
        const travel = content.journeys.length
            ? `<section class="continue-travel" aria-labelledby="continue-travel-title"><div class="continue-head"><h3 id="continue-travel-title">Saved journeys</h3><span class="record-label">Sample routes</span></div>
            ${content.journeys
                .slice(0, 2)
                .map(
                    (route) =>
                        `<a class="continue-route" href="${S.routes.departures}?route=${encodeURIComponent(route.id)}&date=${journey.date}&time=${journey.time}"><span class="continue-mode">${I(route.mode === 'transit' ? 'train' : route.mode)}${route.line ? `<b>${E(route.line)}</b>` : ''}</span><span><strong>${E(journey.to)}</strong><small>From ${E(journey.from)} · ${route.duration} min</small></span></a>`,
                )
                .join('')}
            <div class="continue-travel-foot"><small>Captured ${E(shortDate(journey.date))} · not live</small><a href="${S.routes.departures}?view=timetable&stop=${encodeURIComponent(journey.boardingStop.id)}">Check departures</a></div>
        </section>`
            : '';
        const requests = content.requests.length
            ? `<section class="continue-services" aria-labelledby="continue-services-title"><div class="continue-head"><h3 id="continue-services-title">Your requests</h3><span class="record-label">Nothing booked</span></div>
            ${content.requests
                .slice(0, 2)
                .map((request) => {
                    const category = ExpaduModel.categories.find(
                        (item) => item.id === request.category,
                    );
                    const meta = [
                        request.requirements.area,
                        request.requirements.language,
                    ]
                        .filter(Boolean)
                        .join(' · ');
                    return `<a class="continue-request" href="${S.routes.services}?service=${encodeURIComponent(request.category)}" aria-label="Continue request: ${E(request.title)}"><span class="request-symbol">${I(category.icon)}</span><span><strong>${E(request.title)}</strong>${meta ? `<small>${E(meta)}</small>` : ''}<small class="request-progress">Requirements saved</small></span></a>`;
                })
                .join('')}
            ${content.requests.length > 2 ? `<a class="continue-all-requests" href="${S.routes.services}">All ${content.requests.length} requests</a>` : ''}
        </section>`
            : '';
        const support = travel || requests ? travel + requests : '';
        if (columns) {
            return {
                personal: c
                    ? `<section class="column-continuation" aria-label="Pick up where you left off">${composer}</section>`
                    : '',
                support: `<div class="continue-support">${travel || requests ? travel + requests : c ? support : ''}${enrichment?.cityUpdates() || ''}</div>`,
            };
        }
        return `<section class="continuity" aria-labelledby="continuity-title"><div class="continuity-heading"><h2 id="continuity-title">Pick up where you left off</h2><button class="text-action" data-today-workspace="plans">My plans</button></div><div class="continuity-grid ${c ? 'has-composer' : ''} has-support">${composer}<div class="continue-support">${support}${enrichment?.cityUpdates() || ''}</div></div>${!c ? starters : ''}</section>`;
    }
    function welcome() {
        return day.firstVisit
            ? `<section class="today-welcome" aria-labelledby="welcome-title"><div><span class="welcome-symbol">${I('spark')}</span><h2 id="welcome-title">What can we help you with?</h2><p>Make a plan, find your way, or get settled.</p></div><div class="welcome-choices"><button data-today-prompt="day">${I('plus')}Plan my day</button><a href="${S.routes.departures}">${I('train')}Find a route</a><button data-today-prompt="paperwork">${I('document')}Help me settle in</button></div></section>`
            : '';
    }
    // No page title on screen (owner decision, 9 Oct 2026): the navigation
    // already says where you are. Screen readers still get it.
    function greeting() {
        return `<h1 class="sr-only">Today, ${E(day.person)}</h1>`;
    }
    // The day the page shows, beside "Your day".
    const dayLabel = () =>
        `${new Date(`${day.date}T12:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })} · Sample day`;
    function comingUp() {
        const upcoming = day.upcoming
            .filter((item) => item.date > day.date)
            .slice(0, 2);
        if (!upcoming.length) return '';
        return `<details class="coming-up" ${matchMedia('(min-width: 701px)').matches ? 'open' : ''}><summary><span>Coming up <small>${upcoming.length} plans</small></span>${I('down')}</summary><div class="upcoming-plans">${upcoming
            .map((item) => {
                const date = new Date(item.date + 'T12:00:00');
                const label = date.toLocaleDateString('en-GB', {
                    weekday: 'short',
                    day: 'numeric',
                    month: 'short',
                });
                return `<button class="upcoming-plan" data-today-details="${E(item.id)}" aria-label="${E(item.title)}, ${E(label)}, ${item.start}"><span class="upcoming-date" aria-hidden="true"><small>${E(date.toLocaleDateString('en-GB', { month: 'short' }))}</small><b>${date.getDate()}</b></span><span class="upcoming-copy"><strong>${E(item.title)}</strong><small>${E(item.location)}</small></span><time datetime="${item.date}T${item.start}">${item.start}</time></button>`;
            })
            .join(
                '',
            )}</div>${contentPriority?.preparation ? '' : `<button class="plan-tomorrow" data-today-prompt="tomorrow">${I('spark')}Plan tomorrow</button>`}</details>`;
    }
    function deadlineStrip() {
        const record = contentPriority?.urgent;
        if (!record) return '';
        const ready = Number.isInteger(record.prepared)
            ? `${record.prepared} of ${record.total} documents ready`
            : '';
        const summary = [record.deadlineShort || record.nextAction, ready]
            .filter(Boolean)
            .join(' · ');
        return `<a class="deadline-strip" href="${processURL(record.id)}" aria-label="${E(record.deadlineLabel || 'Deadline')} ${E(record.when)}. ${E(record.deadlineAction || '')}. ${E(ready)}. Open your ${E(record.title)} checklist"><span class="deadline-symbol" aria-hidden="true">${I('clock')}</span><span class="deadline-copy"><strong>${E(record.deadlineLabel || 'Deadline')} ${E(record.when)}</strong><small>${E(summary)}</small></span>${I('arrow')}</a>`;
    }
    function preparation() {
        const item = contentPriority?.preparation;
        if (!item) return '';
        return `<section class="preparation-panel" aria-labelledby="preparation-title"><div class="section-head"><span class="record-label">Your reminder · Sample</span><button class="icon-button" data-today-finish-preparation="${E(item.id)}" aria-label="Mark reminder done">${I('check')}</button></div><h2 id="preparation-title">${E(item.title)}</h2><p>For ${E(shortDate(item.targetDate))}</p><button class="outline-action" data-today-prompt="tomorrow">${I('spark')}Review with Composer</button></section>`;
    }
    function discoveryHeading() {
        if (!contentPriority?.freeDay)
            return `<h2 id="discovery-title">A little ${E(day.city)}</h2>`;
        const now = M.minutes(day.time);
        const title =
            now < 720
                ? 'Ideas for this morning'
                : now < 1020
                  ? 'Ideas for this afternoon'
                  : 'Ideas for this evening';
        return `<div class="discovery-title-group"><h2 id="discovery-title">${title}</h2><p>${I('sun')}<span>Nothing in your plan yet · 21° and dry, good for being outside <small>Sample forecast</small></span></p></div>`;
    }
    function render() {
        document.querySelector('.skip-link').href =
            `${location.pathname}${location.search}#main`;
        const content = connectedContent();
        connectedSignature = JSON.stringify(content);
        contentPriority = window.TodayContent?.priority(day, content) || null;
        const featured = (
            contentPriority
                ? contentPriority.lead === 'event'
                : M.priority(day) === 'event'
        )
            ? day.highlight
            : null;
        const eligibleEvents = contentPriority?.events || day.events;
        const nearbyEventId = window.TodayContent?.around(day, content.saved)
            ?.item.id;
        const discoveries = eligibleEvents.filter(
            (item) =>
                item.id !== featured?.id && (!enrichment || !item.compact),
        );
        main.classList.toggle(
            'quiet-layout',
            !(contentPriority?.active || day.agenda).length,
        );
        main.classList.toggle('highlight-layout', !!featured);
        main.dataset.priority = contentPriority?.lead || 'agenda';
        renderedContinuation = columnFlow ? continuity(content, true) : null;
        main.innerHTML = `${greeting()}${deadlineStrip()}${welcome()}
            ${featured ? `<section class="event-spotlight" aria-label="Today’s event highlight">${eventCard(featured, true)}</section>` : ''}
            <div class="today-workspace"><div class="daily-canvas"><div class="day-area">${preparation()}${agenda()}${enrichment ? `<div id="around-plan-slot">${enrichment.around(content)}</div>` : ''}${savedIdeas(content)}${comingUp()}${columnFlow ? `<div id="personal-continuity-slot">${renderedContinuation.personal}</div>` : ''}</div>
                <div class="next-area"><div class="practical-surface">${enrichment ? `<div class="practical-personal">${paperwork()}${enrichment.services()}</div>` : paperwork()}${cityHelp()}</div>
                    <section class="discovery" aria-labelledby="discovery-title"><div class="discovery-heading">${discoveryHeading()}<a href="${S.routes.events}">All events</a></div>${discoveries.length ? `<div class="event-rail" aria-label="Sample events. Swipe to browse." tabindex="0">${discoveries.map((item) => eventCard(item)).join('')}</div>` : ''}${enrichment?.compactEvents(eligibleEvents.filter((item) => item.compact && item.id !== nearbyEventId)) || ''}${!eligibleEvents.length ? '<p class="discovery-empty">More dates and ideas in Events.</p>' : ''}</section>
                    ${columnFlow ? `<div id="support-continuity-slot">${renderedContinuation.support}</div>` : ''}
                </div>
            </div>${columnFlow ? '' : `<div id="continuity-slot">${continuity(content)}</div>`}</div>`;
        document.querySelector('.preview-foot')?.prepend(reviewControls);
        reviewControls.hidden = false;
        enrichment?.arrange(contentPriority);
        bindRail();
    }
    function syncSaves() {
        if (syncingDay) return;
        if (syncDay()) {
            persist();
            render();
        }
        document.querySelectorAll('[data-today-save]').forEach((button) => {
            const item = eventById(button.dataset.todaySave),
                yes = saved(item);
            button.setAttribute('aria-pressed', String(yes));
            button.setAttribute(
                'aria-label',
                `${yes ? 'Unsave' : 'Save'} ${item.title}`,
            );
        });
        syncSavedSheet();
        refreshConnectedContent();
    }
    function syncSavedSheet() {
        const records = [
            detailRecord,
            ...day.events,
            ...connectedContent().saved,
        ].filter(Boolean);
        document.querySelectorAll('[data-save-item]').forEach((button) => {
            const item = records.find(
                (record) => record.id === button.dataset.saveItem,
            );
            if (!item) return;
            const yes = saved(item);
            button.setAttribute('aria-pressed', String(yes));
            button.setAttribute(
                'aria-label',
                `${yes ? 'Unsave' : 'Save'} ${item.title}`,
            );
            const label = button.querySelector('[data-save-label]');
            if (label) label.textContent = yes ? 'Saved' : 'Save for later';
        });
    }
    function refreshConnectedContent() {
        const content = connectedContent();
        const signature = JSON.stringify(content);
        if (signature === connectedSignature) return;
        connectedSignature = signature;
        const savedSection = main.querySelector('.saved-ideas');
        const slot = main.querySelector('#continuity-slot');
        if (savedSection) {
            const markup = savedIdeas(content);
            if (savedSection.outerHTML !== markup)
                savedSection.outerHTML = markup;
        }
        if (slot) {
            const markup = continuity(content);
            if (slot.innerHTML !== markup) slot.innerHTML = markup;
        }
        if (columnFlow) {
            const sections = continuity(content, true);
            for (const key of ['personal', 'support']) {
                const columnSlot = main.querySelector(
                    `#${key}-continuity-slot`,
                );
                if (columnSlot && sections[key] !== renderedContinuation?.[key])
                    columnSlot.innerHTML = sections[key];
            }
            renderedContinuation = sections;
        }
        enrichment?.refresh(content);
    }
    function toggleSave(item) {
        S.update((state) => ExpaduHandoffs.bookmark(state, item));
        U.toast(saved(item) ? 'Saved for later' : 'Removed from saved');
        syncSaves();
    }
    function closeMenus() {
        main.querySelectorAll('.appointment-options[open]').forEach(
            (menu) => (menu.open = false),
        );
    }
    function reveal(id, focus = false) {
        if (revealed && revealed !== id) hideReveal();
        const card = main.querySelector(`[data-day-swipe="${CSS.escape(id)}"]`);
        if (!card) return;
        revealed = id;
        closeMenus();
        card.classList.add('is-revealed');
        card.setAttribute('aria-expanded', 'true');
        card.querySelector('.delete-reveal').hidden = false;
        if (focus)
            card.querySelector('[data-today-delete]').focus({
                preventScroll: true,
            });
    }
    function hideReveal(focus = false) {
        const card = main.querySelector(
            `[data-day-swipe="${CSS.escape(revealed || '')}"]`,
        );
        if (card) {
            card.classList.remove('is-revealed');
            card.setAttribute('aria-expanded', 'false');
            card.querySelector('.delete-reveal').hidden = true;
            if (focus)
                card.querySelector('summary').focus({ preventScroll: true });
        }
        revealed = null;
    }
    ExpaduDaySwipe.bind(main, {
        begin: (card) => card,
        move: (card, distance, open) =>
            (card.querySelector('.identity-main').style.transform =
                `translateX(${-Math.min(18, distance / 5) * (open ? -1 : 1)}px)`),
        end: (card, commit, _distance, open) => {
            card.querySelector('.identity-main').style.transform = '';
            if (commit) open ? hideReveal() : reveal(card.dataset.daySwipe);
        },
        reveal: (card) => reveal(card.dataset.daySwipe),
    });
    main.addEventListener('dblclick', (event) => {
        if (event.target.closest('button,a,summary')) return;
        const card = event.target.closest('[data-day-swipe]');
        if (card) {
            event.preventDefault();
            reveal(card.dataset.daySwipe);
        }
    });
    document.addEventListener('pointerdown', (event) => {
        if (
            revealed &&
            !event.target.closest(`[data-day-swipe="${CSS.escape(revealed)}"]`)
        )
            hideReveal();
        main.querySelectorAll('.appointment-options[open]').forEach((menu) => {
            if (!menu.contains(event.target)) menu.open = false;
        });
    });
    main.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && revealed) {
            event.preventDefault();
            hideReveal(true);
        }
    });

    function focusComposer() {
        document
            .querySelector('#composer-tray')
            .dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
        document.querySelector('#request').focus({ preventScroll: true });
    }
    function openSuggestion(intent) {
        const prompt = M.suggestion(day, intent);
        if (!prompt) return;
        const field = document.querySelector('#request');
        S.update((state) => {
            Object.assign(
                state,
                M.withSuggestion({ ...state, prompt: field.value }, prompt),
            );
            state.todaySuggestion = prompt;
        });
        field.value = S.read().prompt;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        focusComposer();
    }
    function openComposer(item) {
        const field = document.querySelector('#request');
        // This adds context, never a scheduled selection, and retains existing text.
        const typed = field.value.trim();
        S.update((state) => {
            Object.assign(
                state,
                M.withContext(
                    { ...state, prompt: field.value },
                    item,
                    day.date,
                ),
            );
            if (!typed) state.todaySuggestion = state.prompt;
        });
        field.value = S.read().prompt;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        pendingContext = item.id;
        let note = document.querySelector('.composer-context-note');
        if (!note) {
            note = document.createElement('div');
            note.className = 'composer-context-note';
            document.querySelector('#compose-form').before(note);
        }
        note.innerHTML = `<span>${E(item.title)} · ${item.start}<br>${E(item.journey?.from || '')} → ${E(item.location)} · Sample</span><button data-clear-today-context aria-label="Remove this journey context">${I('close')}</button>`;
        // Focusing uses the existing Composer expansion and preserves its draft.
        focusComposer();
    }
    // Details and short tasks open as a sheet on phones and a compact dialog on
    // larger screens (guide: Sheets & dialogs).
    const sheet = (html) => `<div class="today-sheet">${html}</div>`;
    function journeySteps(item, plan) {
        const j = item.journey,
            delayed = j.delay > 0;
        // The tram to take, when it isn't the planned one.
        const lead = plan?.tight && plan.earlier ? plan.earlier : null;
        const t = lead
            ? {
                  departure: lead.depart,
                  stationArrival: lead.arrive,
                  destinationArrival: lead.atAppointment,
              }
            : M.travel(item);
        const step = (icon, title, detail, time) =>
            `<li class="is-${icon}"><span class="sheet-step-icon">${I(icon)}</span><span class="sheet-step-copy"><strong>${title}</strong>${detail ? `<small>${detail}</small>` : ''}</span>${time ? `<span class="sheet-step-time">${time}</span>` : ''}</li>`;
        return `<ol class="sheet-steps" aria-label="Journey, step by step">${[
            j.accessWalkMinutes !== null && j.accessWalkMinutes !== undefined
                ? step(
                      'walk',
                      `Walk to ${E(j.from)}`,
                      `${j.accessWalkMinutes} min`,
                      plan ? (lead || plan).leave : '',
                  )
                : '',
            step(
                'train',
                `Line ${E(j.line)} from ${E(j.from)}`,
                delayed
                    ? `${j.delay} min late · simulated`
                    : 'Scheduled · no live status',
                `${delayed ? `<del>${E(j.depart)}</del> ` : ''}${E(t.departure)}`,
            ),
            step('pin', `Get off at ${E(alightStop(j))}`, '', E(t.stationArrival)),
            j.walkMinutes !== null && j.walkMinutes !== undefined
                ? step(
                      'walk',
                      `Walk to ${E(item.title)}`,
                      `${j.walkMinutes} min · ${E(item.location)}`,
                      t.destinationArrival ? `≈ ${t.destinationArrival}` : '',
                  )
                : '',
        ].join('')}</ol>`;
    }
    function routeDetails(item) {
        const j = item.journey,
            t = M.travel(item),
            id = E(item.id);
        const plan = t.stale
            ? null
            : window.TodayContent?.leaveBy(item, M.minutes(day.time));
        const delayed = !t.stale && j.delay > 0;
        const late = delayed && t.lateMinutes > 0;
        const take = plan?.take;
        const timing = take
            ? [
                  take.leaveIn > 0 ? inMinutes(take.leaveIn) : '',
                  take.spare !== null && take.spare >= 0
                      ? `${take.spare} min to spare before ${item.start}`
                      : '',
              ]
                  .filter(Boolean)
                  .join(' · ')
            : '';
        const lead = t.stale
            ? `<div class="sheet-lead is-unknown"><span>Journey</span><strong>Needs checking</strong><small>These sample times no longer match your plan.</small></div>`
            : late
              ? `<div class="sheet-lead is-late"><span>On this train</span><strong>${t.lateMinutes} min late</strong><small>Estimated at ${E(item.title)} ${t.destinationArrival} · it starts ${item.start}</small></div>`
              : plan
                ? `<div class="sheet-lead"><span>Leave by</span><strong>${take.leaveIn <= 0 ? 'Now' : take.leave}</strong>${timing ? `<small>${timing}</small>` : ''}${plan.tight && plan.earlier ? `<p class="sheet-lead-tip">The ${E(t.departure)} in your plan leaves only ${plan.spare} min to spare.</p>` : ''}</div>`
                : '';
        let fallback = '';
        if (delayed && (j.alternatives || []).length)
            fallback = `<section class="sheet-section"><h3>${late ? 'Get there on time' : 'Other ways to go'}</h3>${alternativesList(item)}</section>`;
        else if (!t.stale)
            fallback = `<section class="sheet-section"><h3>If you miss it</h3><p>Check the next Line ${E(j.line)} or another route in Travel.</p></section>`;
        U.open(
            'Your journey',
            sheet(
                `<p class="detail-intro">Line ${E(j.line)} · ${E(j.from)} → ${E(alightStop(j))} · ${E(shortDate(day.date))}</p>${lead}${t.stale ? '' : journeySteps(item, plan)}${fallback}<p class="detail-note">Sample times. Live service and the walking route have not been checked.</p><div class="detail-actions"><button class="outline-action" data-today-travel="${id}">${I('route')}Open in Travel</button><button class="text-action" data-today-context="${id}">${I('spark')}Ask Composer</button></div>`,
            ),
        );
    }
    function details(item) {
        const id = E(item.id),
            onPage = day.agenda.some((entry) => entry.id === item.id),
            editable = onPage && !item.fixed && item.type !== 'event';
        const date = new Date(
            `${item.date || day.date}T12:00:00`,
        ).toLocaleDateString('en-GB', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
        });
        const t = onPage && item.journey ? M.travel(item) : null;
        const plan =
            t && !t.stale
                ? window.TodayContent?.leaveBy(item, M.minutes(day.time))
                : null;
        const row = (data, icon, title, detail) =>
            `<button class="sheet-row" ${data}="${id}">${I(icon)}<span><strong>${title}</strong><small>${detail}</small></span>${I('arrow')}</button>`;
        const getting = !onPage
            ? ''
            : !t
              ? row(
                    'data-today-travel',
                    'route',
                    'Plan your journey',
                    'No route in your plan yet',
                )
              : t.stale
                ? row(
                      'data-today-route',
                      'train',
                      'Journey needs checking',
                      `${E(item.journey.from)} → ${E(item.location)}`,
                  )
                : t.lateMinutes > 0
                  ? row(
                        'data-today-route',
                        'train',
                        `About ${t.lateMinutes} min late`,
                        `Line ${E(item.journey.line)} is delayed · see ways to get there on time`,
                    )
                  : row(
                      'data-today-route',
                      'train',
                      plan
                          ? plan.take.leaveIn <= 0
                              ? 'Leave now'
                              : `Leave by ${plan.take.leave}`
                          : `Line ${E(item.journey.line)}`,
                      `Line ${E(item.journey.line)} from ${E(item.journey.from)} · ${(plan?.take.atAppointment || t.destinationArrival) ? `there ≈ ${plan?.take.atAppointment || t.destinationArrival}` : 'arrival needs checking'}`,
                  );
        const status = ['Pending', 'Proposal'].includes(item.planStatus)
            ? 'To review'
            : M.status(item);
        U.open(
            item.title,
            sheet(
                `<p class="detail-intro">${E(date)} · ${item.start}–${item.end} · ${E(item.location)}</p><div class="status-row">${swatch(status)}</div>${paperworkLink(item)}${getting}<dl class="detail-grid"><div><dt>Booking</dt><dd>${M.status(item) === 'Booking recorded' ? 'Recorded by you' : 'Not recorded in Expadu'}</dd></div></dl><p class="detail-note">Keeping an appointment in your plan does not book or confirm it with the organiser.</p>${onPage ? `<div class="detail-actions"><button class="outline-action" data-today-travel="${id}">${I('route')}Directions</button>${editable ? `<button class="text-action" data-today-edit="${id}">${I('pencil')}Edit</button>` : ''}<button class="text-action" data-today-reveal="${id}">${I('trash')}Remove</button></div>` : ''}`,
            ),
        );
    }
    (function sheetSwipe() {
        const dialog = document.querySelector('#shared-dialog');
        let drag = null;
        const phoneSheet = () =>
            dialog.querySelector('.today-sheet') &&
            matchMedia('(max-width: 700px)').matches;
        dialog.addEventListener('pointerdown', (event) => {
            if (
                !phoneSheet() ||
                !event.target.closest('.dialog-head') ||
                event.target.closest('button')
            )
                return;
            drag = { id: event.pointerId, y: event.clientY, at: event.timeStamp, dy: 0 };
            dialog.setPointerCapture(event.pointerId);
        });
        dialog.addEventListener('pointermove', (event) => {
            if (drag?.id !== event.pointerId) return;
            drag.dy = Math.max(0, event.clientY - drag.y);
            dialog.style.transform = `translateY(${drag.dy}px)`;
        });
        const release = (event) => {
            if (drag?.id !== event.pointerId) return;
            const { dy, at } = drag;
            drag = null;
            const fast = dy / Math.max(1, event.timeStamp - at) > 0.5;
            if (dy > 96 || (fast && dy > 32)) {
                U.close();
                return;
            }
            const from = dialog.style.transform;
            dialog.style.transform = '';
            if (dy) ExpaduMotion?.run(dialog, [{ transform: from }, { transform: 'translateY(0)' }], { duration: 200 });
        };
        dialog.addEventListener('pointerup', release);
        dialog.addEventListener('pointercancel', release);
    })();
    function openAdd(item = null) {
        editingPlan = item
            ? structuredClone(
                  ExpaduHandoffs.dayItems(S.read(), S.studio(), day.date).find(
                      (entry) => entry.id === item.id,
                  ),
              )
            : null;
        U.open(
            item ? 'Edit plan' : 'Add a plan',
            sheet(`<form id="today-add-form"><p class="detail-intro">${E(shortDate(day.date))} · Sample day</p><label class="form-field">What’s the plan?<input name="title" value="${E(item?.title || '')}" maxlength="80" required placeholder="Appointment, lunch, a little time outside" autocomplete="off"></label><label class="form-field">Where? <small>Optional</small><input name="location" value="${E(item?.location || '')}" maxlength="100" placeholder="Place or neighbourhood" autocomplete="off"></label><div class="selection-times"><label class="form-field">From<input name="start" data-time-input role="combobox" aria-haspopup="dialog" aria-expanded="false" aria-controls="time-options" inputmode="numeric" maxlength="5" required value="${item?.start || '15:00'}" aria-label="Start time"></label><span aria-hidden="true">–</span><label class="form-field">Until<input name="end" data-time-input role="combobox" aria-haspopup="dialog" aria-expanded="false" aria-controls="time-options" inputmode="numeric" maxlength="5" required value="${item?.end || '16:00'}" aria-label="End time"></label></div><p class="detail-note">A personal plan, not a booking.</p><p id="add-error" role="alert" hidden></p><div class="detail-actions"><button class="outline-action" type="submit">${I(item ? 'check' : 'plus')}${item ? 'Save changes' : 'Add to this day'}</button></div></form>`),
        );
    }
    document.addEventListener('submit', (event) => {
        if (event.target.id !== 'today-add-form') return;
        event.preventDefault();
        const values = Object.fromEntries(new FormData(event.target));
        values.start = ExpaduTime.normalizeTime(values.start) || values.start;
        values.end = ExpaduTime.normalizeTime(values.end) || values.end;
        if (
            !values.title.trim() ||
            M.minutes(values.start) === null ||
            M.minutes(values.end) === null ||
            values.start >= values.end
        ) {
            const error = document.querySelector('#add-error');
            error.hidden = false;
            error.textContent = 'Add a title and an end time after the start.';
            return;
        }
        try {
            changeDay((shared, workspace) => {
                if (editingPlan) {
                    ExpaduDayActions.editPersonal(
                        shared,
                        workspace,
                        day.date,
                        editingPlan,
                        values,
                    );
                } else {
                    const added = {
                        id: `today-review-${Date.now()}`,
                        type: 'appointment',
                        personal: true,
                        sample: true,
                        title: values.title.trim(),
                        location: values.location.trim() || 'Location not set',
                        start: values.start,
                        end: values.end,
                        date: day.date,
                    };
                    const overlap = ExpaduHandoffs.dayItems(
                        shared,
                        workspace,
                        day.date,
                    ).find(
                        (entry) =>
                            added.start < entry.end && entry.start < added.end,
                    );
                    if (overlap)
                        throw Error(
                            `This overlaps ${overlap.title}. Choose another time.`,
                        );
                    const plan =
                        workspace?.proposal?.date === day.date
                            ? workspace.proposal
                            : workspace?.plans?.find(
                                  (entry) => entry.date === day.date,
                              );
                    if (plan) {
                        plan.items.push(added);
                        plan.items.sort((a, b) =>
                            a.start.localeCompare(b.start),
                        );
                        if (plan !== workspace.proposal)
                            plan.revision = (plan.revision || 0) + 1;
                    } else shared.reviewDay.agenda.push(added);
                }
            });
        } catch (reason) {
            const error = document.querySelector('#add-error');
            error.hidden = false;
            error.textContent = reason.message;
            return;
        }
        editingPlan = null;
        U.close();
        render();
    });
    document.addEventListener('click', (event) => {
        const target = event.target.closest('button,a');
        if (!target) return;
        const data = target.dataset;
        if (data.todayComplete || data.todayUncomplete) {
            const plan = day.agenda.find(
                (entry) =>
                    entry.id === (data.todayComplete || data.todayUncomplete),
            );
            if (plan) {
                setDone(plan, !!data.todayComplete);
                plan.completed = !!data.todayComplete;
                recentCompletion = plan.completed ? plan : null;
                persist();
                render();
                const focus =
                    main.querySelector(
                        plan.completed
                            ? '.day-feedback [data-today-uncomplete]'
                            : `[data-activity-id="${CSS.escape(plan.id)}"] summary`,
                    ) || main;
                focus?.focus({ preventScroll: true });
            }
        }
        if (data.todayFinishPreparation) {
            const reminder = day.preparations?.find(
                (entry) => entry.id === data.todayFinishPreparation,
            );
            if (reminder) {
                reminder.status = 'done';
                persist();
                render();
                U.toast('Reminder marked done');
                main.querySelector('[data-today-add]')?.focus({
                    preventScroll: true,
                });
            }
        }
        const id =
            data.todayRoute ||
            data.todayDetails ||
            data.todayEdit ||
            data.todayAlternatives ||
            data.todayContext ||
            data.todayTravel ||
            data.todayReveal ||
            data.todayDelete;
        const item = [...day.agenda, ...day.upcoming].find((x) => x.id === id);
        if (data.todayPrompt) openSuggestion(data.todayPrompt);
        if (data.todayEvent) {
            detailRecord = eventById(data.todayEvent);
            ExpaduEventSheet.open(detailRecord, 'about');
        }
        if (data.todayPlan) {
            detailRecord = eventById(data.todayPlan);
            ExpaduEventSheet.open(detailRecord, 'plan');
        }
        if (data.todaySave) toggleSave(eventById(data.todaySave));
        if (
            data.saveItem &&
            // The shared planning handler owns the records it knows.
            !(
                window.ExpaduPlanning?.find?.(data.saveItem) ||
                [...ExpaduModel.places, ...ExpaduModel.events].some(
                    (record) => record.id === data.saveItem,
                )
            )
        ) {
            const record = [
                detailRecord,
                ...day.events,
                ...connectedContent().saved,
            ].find((item) => item?.id === data.saveItem);
            if (record) toggleSave(record);
        }
        if (data.todayRoute && item) routeDetails(item);
        if (data.todayDetails && item) {
            closeMenus();
            details(item);
        }
        if (data.todayEdit && item) {
            closeMenus();
            openAdd(item);
        }
        if (data.todayContext && item) {
            if (document.querySelector('#shared-dialog').open) {
                document
                    .querySelector('#shared-dialog')
                    .addEventListener('close', () => openComposer(item), {
                        once: true,
                    });
                U.close();
            } else openComposer(item);
        }
        if (data.todayAlternatives && item) {
            const panel = main.querySelector(
                `#alternatives-${CSS.escape(item.id)}`,
            );
            const trigger = event.target.closest('[data-today-alternatives]');
            if (panel && trigger) {
                const open = panel.hidden;
                panel.hidden = !open;
                trigger.setAttribute('aria-expanded', String(open));
                if (open)
                    panel
                        .querySelector('a, button')
                        ?.focus({ preventScroll: true });
            }
        }
        if (data.todayTravel && item) {
            persist();
            S.go('departures', {
                from: item.journey?.from,
                to: item.location,
                toId: item.destinationId,
                toLat: item.lat,
                toLng: item.lng,
                date: day.date,
                arrive: item.start,
                activity: item.id,
                planDate: day.date,
            });
        }
        if (data.todayReveal) {
            const dialog = document.querySelector('#shared-dialog');
            if (dialog.open && target.closest('#shared-dialog')) {
                dialog.addEventListener('close', () => reveal(id, true), {
                    once: true,
                });
                U.close();
            } else reveal(id, true);
        }
        if (data.todayDelete && item && revealed === id) {
            try {
                const receipt = changeDay((shared, workspace) =>
                    ExpaduDayActions.remove(
                        shared,
                        workspace,
                        day.date,
                        ExpaduHandoffs.dayItems(
                            shared,
                            workspace,
                            day.date,
                        ).find((entry) => entry.id === id),
                    ),
                );
                day.removed.push({ item, receipt });
            } catch (reason) {
                U.toast(reason.message);
                return;
            }
            revealed = null;
            persist();
            render();
            main.querySelector('[data-today-undo]').focus({
                preventScroll: true,
            });
        }
        if ('todayUndo' in data) {
            const removed = day.removed.at(-1);
            let restored;
            try {
                if (removed?.receipt) {
                    changeDay((shared, workspace) =>
                        ExpaduDayActions.undo(
                            shared,
                            workspace,
                            removed.receipt,
                        ),
                    );
                    day.removed.pop();
                    restored = removed.item.id;
                } else restored = M.undo(day);
            } catch (reason) {
                U.toast(reason.message);
                return;
            }
            persist();
            render();
            main.querySelector(
                `[data-day-swipe="${CSS.escape(restored)}"] summary`,
            )?.focus({ preventScroll: true });
        }
        if ('todayDismissRemoval' in data) {
            day.removed = [];
            persist();
            render();
            main.querySelector('[data-today-add]').focus({
                preventScroll: true,
            });
        }
        if ('todayAdd' in data) openAdd();
        if ('todayWeather' in data)
            U.open(
                'Cologne forecast',
                sheet(
                    `<p class="detail-intro">Sample forecast · not live</p><dl class="detail-grid"><div><dt>Now</dt><dd>${I('sun')}21°</dd></div><div><dt>17:00</dt><dd>20°</dd></div><div><dt>20:00</dt><dd>18°</dd></div></dl>`,
                ),
            );
        if (data.todayKept || data.todayKeptPlan) {
            const record = connectedContent().saved.find(
                (item) =>
                    TodayContinuity.key(item) ===
                    (data.todayKept || data.todayKeptPlan),
            );
            if (record) {
                detailRecord = record;
                const section = data.todayKeptPlan ? 'plan' : 'about';
                if (record.type === 'event')
                    ExpaduEventSheet.open(record, section);
                else ExpaduPlaceSheet.open(record, section);
            }
        }
        if (data.todayWorkspace) {
            persist();
            S.go('composer', { panel: data.todayWorkspace });
        }
        if ('todayResume' in data) {
            const composer = connectedContent().composer;
            persist();
            S.go(
                'composer',
                composer?.kind === 'proposal' ? { date: composer.date } : {},
            );
        }
        if ('todayAccount' in data)
            U.open(
                'Your account',
                sheet(
                    '<p class="detail-intro">Anar · Preview profile</p><p>Account settings are available in the main app.</p>',
                ),
            );
        if ('clearTodayContext' in data) {
            S.update((state) => {
                state.draft = state.draft.filter(
                    (x) => x.id !== `today-context-${pendingContext}`,
                );
            });
            document.querySelector('.composer-context-note')?.remove();
            pendingContext = null;
            document.querySelector('#request').focus();
        }
        syncSavedSheet();
    });
    function bindRail() {
        const rail = main.querySelector('.event-rail');
        if (!rail) return;
        let drag,
            swallow = false;
        rail.addEventListener('dragstart', (event) => event.preventDefault());
        rail.addEventListener('pointerdown', (event) => {
            if (
                event.pointerType === 'touch' ||
                event.button !== 0 ||
                event.target.closest('.event-save,.event-plan')
            )
                return;
            drag = {
                x: event.clientX,
                y: event.clientY,
                left: rail.scrollLeft,
                id: event.pointerId,
                moved: false,
            };
            swallow = false;
        });
        rail.addEventListener('pointermove', (event) => {
            if (!drag) return;
            const dx = event.clientX - drag.x;
            if (!drag.moved && Math.abs(dx) < 8) return;
            if (Math.abs(event.clientY - drag.y) > Math.abs(dx)) {
                drag = null;
                return;
            }
            drag.moved = true;
            rail.setPointerCapture(event.pointerId);
            rail.scrollLeft = drag.left - dx;
            event.preventDefault();
        });
        const end = () => {
            if (drag?.moved) {
                swallow = true;
                if (rail.hasPointerCapture(drag.id))
                    rail.releasePointerCapture(drag.id);
            }
            drag = null;
        };
        rail.addEventListener('pointerup', end);
        rail.addEventListener('pointercancel', end);
        rail.addEventListener(
            'click',
            (event) => {
                if (swallow) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    swallow = false;
                }
            },
            true,
        );
        rail.addEventListener('keydown', (event) => {
            if (
                event.target !== rail ||
                !['ArrowLeft', 'ArrowRight'].includes(event.key)
            )
                return;
            event.preventDefault();
            rail.scrollBy({
                left:
                    (event.key === 'ArrowRight' ? 1 : -1) *
                    rail.clientWidth *
                    0.8,
                behavior: matchMedia('(prefers-reduced-motion:reduce)').matches
                    ? 'instant'
                    : 'smooth',
            });
        });
    }
    picker.addEventListener('change', () => {
        persist();
        view = picker.value;
        day = load(view);
        initializeDay(true);
        revealed = null;
        recentCompletion = null;
        const url = new URL(location.href);
        url.searchParams.set('view', view);
        history.pushState({}, '', url);
        render();
        scroller.scrollTop = 0;
    });
    window.addEventListener('popstate', () => {
        view = new URLSearchParams(location.search).get('view') || 'everyday';
        if (!allowed.includes(view)) view = 'everyday';
        day = load(view);
        initializeDay(true);
        picker.value = view;
        render();
    });
    window.addEventListener('pagehide', persist);
    window.addEventListener('expadu:change', syncSaves);
    window.addEventListener('pageshow', syncSaves);
    window.addEventListener('storage', (event) => {
        if (
            event.key === null ||
            [
                'expadu-connected-design-v1',
                'expadu-composer-studio-v1',
                'expadu-departures-next-saved',
            ].includes(event.key)
        )
            syncSaves();
    });
    render();
    const tray = document.querySelector('#composer-tray');
    new ResizeObserver(() => {
        // Only the expanded Composer needs a reserved strip. The small launcher
        // floats over the page, with local clearance around the footer actions.
        const height = tray.classList.contains('is-active')
            ? tray.getBoundingClientRect().height + 32
            : 0;
        document.documentElement.style.setProperty(
            '--composer-space',
            `${Math.min(height, innerHeight * 0.46)}px`,
        );
    }).observe(tray);
    try {
        const previous = JSON.parse(sessionStorage.getItem(sessionKey(view)));
        if (previous?.scroll)
            requestAnimationFrame(() => (scroller.scrollTop = previous.scroll));
    } catch {}
})();
