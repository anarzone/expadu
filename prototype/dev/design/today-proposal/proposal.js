/* A design proposal with isolated, in-memory interactions. No account or API access. */
(() => {
    const params = new URLSearchParams(location.search);
    const view = ['everyday', 'event', 'delay'].includes(params.get('view'))
        ? params.get('view')
        : 'everyday';
    const empty = params.get('state') === 'empty';
    const eventDay = view === 'event';
    const saved = new Set();
    let composerDraft = 'Help me leave a little room this evening.';
    let context = {
        name: 'Today · 30 September',
        request: 'Review my plans for today.',
    };
    let removed = false;
    let opener;
    let feedbackTimer;
    const icon = (name) =>
        name === 'calendar-plus'
            ? '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 2v6m10-6v6M3 11h18M12 14v5m-2.5-2.5h5"/></svg>'
            : name === 'chevron'
              ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>'
              : window.ExpaduIcon(name);
    const events = [
        {
            id: 'art',
            title: 'Kunst zum<br><em>Mit-Machen</em>',
            plain: 'Kunst zum Mit-Machen',
            category: 'Arts & culture',
            symbol: 'pencil',
            image: 'kunst-machen.webp',
            time: '16:00–17:30',
            venue: 'Mülheim',
            admission: 'Free',
            className: 'art-event',
        },
        {
            id: 'film',
            title: 'A film.<br>A river.<br>A good evening.',
            plain: 'Open-air film evening',
            category: 'Film',
            symbol: 'film',
            image: 'film.webp',
            time: '19:30–21:00',
            venue: 'Rheinpark',
            admission: 'Admission not listed',
            className: 'film-event',
        },
        {
            id: 'forest',
            title: 'Into the<br>green.',
            plain: 'An evening in the forest',
            category: 'Outdoors',
            symbol: 'leaf',
            image: 'forest.webp',
            time: '17:00–18:30',
            venue: 'Waldlabor',
            admission: 'Free',
            className: 'forest-event',
        },
    ];
    const sampleNote = '<span class="sample-label">Sample day</span>';
    function logo() {
        return `<a class="brand" href="./" aria-label="Expadu home"><svg viewBox="13 22 74 60" aria-hidden="true" class="logo-mark"><g transform="translate(2.45 2.05)"><circle cx="20.5" cy="50" r="6" fill="#05badd"/><rect x="30" y="24" width="13" height="52" rx="6" fill="currentColor"/><rect x="46" y="24" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-7 46 30)"/><rect x="46" y="43.5" width="25" height="13" rx="6" fill="#ff3902" transform="rotate(-4 46 50)"/><rect x="46" y="63" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-2 46 69)"/></g></svg><span>Expadu</span></a>`;
    }
    function header() {
        const nav = [
            ['today', 'Today', 'home', './'],
            ['events', 'Events', 'calendar', '../events-next/'],
            ['bureaucracy', 'Bureaucracy', 'document', '../bureaucracy-next/'],
            ['departures', 'Departures', 'train', '../departures-next/'],
            ['services', 'Services', 'grid', '../services-next/'],
        ];
        return `<header class="app-header">${logo()}<button class="city-control" data-open="city">${icon('pin')}<span>Cologne</span>${icon('down')}</button><div class="header-actions"><button class="saved-control" data-open="saved">${icon('bookmark')}<span>Saved</span></button><button class="composer-launch" data-composer="day">${icon('spark')}<span>Composer</span></button><button class="account-control" data-open="account" aria-label="Your account">A</button></div></header><nav class="app-nav" aria-label="Main navigation">${nav.map(([id, label, symbol, href]) => `<a href="${href}" class="nav-${id}" ${id === 'today' ? 'aria-current="page"' : ''}>${icon(symbol)}<span>${label}</span></a>`).join('')}</nav>`;
    }
    function plans() {
        const title =
            empty || removed
                ? 'Nothing scheduled yet'
                : eventDay
                  ? 'Dinner with Lea'
                  : 'German class';
        const time = eventDay ? '18:30' : '09:30';
        return `<div class="overview-role plans-role"><div class="role-heading">${icon('calendar')}<h3>Plans</h3></div><div class="activity-reveal" id="activity-reveal"><div class="plan-body" id="plan-body"><div class="plan-top"><span class="plan-time">${empty || removed ? 'A little room for today' : time}</span>${empty || removed ? '' : '<span class="confirmed">' + icon('check') + 'Confirmed</span>'}</div><strong class="plan-name">${title}</strong><div class="plan-location">${empty || removed ? 'Start with something you want to do.' : eventDay ? 'Ehrenfeld · with Lea' : 'Südstadt · A2 group'}</div><div class="plan-actions"><button class="primary small" data-open="${empty || removed ? 'day-composer' : 'activity'}">${empty || removed ? icon('calendar-plus') + 'Plan your day' : 'Open activity'}</button>${empty || removed ? '' : `<button class="activity-menu" aria-label="Activity options" aria-expanded="false" data-reveal-delete>•••</button>`}</div></div><button class="delete-action" data-delete-activity hidden>${icon('trash')}Delete</button></div></div>`;
    }
    function connections() {
        if (view === 'delay') {
            return `<div class="overview-role connections-role delay-role"><div class="role-heading">${icon('train')}<h3>Connections</h3><span class="delay-badge">12 min delay</span></div><h3 class="delay-title">Your usual route<br>is running late.</h3><div class="route-stops"><span class="line-badge">16</span><div class="stop-stack"><strong>Rathaus</strong><span>Ubierring</span></div><span class="route-caption">To your 09:30 class</span></div><div class="delay-times"><div><span>Scheduled departure</span><strong>09:02</strong></div><div class="expected"><span>Expected departure</span><strong>09:14</strong></div></div><div class="delay-actions"><button class="primary" data-open="alternatives">Check alternatives</button><button class="composer-link" data-composer="route">${icon('spark')}Ask Composer</button></div><p class="data-status">Sample update · 08:38 · not live</p></div>`;
        }
        return `<div class="overview-role connections-role"><div class="role-heading">${icon('train')}<h3>Connections</h3></div><button class="journey-row open-row" data-open="journey"><span class="line-badge">${eventDay ? '5' : '16'}</span><span class="journey-info"><strong>${eventDay ? 'Rathaus' : 'Rathaus'}<span class="stop-destination">${eventDay ? 'Venloer Straße' : 'Ubierring'}</span></strong><span class="journey-time">${eventDay ? '18:05' : '09:02'} <span>Scheduled · no live status</span></span></span>${icon('chevron')}</button><p class="data-status">Sample timetable</p></div>`;
    }
    function paperwork() {
        return `<div class="overview-role paperwork-role"><div class="role-heading">${icon('document')}<h3>Paperwork</h3><span class="record-label">Your records</span></div><button class="process-row open-row" data-process="blue-card"><span><strong>EU Blue Card</strong><span class="process-progress">1 of 5 documents ready</span><span class="progress-track" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span></span>${icon('chevron')}</button><button class="process-row secondary-process open-row" data-process="address"><span><strong>Address registration</strong><span>Draft checklist</span></span>${icon('chevron')}</button><button class="all-paperwork text-open" data-open="all-paperwork">All paperwork ${icon('chevron')}</button></div>`;
    }
    function weather() {
        const times = eventDay
            ? ['Now', '17:00', '20:00']
            : ['Now', '12:00', '17:00'];
        const temps = eventDay ? ['19°', '18°', '16°'] : ['15°', '19°', '18°'];
        return `<div class="overview-role weather-role"><div class="role-heading">${icon('sun')}<h3>Weather</h3></div><button class="weather-content" data-open="weather" aria-label="Open the sample forecast, dry until this evening"><div class="forecast-hours">${times.map((time, i) => `<span><small>${time}</small>${icon('sun')}<strong>${temps[i]}</strong></span>`).join('')}</div><span class="weather-useful">Dry until this evening ${icon('chevron')}</span></button><p class="data-status">Sample forecast · not live</p></div>`;
    }
    function overview() {
        return `<section class="your-day ${view === 'delay' ? 'has-delay' : ''}" aria-labelledby="day-title"><div class="overview-header"><h2 id="day-title">Your day</h2><button class="agenda-control text-open" data-open="agenda">${icon('calendar')}Agenda ${icon('chevron')}</button></div><div class="overview-content">${view === 'delay' ? connections() + plans() + paperwork() + weather() : plans() + connections() + paperwork() + weather()}</div></section>`;
    }
    function eventCard(e, featured = false) {
        return `<article class="event-card ${e.className} ${featured ? 'featured-event' : ''}" data-event-id="${e.id}"><img src="../events-next/photos/${e.image}" alt="Illustrative ${e.category.toLowerCase()} scene; not the listed event or venue"/><button class="event-open" data-event="${e.id}" aria-label="Open ${e.plain}, 30 September, ${e.time}"></button><div class="event-top"><span class="event-category">${icon(e.symbol)}${featured ? 'Today’s city highlight' : e.category}</span><button class="event-save glass" data-save="${e.id}" aria-label="${saved.has(e.id) ? 'Unsave' : 'Save'} ${e.plain} for later" aria-pressed="${saved.has(e.id)}">${icon('bookmark')}<span>${saved.has(e.id) ? 'Saved' : 'Save'}</span></button></div><div class="event-content"><span class="date-marker"><strong>30</strong> Sept</span><h3>${e.title}</h3><div class="event-bottom"><div class="event-facts"><span>${icon('clock')}${e.time}</span><span>${icon('pin')}${e.venue}</span><span>${icon('ticket')}${e.admission}</span></div><button class="event-plan" data-plan="${e.id}" aria-label="Add ${e.plain} to a proposed plan">${icon('calendar-plus')}<span>Plan</span></button></div><span class="image-disclosure">Illustrative image · sample event</span></div></article>`;
    }
    function draft() {
        return `<article class="practical-card draft-card"><div class="card-role">${icon('calendar-plus')}<span>For later today</span><span class="draft-label">Draft</span></div><h3>An evening<br> in Ehrenfeld</h3><div class="card-footer"><span>2 ideas · nothing confirmed</span><button class="dark-action" data-composer="draft">${icon('spark')}Review draft</button></div></article>`;
    }
    function routeEntry() {
        return `<article class="practical-card route-card"><div class="card-role">${icon('route')}<span>A new journey</span></div><h3>Going somewhere else?</h3><button class="text-open" data-open="new-route">Plan a route ${icon('chevron')}</button></article>`;
    }
    function welcome() {
        return `<article class="practical-card settlement-card" id="settlement-card"><div class="card-role">${icon('document')}<span>Getting settled</span><button class="dismiss-help" aria-label="Dismiss settlement help" data-dismiss-help>${icon('close')}</button></div><h3>A little help<br> finding your feet.</h3><div class="card-footer"><span>Optional · at your pace</span><button class="text-open" data-open="settlement">Explore your guide ${icon('chevron')}</button></div></article>`;
    }
    function board() {
        return `<section class="discovery-section" aria-labelledby="discovery-title"><div class="section-heading"><h2 id="discovery-title">${eventDay ? 'More happening today' : 'Around you today'}</h2><a href="../events-next/" class="text-open">All events ${icon('chevron')}</a></div><div class="mixed-board ${eventDay ? 'event-led-board' : ''}">${draft()}<div class="event-collection" role="region" aria-label="Today’s sample events" aria-roledescription="swipeable collection">${events
            .filter((e) => !eventDay || e.id !== 'art')
            .map((e) => eventCard(e))
            .join(
                '',
            )}</div>${routeEntry()}${welcome()}</div><div class="collection-footer"><span>Music, film, games, outdoors & more</span><div class="collection-controls"><button data-rail="prev" aria-label="Previous events">${icon('back')}</button><button data-rail="next" aria-label="Next events">${icon('arrow')}</button></div></div></section>`;
    }
    function render() {
        document.body.dataset.view = view;
        document.getElementById('app').innerHTML =
            `${header()}<main class="today-page"><div class="greeting"><div><h1>${eventDay ? 'Good afternoon' : 'Good morning'}, Anar.</h1><span class="mobile-city">Cologne</span></div><div class="day-meta"><time datetime="2026-09-30">Wed, 30 September</time>${sampleNote}</div></div><div class="top-composition">${eventDay ? eventCard(events[0], true) + overview() : overview()}</div>${board()}<footer class="proposal-footnote"><p>Sample day · ${eventDay ? '14:00' : '08:40'}, 30 September 2026. All personal records and updates are fictional.</p><p>Existing AI illustrations depict activities, not these events or venues.</p><a href="./review.html">Open the design review</a></footer></main>`;
        bindRail();
        bindActivity();
    }
    const panel = document.getElementById('context-panel');
    function openPanel(title, content, trigger) {
        if (!panel.open) opener = trigger || document.activeElement;
        panel.innerHTML = `<div class="panel-header"><h2 id="panel-title">${title}</h2><button class="panel-close" aria-label="Close panel" data-close>${icon('close')}</button></div>${content}`;
        if (!panel.open) panel.showModal();
    }
    function closePanel() {
        panel.close();
        if (opener?.isConnected) opener.focus({ preventScroll: true });
    }
    function notice(text, undo = false) {
        const feedback = document.getElementById('feedback');
        clearTimeout(feedbackTimer);
        feedback.innerHTML = `<span>${text}</span>${undo ? '<button data-undo>Undo</button>' : ''}`;
        feedback.hidden = false;
        feedbackTimer = setTimeout(
            () => {
                feedback.hidden = true;
            },
            undo ? 12000 : 4500,
        );
    }
    function composer(kind, trigger) {
        const contexts = {
            day: {
                name: 'Today · 30 September',
                request: 'Review my plans for today.',
            },
            route: {
                name: 'Route 16 · Rathaus to Ubierring',
                request:
                    'Help me compare alternatives to the 09:02 departure, expected at 09:14. My class is at 09:30. This is a sample delay.',
            },
            draft: {
                name: 'Draft · An evening in Ehrenfeld',
                request:
                    'Review my two saved ideas for this evening. Keep them as a proposal.',
            },
            'blue-card': {
                name: 'EU Blue Card · your sample record',
                request:
                    'Help me review my saved EU Blue Card document checklist. Do not assume requirements or mark anything complete.',
            },
            address: {
                name: 'Address registration · your sample record',
                request:
                    'Help me review my draft address registration checklist.',
            },
        };
        context = contexts[kind] || {
            name: events.find((e) => e.id === kind)?.plain || 'Today',
            request:
                'Help me consider this event for today. Keep my current plan.',
        };
        openPanel(
            'Composer',
            `<div class="composer-preview"><span class="preview-chip">Sample conversation</span><div class="conversation-history"><span>Earlier in this conversation</span><p>Let’s keep the evening flexible.</p></div><div class="context-chip">${icon(kind === 'route' ? 'train' : kind === 'blue-card' || kind === 'address' ? 'document' : 'calendar')}<span>${context.name}</span></div><div class="context-request">${context.request}</div><label for="composer-draft">Your editable request</label><textarea id="composer-draft" rows="4"></textarea><div class="composer-review-note">Your draft and earlier conversation stay here.</div><button class="primary send-request" data-preview-send>${icon('spark')}Send request</button><p class="data-status">Design preview · no message will be sent</p></div>`,
            trigger,
        );
        document.getElementById('composer-draft').value = composerDraft;
    }
    function process(id, trigger) {
        const isBlue = id === 'blue-card';
        openPanel(
            isBlue ? 'EU Blue Card' : 'Address registration',
            `<div class="process-detail"><div class="detail-category">${icon('document')}Paperwork <span class="preview-chip">Sample record</span></div><h3>${isBlue ? 'Your document checklist' : 'Your draft checklist'}</h3><p class="detail-progress">${isBlue ? '1 of 5 documents ready' : 'Not started'}</p>${isBlue ? '<span class="progress-track large"><i></i><i></i><i></i><i></i><i></i></span>' : ''}<button class="detail-process-row open-row" data-checklist="${id}"><span><strong>Review your saved document list</strong><span>Your own entries and progress</span></span>${icon('chevron')}</button><p class="record-note">This preview shows user-recorded progress. Requirements and official guidance belong in the verified process.</p><button class="composer-link" data-composer="${id}">${icon('spark')}Ask Composer about this process</button></div>`,
            trigger,
        );
    }
    function eventDetail(id, trigger, planning = false) {
        const e = events.find((item) => item.id === id);
        openPanel(
            planning ? 'Add to a proposed plan' : e.plain,
            `<div class="event-detail"><img src="../events-next/photos/${e.image}" alt="Illustrative activity scene"/><span class="preview-chip">Sample event · illustrative image</span><h3>${e.plain}</h3><div class="detail-facts"><span>${icon('calendar')}Wed, 30 September</span><span>${icon('clock')}${e.time} · fixed session</span><span>${icon('pin')}${e.venue}</span><span>${icon('ticket')}${e.admission}</span></div>${planning ? '<p class="plan-note">Proposed only. Review it alongside your existing plans before confirming.</p>' : ''}<button class="primary" data-composer="${id}">${icon('spark')}${planning ? 'Review with Composer' : 'Ask Composer about this event'}</button><p class="data-status">No booking, confirmed activity or message is created here.</p></div>`,
            trigger,
        );
    }
    function practical(kind, trigger) {
        if (kind === 'activity') {
            openPanel(
                eventDay ? 'Dinner with Lea' : 'German class',
                `<div class="activity-detail"><span class="confirmed">${icon('check')}Confirmed · sample activity</span><h3>${eventDay ? '18:30 · Ehrenfeld' : '09:30 · Südstadt'}</h3><p>${eventDay ? 'Your recorded plan with Lea.' : 'Your recorded A2 group session.'}</p><button class="composer-link" data-composer="day">${icon('spark')}Review today with Composer</button><button class="text-destructive" data-panel-reveal>Remove activity</button><div class="panel-removal" hidden><span>Remove from today?</span><button class="delete-action" data-delete-activity>${icon('trash')}Delete</button></div></div>`,
                trigger,
            );
        } else if (kind === 'agenda') {
            openPanel(
                'Today’s agenda',
                `<div class="agenda-detail"><span class="preview-chip">Sample day · 30 September</span><div class="agenda-item"><strong>${empty || removed ? 'Nothing scheduled yet' : eventDay ? '18:30' : '09:30'}</strong><span>${empty || removed ? 'Make a little space for something good.' : eventDay ? 'Dinner with Lea' : 'German class'}<small>${empty || removed ? '' : 'Confirmed activity'}</small></span></div><button class="composer-link" data-composer="day">${icon('spark')}Review your day</button></div>`,
                trigger,
            );
        } else if (
            kind === 'alternatives' ||
            kind === 'journey' ||
            kind === 'new-route'
        ) {
            openPanel(
                kind === 'new-route'
                    ? 'Plan a new journey'
                    : 'Check your journey',
                `<div class="route-detail"><span class="preview-chip">Sample route · no live service</span><label for="route-from">From</label><input id="route-from" value="Rathaus"/><label for="route-to">To</label><input id="route-to" value="${kind === 'new-route' ? '' : eventDay ? 'Venloer Straße' : 'Ubierring'}" placeholder="Choose a destination"/><label for="route-time">${kind === 'alternatives' ? 'Arrive for your class' : 'Departure time'}</label><input id="route-time" type="time" value="${kind === 'alternatives' ? '09:30' : eventDay ? '18:05' : '09:02'}"/><p class="plan-note">${kind === 'alternatives' ? 'Your affected route and class time are kept together.' : 'Choose the route before reviewing options.'}</p><button class="primary" data-preview-routes>Check alternatives</button><button class="composer-link" data-composer="route">${icon('spark')}Ask Composer</button><p class="data-status">Route results are unavailable in this design preview.</p></div>`,
                trigger,
            );
        } else if (kind === 'all-paperwork') {
            openPanel(
                'Your paperwork',
                `<div class="process-list"><span class="preview-chip">Sample records</span>${paperwork()}</div>`,
                trigger,
            );
        } else if (kind === 'weather') {
            openPanel(
                'Today’s weather',
                `<div class="forecast-detail">${weather()}<p class="record-note">Sample forecast. A weather notice would only take priority when it affects a plan or journey.</p></div>`,
                trigger,
            );
        } else if (kind === 'settlement') {
            openPanel(
                'Getting settled',
                `<div class="settlement-detail"><span class="preview-chip">Optional guide</span><h3>Start with your situation.</h3><p>You can explore the guide whenever it is useful. Today stays available.</p><button class="composer-link" data-composer="day">${icon('spark')}Explore with Composer</button></div>`,
                trigger,
            );
        } else if (kind === 'saved') {
            openPanel(
                'Saved ideas',
                `<div class="saved-detail"><p class="record-note">Bookmarks are ideas for later. They are not confirmed activities.</p>${saved.size ? [...saved].map((id) => `<button class="detail-process-row open-row" data-event="${id}"><span><strong>${events.find((e) => e.id === id).plain}</strong><span>Saved idea</span></span>${icon('chevron')}</button>`).join('') : '<h3>No saved ideas in this preview.</h3>'}</div>`,
                trigger,
            );
        } else if (kind === 'city') {
            openPanel(
                'Your city',
                `<div class="city-detail"><span class="preview-chip">Sample location</span><h3>Cologne</h3><p>The same layout works with another city’s plans, places and programme.</p></div>`,
                trigger,
            );
        } else if (kind === 'day-composer') composer('day', trigger);
        else
            openPanel(
                'Your account',
                '<div class="account-detail"><span class="preview-chip">Sample profile</span><h3>Anar</h3><p>Your city: Cologne</p></div>',
                trigger,
            );
    }
    function revealDelete() {
        const reveal = document.getElementById('activity-reveal');
        if (!reveal || empty || removed) return;
        reveal.classList.toggle('is-revealed');
        const button = reveal.querySelector('[data-delete-activity]');
        button.hidden = !reveal.classList.contains('is-revealed');
        reveal
            .querySelector('[data-reveal-delete]')
            .setAttribute('aria-expanded', String(!button.hidden));
    }
    function bindActivity() {
        const row = document.getElementById('plan-body');
        let start;
        row.addEventListener('pointerdown', (e) => {
            start = { x: e.clientX, y: e.clientY };
        });
        row.addEventListener('pointerup', (e) => {
            if (
                start &&
                start.x - e.clientX > 50 &&
                Math.abs(start.y - e.clientY) < 40
            )
                revealDelete();
            start = null;
        });
        row.addEventListener('dblclick', (e) => {
            if (!e.target.closest('button')) revealDelete();
        });
    }
    function bindRail() {
        const rail = document.querySelector('.event-collection');
        let start;
        rail.addEventListener('pointerdown', (e) => {
            if (
                e.pointerType !== 'mouse' ||
                innerWidth > 760 ||
                e.target.closest('[data-save],[data-plan]')
            )
                return;
            start = { x: e.clientX, scroll: rail.scrollLeft, moved: false };
        });
        rail.addEventListener('pointermove', (e) => {
            if (!start || !e.buttons) return;
            const delta = e.clientX - start.x;
            if (Math.abs(delta) > 5) {
                start.moved = true;
                rail.classList.add('is-dragging');
                rail.scrollLeft = start.scroll - delta;
            }
        });
        const end = () => {
            if (!start) return;
            if (start.moved) {
                rail.dataset.dragged = 'true';
                setTimeout(() => delete rail.dataset.dragged, 60);
            }
            rail.classList.remove('is-dragging');
            start = null;
        };
        rail.addEventListener('pointerup', end);
        rail.addEventListener('pointerleave', end);
        rail.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                e.preventDefault();
                rail.scrollBy({
                    left: (e.key === 'ArrowRight' ? 1 : -1) * 280,
                    behavior: matchMedia('(prefers-reduced-motion: reduce)')
                        .matches
                        ? 'instant'
                        : 'smooth',
                });
            }
        });
    }
    document.addEventListener('input', (e) => {
        if (e.target.id === 'composer-draft') composerDraft = e.target.value;
    });
    document.addEventListener('click', (e) => {
        const button = e.target.closest('button');
        if (!button) return;
        if (button.hasAttribute('data-close')) {
            closePanel();
            return;
        }
        if (button.hasAttribute('data-save')) {
            const id = button.dataset.save;
            saved.has(id) ? saved.delete(id) : saved.add(id);
            button.setAttribute('aria-pressed', String(saved.has(id)));
            button.querySelector('span').textContent = saved.has(id)
                ? 'Saved'
                : 'Save';
            button.setAttribute(
                'aria-label',
                `${saved.has(id) ? 'Unsave' : 'Save'} ${events.find((item) => item.id === id).plain} for later`,
            );
            notice(
                saved.has(id)
                    ? 'Saved idea · no time scheduled'
                    : 'Removed from saved ideas',
            );
            return;
        }
        if (button.hasAttribute('data-plan')) {
            eventDetail(button.dataset.plan, button, true);
            return;
        }
        if (button.hasAttribute('data-event')) {
            if (button.closest('.event-collection')?.dataset.dragged) return;
            eventDetail(button.dataset.event, button);
            return;
        }
        if (button.hasAttribute('data-composer')) {
            composer(button.dataset.composer, button);
            return;
        }
        if (button.hasAttribute('data-process')) {
            process(button.dataset.process, button);
            return;
        }
        if (button.hasAttribute('data-open')) {
            practical(button.dataset.open, button);
            return;
        }
        if (button.hasAttribute('data-reveal-delete')) {
            revealDelete();
            return;
        }
        if (button.hasAttribute('data-panel-reveal')) {
            panel.querySelector('.panel-removal').hidden = false;
            return;
        }
        if (button.hasAttribute('data-delete-activity')) {
            removed = true;
            if (panel.open) panel.close();
            render();
            notice('Activity removed from today', true);
            return;
        }
        if (button.hasAttribute('data-undo')) {
            removed = false;
            render();
            document.getElementById('feedback').hidden = true;
            return;
        }
        if (button.hasAttribute('data-dismiss-help')) {
            document.getElementById('settlement-card').hidden = true;
            return;
        }
        if (button.hasAttribute('data-rail')) {
            document
                .querySelector('.event-collection')
                .scrollBy({
                    left: (button.dataset.rail === 'next' ? 1 : -1) * 280,
                    behavior: matchMedia('(prefers-reduced-motion: reduce)')
                        .matches
                        ? 'instant'
                        : 'smooth',
                });
            return;
        }
        if (button.hasAttribute('data-preview-send')) {
            notice('Request reviewed in this preview · nothing sent');
            return;
        }
        if (button.hasAttribute('data-preview-routes')) {
            notice('Live route results are unavailable in this preview');
            return;
        }
        if (button.hasAttribute('data-checklist')) {
            notice(
                'This exact sample checklist opens here in the final product',
            );
        }
    });
    panel.addEventListener('cancel', (e) => {
        e.preventDefault();
        closePanel();
    });
    panel.addEventListener('click', (e) => {
        if (
            e.target === panel &&
            (e.clientX < panel.getBoundingClientRect().left ||
                e.clientY < panel.getBoundingClientRect().top)
        )
            closePanel();
    });
    render();
})();
