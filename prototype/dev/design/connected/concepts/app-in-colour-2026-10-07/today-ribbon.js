/* "The shape of your day" — a Today concept, 7 October 2026. Review only.
   Added to the approved Today page by the concept viewer; the page itself is
   unchanged. A ribbon at the top of the workspace shows the whole day at a
   glance (now, the trip, the appointment, what isn't planned, tonight's sample
   ideas). Once the journey card scrolls away, a one-line bar under the header
   keeps the next thing to do in view. Built from the shared sample day
   (connected/sample-day.js) and Today's leave-by rule; nothing is live. */
(function () {
    'use strict';
    if (window.TodayRibbon) return;
    const BASE = '/dev/design/connected/concepts/app-in-colour-2026-10-07/';
    const root = document.documentElement;
    const day = () => window.ExpaduSampleDay;
    const mins = (t) => +t.slice(0, 2) * 60 + +t.slice(3, 5);
    const hhmm = (n) =>
        `${String(Math.floor(n / 60)).padStart(2, '0')}:${String(n % 60).padStart(2, '0')}`;
    const E = (s) =>
        String(s ?? '').replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
    const icon = {
        train: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="2" width="14" height="17" rx="4"/><path d="M5 11h14M12 2v9M8 22l2-3m6 3-2-3"/></svg>',
        doc: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H5v20h14V7Z"/><path d="M14 2v5h5M8 11h8m-8 4h8m-8 3h5"/></svg>',
        up: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 14 6-6 6 6"/></svg>',
    };

    // The day as Today shows it: the appointment's planned trip (walk to the
    // stop included), the appointment, and tonight's sample events as ideas.
    function model() {
        const d = day();
        const a = d?.appointment;
        if (!a?.journey) return null;
        const j = a.journey;
        const leave = mins(j.depart) - (j.accessWalkMinutes || 0);
        const there = mins(j.arrive) + (j.walkMinutes || 0);
        return {
            now: mins(d.time),
            trip: {
                from: leave,
                to: there,
                line: j.line,
                stop: j.from,
                depart: j.depart,
            },
            plan: {
                from: mins(a.start),
                to: mins(a.end),
                title: a.title,
                place: a.location,
            },
            ideas: (d.events || [])
                .filter((e) => e.date === d.date)
                .map((e) => ({
                    from: mins(e.start),
                    to: mins(e.end),
                    title: e.title,
                    start: e.start,
                })),
        };
    }

    function ribbon(m, phone) {
        // A little of the morning for context, then the rest of the day.
        const start =
            Math.max(6, Math.floor(m.now / 60) - (phone ? 1 : 3)) * 60;
        const end = 24 * 60;
        const at = (t) =>
            `${(((Math.max(start, Math.min(end, t)) - start) / (end - start)) * 100).toFixed(2)}%`;
        const span = (a, b) => `left:${at(a)};width:calc(${at(b)} - ${at(a)})`;
        const step = phone ? 180 : 120;
        const ticks = [];
        for (let t = start; t <= end; t += step)
            ticks.push(
                `<span style="left:${at(t)}">${String(t / 60).padStart(2, '0')}</span>`,
            );
        // Ideas sit inside the unplanned stretch; overlapping ones stack.
        const rows = [];
        const ideas = [...m.ideas]
            .sort((x, y) => x.from - y.from)
            .map((x) => {
                let row = rows.findIndex((r) => r <= x.from);
                if (row < 0) row = rows.push(0) - 1;
                rows[row] = x.to;
                return { ...x, row };
            });
        const leaveIn = m.trip.from - m.now;
        const nowNote =
            leaveIn > 0 ? `leave by ${hhmm(m.trip.from)}` : 'leave now';
        const first = ideas[0];
        const label = `Your day: now ${hhmm(m.now)}, ${nowNote}. ${hhmm(m.plan.from)} to ${hhmm(m.plan.to)} ${m.plan.title}, ${m.plan.place}. Nothing else in your plan. Sample ideas tonight: ${ideas.map((x) => `${x.title} from ${x.start}`).join(', ')}.`;
        return `<div class="tr-ribbon" role="img" aria-label="${E(label)}">
            <div class="tr-labels" aria-hidden="true">
                <span class="tr-label tr-label--now" style="left:${at(m.now)}">${phone ? 'Now' : `Now ${hhmm(m.now)}`}</span>
                ${phone ? '' : `<span class="tr-label" style="left:${at(m.plan.from)}"><b>${hhmm(m.plan.from)}</b> ${E(m.plan.title)}</span>`}
                ${first && !phone ? `<span class="tr-label tr-label--ideas">Tonight · ${ideas.map((x) => `<b>${x.start}</b> ${E(x.title)}`).join(' · ')} <i>ideas, sample</i></span>` : ''}
            </div>
            <div class="tr-track" aria-hidden="true">
                <span class="tr-past" style="width:${at(m.now)}"></span>
                <span class="tr-open" style="${span(m.plan.to, end)}"><span>${phone ? 'Nothing else planned' : 'Nothing else in your plan'}</span></span>
                ${ideas.map((x) => `<span class="tr-idea" style="${span(x.from, x.to)};--tr-row:${x.row}"></span>`).join('')}
                <span class="tr-block tr-block--trip" style="${span(m.trip.from, m.trip.to)}">${icon.train}</span>
                <span class="tr-block tr-block--plan" style="${span(m.plan.from, m.plan.to)}">${phone ? '' : icon.doc}</span>
                <span class="tr-now" style="left:${at(m.now)}"></span>
            </div>
            <div class="tr-axis" aria-hidden="true">${ticks.join('')}</div>
            ${phone ? `<p class="tr-caption" aria-hidden="true">${nowNote[0].toUpperCase() + nowNote.slice(1)} · ${hhmm(m.plan.from)} appointment · ${ideas.length} ideas tonight</p>` : ''}
        </div>`;
    }

    function bar(m) {
        const leaveIn = m.trip.from - m.now;
        const lead =
            leaveIn > 0 ? `Leave by ${hhmm(m.trip.from)}` : 'Leave now';
        return `<button type="button" class="tr-bar" aria-label="${E(`${lead} for your ${hhmm(m.plan.from)} ${m.plan.title}. Show it`)}">
            <span class="tr-bar__icon">${icon.train}</span>
            <span class="tr-bar__text"><strong>${lead}</strong><span> · Line ${E(m.trip.line)} at ${E(m.trip.depart)} from ${E(m.trip.stop)}</span><small>for ${hhmm(m.plan.from)} ${E(m.plan.title)}</small></span>
            <span class="tr-bar__go">${icon.up}</span>
        </button>`;
    }

    const phone = () => window.matchMedia('(max-width: 700px)').matches;
    let shown = false;
    function place() {
        const ws = document.querySelector('.today-workspace');
        const m = model();
        const everyday =
            (document.querySelector('#scenario')?.value || 'everyday') ===
            'everyday';
        let el = document.querySelector('.tr-ribbon-wrap');
        if (!ws || !m || !everyday) {
            el?.remove();
            document.querySelector('.tr-bar')?.remove();
            return;
        }
        const key = phone() ? 'phone' : 'wide';
        if (!el || el.dataset.key !== key || el.parentElement !== ws) {
            el?.remove();
            el = document.createElement('div');
            el.className = 'tr-ribbon-wrap';
            el.dataset.key = key;
            el.innerHTML = ribbon(m, key === 'phone');
            ws.prepend(el);
        }
        if (!document.querySelector('.tr-bar')) {
            const holder = document.createElement('div');
            holder.innerHTML = bar(m);
            const b = holder.firstElementChild;
            b.addEventListener('click', () => {
                const card = document.querySelector('.agenda-item') || ws;
                const reduce = window.matchMedia(
                    '(prefers-reduced-motion: reduce)',
                ).matches;
                card.scrollIntoView({
                    behavior: reduce ? 'auto' : 'smooth',
                    block: 'start',
                });
                card.querySelector('.route-summary')?.focus({
                    preventScroll: true,
                });
            });
            document.body.append(b);
        }
        follow();
    }

    // The bar shows once the journey card's "Leave by" line has scrolled up
    // out of view.
    function follow() {
        const b = document.querySelector('.tr-bar');
        const card =
            document.querySelector('.route-summary .route-leave') ||
            document.querySelector('.route-summary');
        const shell = document.querySelector('#shell');
        if (!b || !card || !shell) return;
        const top = Math.max(0, shell.getBoundingClientRect().bottom);
        const gone = card.getBoundingClientRect().bottom < top + 8;
        b.style.setProperty('--tr-top', `${Math.round(top + 8)}px`);
        if (gone !== shown) {
            shown = gone;
            b.classList.toggle('is-shown', gone);
            b.tabIndex = gone ? 0 : -1;
            b.setAttribute('aria-hidden', String(!gone));
        }
    }

    function start() {
        if (!document.querySelector('link[data-tr]')) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = `${BASE}today-ribbon.css`;
            link.dataset.tr = '1';
            document.head.append(link);
        }
        root.classList.add('tr');
        place();
        let queued = false;
        const later = () => {
            if (queued) return;
            queued = true;
            requestAnimationFrame(() => {
                queued = false;
                place();
            });
        };
        new MutationObserver(later).observe(
            document.querySelector('main') || document.body,
            {
                childList: true,
                subtree: true,
            },
        );
        (document.querySelector('#page-scroll') || window).addEventListener(
            'scroll',
            follow,
            { passive: true },
        );
        window.addEventListener('resize', later);
        document.querySelector('#scenario')?.addEventListener('change', later);
    }

    window.TodayRibbon = { refresh: () => place() };
    if (document.readyState === 'loading')
        document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();
