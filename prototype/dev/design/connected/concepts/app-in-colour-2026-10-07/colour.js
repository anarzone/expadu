/* "App in colour" concept, 7 October 2026 — review only.
   Injected into the approved pages by this folder's index.html (or by the
   screenshot script); the pages themselves are unchanged. It adds:
   - Today: a top band whose colour follows the time of day;
   - Composer: section-coloured starters and poster answers for events;
   - Paperwork and Services: their own section colour in the heading area;
   - an optional Bricolage Grotesque display face for page titles and big times.
   Options come from window.__appInColour before load, or AppInColour.set(). */
(function () {
    'use strict';
    if (window.AppInColour) return;

    const root = document.documentElement;
    const BASE = '/dev/design/connected/concepts/app-in-colour-2026-10-07/';
    const PERIODS = ['morning', 'midday', 'afternoon', 'evening', 'night'];
    const options = Object.assign(
        { time: 'sample', display: 'geist' },
        window.__appInColour || {},
    );

    // Morning 05–11, midday 11–14, afternoon 14–18, evening 18–22, night.
    function periodAt(clock) {
        const [h, m] = String(clock || '13:10')
            .split(':')
            .map(Number);
        const t = h * 60 + (m || 0);
        if (t >= 300 && t < 660) return 'morning';
        if (t >= 660 && t < 840) return 'midday';
        if (t >= 840 && t < 1080) return 'afternoon';
        if (t >= 1080 && t < 1320) return 'evening';
        return 'night';
    }
    const sampleClock = () => window.ExpaduSampleDay?.time || '13:10';

    function apply() {
        const period = PERIODS.includes(options.time)
            ? options.time
            : periodAt(sampleClock());
        root.classList.add('ac');
        root.dataset.acBand = period;
        root.dataset.acSample =
            period === periodAt(sampleClock()) ? 'true' : 'false';
        root.dataset.acDisplay =
            options.display === 'bricolage' ? 'bricolage' : 'geist';
        const page = document.body?.className || '';
        root.dataset.acPage = /today-next/.test(page)
            ? 'today'
            : document.querySelector('.studio')
              ? 'composer'
              : location.pathname.includes('bureaucracy-next')
                ? 'paperwork'
                : location.pathname.includes('services-next')
                  ? 'services'
                  : location.pathname.includes('events-next')
                    ? 'events'
                    : location.pathname.includes('departures-next')
                      ? 'travel'
                      : 'other';
        measureToday();
    }

    /* ── The band: Today's ends just below "Your day" on phones and behind
       the top of the workspace on larger screens; Paperwork's and Services'
       hold the page heading. Measured per layout change; the time of day
       never changes it. ── */
    function measureBand() {
        const main = document.querySelector('main#main');
        if (!main) return;
        const top = main.getBoundingClientRect().top;
        // Start right under the header, even where the page leaves a gap.
        const header = document.querySelector('#shell');
        const start = header
            ? Math.min(0, header.getBoundingClientRect().bottom - top)
            : 0;
        main.style.setProperty('--ac-band-top', `${Math.round(start)}px`);
        const set = (px) =>
            main.style.setProperty(
                '--ac-band-h',
                `${Math.round(px - start)}px`,
            );
        const page = root.dataset.acPage;
        if (page === 'today') {
            const ws = document.querySelector('.today-workspace');
            const heading = document.querySelector('.day-heading');
            if (!ws || !heading) return;
            const wsTop = ws.getBoundingClientRect().top;
            const phone = window.matchMedia('(max-width: 700px)').matches;
            const head = phone
                ? heading.getBoundingClientRect().bottom - wsTop + 14
                : 0;
            ws.style.setProperty('--ac-head', `${Math.round(head)}px`);
            set(wsTop - top + (phone ? head + 36 : 72));
        } else if (page === 'paperwork' || page === 'services') {
            const last =
                page === 'paperwork'
                    ? document.querySelector('.case-navigation') ||
                      document.querySelector('.case-top')
                    : document.querySelector('.page-title');
            if (last)
                set(
                    last.getBoundingClientRect().bottom -
                        top +
                        (page === 'paperwork' ? 12 : 22),
                );
        }
    }
    const measureToday = measureBand;

    /* ── Composer: starters coloured by the section they lead to. ── */
    const E = (s) =>
        String(s ?? '').replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
    const svg = {
        route: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="6" cy="19" r="2.2"/><circle cx="18" cy="5" r="2.2"/><path d="M8.2 19H15a3.5 3.5 0 0 0 0-7H9a3.5 3.5 0 0 1 0-7h6.8"/></svg>',
        doc: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg>',
        calendar:
            '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M16 3v4M8 3v4M4 10h16"/></svg>',
        spark: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3c.6 4.6 3.4 7.4 9 9-5.6 1.6-8.4 4.4-9 9-.6-4.6-3.4-7.4-9-9 5.6-1.6 8.4-4.4 9-9z"/></svg>',
        leaf: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19c0-8 5-14 15-14 0 10-6 15-14 15"/><path d="M5 19c3-4 6-7 10-9"/></svg>',
        arrow: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
    };

    function starters() {
        const day = window.ExpaduSampleDay || {};
        const appt = day.appointment;
        const tonight = (day.events || []).filter((x) => x.date === day.date);
        const cards = [];
        if (appt && appt.start >= sampleClock())
            cards.push({
                kind: 'travel',
                tag: 'Travel',
                icon: svg.route,
                label: `When to leave for ${appt.start}`,
                detail: `${appt.journey?.from || ''} → ${appt.location} · line ${appt.journey?.line || ''}`,
                prompt: `When should I leave for my ${appt.start}?`,
            });
        if (appt?.process)
            cards.push({
                kind: 'paperwork',
                tag: 'Paperwork',
                icon: svg.doc,
                label: 'What to bring at 14:00',
                detail: 'Your EU Blue Card checklist',
                prompt: `What should I bring to my ${appt.title}?`,
            });
        if (tonight.length)
            cards.push({
                kind: 'events',
                tag: 'Events',
                icon: svg.calendar,
                label: 'What’s on tonight',
                detail: `${tonight.length} sample events from ${tonight[0].start}`,
                stamp: stampDate(day.date),
                prompt: 'What’s on tonight?',
            });
        const chips = [
            {
                icon: svg.spark,
                label: 'Plan my Saturday',
                prompt: 'Help me plan Saturday',
            },
            {
                icon: svg.leaf,
                label: 'A little time outdoors',
                prompt: 'Find something outdoors for Saturday afternoon.',
            },
        ];
        return { cards, chips };
    }

    function decorateWelcome() {
        const grid = document.querySelector('.studio-welcome .starter-grid');
        if (!grid || grid.dataset.ac) return;
        const { cards, chips } = starters();
        const wrap = document.createElement('div');
        wrap.className = 'ac-starters';
        wrap.dataset.ac = '1';
        wrap.innerHTML =
            `<div class="ac-starter-cards">${cards
                .map(
                    (x) =>
                        `<button type="button" class="ac-starter ac-starter--${x.kind}" data-prompt="${E(x.prompt)}"><span class="ac-starter__tag">${x.icon}${E(x.tag)}</span><strong>${E(x.label)}</strong><small>${E(x.detail)}</small>${x.stamp ? `<span class="ac-stamp" aria-hidden="true">${E(x.stamp)}</span>` : ''}<span class="ac-starter__go" aria-hidden="true">${svg.arrow}</span></button>`,
                )
                .join('')}</div>` +
            `<div class="ac-starter-chips">${chips
                .map(
                    (x) =>
                        `<button type="button" class="ac-chip" data-prompt="${E(x.prompt)}">${x.icon}<span>${E(x.label)}</span></button>`,
                )
                .join('')}</div>`;
        grid.dataset.ac = '1';
        grid.hidden = true;
        grid.after(wrap);
    }

    /* Event answers: the Events poster picture with its yellow date stamp,
       still labelled as an AI illustration of a sample event. */
    const PHOTO = '/dev/design/events-next/photos/';
    function eventFor(title) {
        const all = [
            ...(window.ExpaduSampleDay?.events || []),
            ...(window.ExpaduModel?.events || []),
        ];
        return all.find((x) => x.title === title);
    }
    function stampDate(iso) {
        const d = new Date(`${iso}T12:00:00`);
        return Number.isNaN(d.getTime())
            ? ''
            : `${d.getDate()} ${d.toLocaleString('en-GB', { month: 'short' })}`;
    }
    function decorateEvents() {
        document.querySelectorAll('.studio .service-card').forEach((card) => {
            if (card.dataset.ac) return;
            const title = card
                .querySelector('.service-title, h3')
                ?.textContent?.trim();
            const event = title && eventFor(title);
            card.dataset.ac = '1';
            if (!event?.illustration) return;
            const poster = document.createElement('div');
            poster.className = 'ac-poster';
            poster.innerHTML = `<img src="${PHOTO}${E(event.illustration)}.webp" alt="" loading="lazy"><span class="ac-poster__label">AI illustration · sample</span><span class="ac-stamp">${E(stampDate(event.date))} · ${E(event.start)}</span>`;
            card.classList.add('ac-event-card');
            card.prepend(poster);
        });
    }

    let queued = false;
    function refresh() {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {
            queued = false;
            decorateWelcome();
            decorateEvents();
            measureToday();
        });
    }

    function start() {
        // Load the concept stylesheet once (index.html may already have).
        if (!document.querySelector('link[data-ac]')) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = `${BASE}colour.css`;
            link.dataset.ac = '1';
            document.head.append(link);
        }
        apply();
        refresh();
        new MutationObserver(refresh).observe(document.body, {
            childList: true,
            subtree: true,
        });
        window.addEventListener('resize', refresh);
    }

    window.AppInColour = {
        periods: PERIODS,
        periodAt,
        set(next) {
            Object.assign(options, next || {});
            apply();
            return { ...options, band: root.dataset.acBand };
        },
        get: () => ({ ...options, band: root.dataset.acBand }),
    };

    if (document.readyState === 'loading')
        document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();
