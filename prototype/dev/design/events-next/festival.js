/* Shared Events and Today cards. Generated preview imagery is never provider media. */
(function () {
    const U = ExpaduUI,
        E = U.esc,
        I = U.icon,
        P = window.ExpaduEventPosters;
    const photos = new Set([
        'kunst-machen',
        'exhibition-ludwig',
        'schach',
        'forest',
        'garden',
        'tandem',
        'film',
    ]);
    // Explicit sample editorial choices, independent of filters and result
    // order. Only events that haven't happened yet are featured.
    const picks = [
        'today-review-culture',
        'kunst-machen',
        'garden',
        'exhibition-ludwig',
    ];
    // The preview's sample day (connected/sample-day.js), when loaded.
    const sampleDay = () => window.ExpaduSampleDay?.date || '';
    const past = (item) =>
        !!sampleDay() && !!item.date && item.date < sampleDay();
    const calendarPlus =
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4m8-4v4M3 10h18M12 21H6a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v6M18 16v6m-3-3h6"/></svg>';
    const unique = (items) => [
        ...new Map(
            items.map((item) => [item.occurrence || item.id, item]),
        ).values(),
    ];
    function image(item, eager = false) {
        // Sample-day events name the illustration they share.
        const photo = photos.has(item.illustration)
            ? item.illustration
            : item.id;
        if (P.approvedMedia(item) || !photos.has(photo))
            return P.image(item, eager);
        return `<img class="event-poster-image" src="../events-next/photos/${E(photo)}.webp" data-event-fallback="../events-next/art/${E(photo)}.svg" alt="" width="1536" height="1024" loading="${eager ? 'eager' : 'lazy'}" ${eager ? 'fetchpriority="high"' : ''} decoding="async">`;
    }
    const date = (item) =>
        item.date
            ? new Date(item.date + 'T12:00:00').toLocaleDateString('en-GB', {
                  day: 'numeric',
                  month: 'short',
              })
            : 'Date TBC';
    const time = (item) =>
        `${E(item.start || 'Time TBC')}${item.end ? '–' + E(item.end) : ' · End TBC'}`;
    const save = (item) =>
        `<button class="festival-save" data-save-item="${E(item.id)}" aria-label="Save ${E(item.title)}" aria-pressed="false" title="Save for later">${I('bookmark')}</button>`;
    const plan = (item) =>
        `<button class="festival-plan" data-plan-item="${E(item.id)}" aria-label="Plan to go to ${E(item.title)}" title="Plan to go">${calendarPlus}</button>`;
    function title(item, hero) {
        return hero &&
            item.id === 'kunst-machen' &&
            item.title === 'Kunst zum Mit-Machen'
            ? 'Kunst zum <em>Mit-Machen</em>'
            : E(item.title);
    }
    // `distance` is a short label such as "3.1 km", when known.
    function card(
        item,
        index = 0,
        hero = false,
        { standalone = false, distance = '' } = {},
    ) {
        const id = E(item.id);
        const gone = past(item);
        // Short venue names say more than a district; long ones don't fit.
        const place =
            hero && String(item.venue || '').length > 20
                ? item.area || item.venue
                : item.venue;
        return `<article class="festival-card${hero ? ' festival-card--hero' : ''}${gone ? ' is-past' : ''}" data-event-card="${id}"${hero && !standalone ? ` role="group" aria-roledescription="slide" aria-label="${index + 1}: ${E(item.title)}"` : ''}>
            <button class="festival-open" data-detail="${id}" aria-label="${gone ? 'Past event: ' : 'Explore '}${E(item.title)}">
                ${image(item, hero && index === 0)}
                <span class="festival-shade" aria-hidden="true"></span>
                <span class="festival-category">${I(item.icon || 'calendar')}<span>${E(item.category)}</span></span>
                <span class="festival-story">
                    <span class="festival-date${gone ? ' is-past' : ''}">${gone ? 'Past · ' : ''}${E(date(item))}</span>
                    <strong class="festival-title" role="heading" aria-level="${hero ? 2 : 3}"${item.titleEnglish ? ' lang="de"' : ''}>${title(item, hero)}</strong>
                    ${item.titleEnglish ? `<span class="festival-translation">${E(item.titleEnglish)}</span>` : ''}
                    <span class="festival-facts"><span>${I('clock')}<span>${time(item)}</span></span><span>${I('pin')}<span>${E(place)}${distance ? ` · ${E(distance)}` : ''}</span></span><span>${I('ticket')}<span>${E(item.price || 'Price to check')}</span></span></span>
                </span>
            </button>${gone ? '' : save(item) + plan(item)}
        </article>`;
    }
    function featured(items) {
        const catalogue = unique(items).filter((item) => !past(item));
        const selection = picks
            .map((id) => catalogue.find((item) => item.id === id))
            .filter(Boolean)
            .slice(0, 3);
        return selection.length
            ? selection
            : catalogue.filter((item) => item.discovery?.featured).slice(0, 3);
    }
    function feature(items) {
        const selection = featured(items);
        if (!selection.length) return '';
        const credits = selection
            .filter(P.approvedMedia)
            .map(
                (item) =>
                    `<a href="${E(item.media.source_url)}" target="_blank" rel="noopener">${E(item.title)}: ${E(item.media.credit)}</a>`,
            )
            .join(' · ');
        return `<div class="festival-feature" role="region" aria-roledescription="carousel" aria-label="Featured sample programme, independent of date filters">
            <div class="festival-track" id="festival-track" tabindex="0" aria-label="Featured events. Swipe or use the arrow keys.">${selection.map((item, index) => card(item, index, true)).join('')}</div>
            <div class="festival-caption"><span>Sample programme · AI illustrations</span><div class="festival-pagination"${selection.length === 1 ? ' hidden' : ''}>
            <span class="festival-position" aria-live="polite" aria-atomic="true">1 / ${selection.length}</span><div class="festival-dots" role="group" aria-label="Choose featured event">${selection.map((item, index) => `<button data-festival-slide="${index}" aria-label="Show ${E(item.title)}" aria-current="${index === 0}"><span></span></button>`).join('')}</div>
            <button data-festival-step="-1" aria-label="Previous featured event" aria-controls="festival-track" disabled>${I('back')}</button><button data-festival-step="1" aria-label="Next featured event" aria-controls="festival-track">${I('arrow')}</button>
            </div></div>${credits ? `<p class="festival-credit">${credits}</p>` : ''}
        </div>`;
    }
    function collection(title, icon, items, options) {
        if (!items.length) return '';
        return `<section class="festival-collection" aria-label="${E(title)}"><h2>${I(icon)}${E(title)}</h2><div class="festival-card-rail" style="--festival-columns:${Math.min(4, items.length)}">${items.map((item, i) => card(item, i, false, options(item))).join('')}</div></section>`;
    }
    function today(items) {
        const catalogue = unique(items);
        if (!catalogue.length) return '';
        const { feature: lead } = P.curate(catalogue);
        const seen = new Set(lead ? [lead.category] : []);
        const others = catalogue.filter((item) => item !== lead);
        const supporting = unique([
            ...others.filter((item) => {
                if (seen.has(item.category)) return false;
                seen.add(item.category);
                return true;
            }),
            ...others,
        ]).slice(0, 2);
        const programmeDate = lead?.date || supporting[0]?.date;
        const browse = `../events-next/?discover=1${programmeDate ? '&amp;date=' + E(programmeDate) : ''}`;
        return `${lead ? `<div class="today-event-lead">${card(lead, 0, true, { standalone: true })}<p class="today-event-note">Sample programme · AI illustrations</p></div>` : ''}
            <div class="today-event-support"><div class="today-event-support-heading"><h2>${supporting.length ? 'More good plans' : 'Keep exploring'}</h2><a href="${browse}" aria-label="Explore all events" title="Explore all events">${I('arrow')}</a></div>${supporting.length ? `<div class="today-event-rail" tabindex="0" aria-label="More events. Swipe, drag or use the arrow keys.">${supporting.map((item, index) => card(item, index)).join('')}</div>` : ''}</div>`.replaceAll(
            'data-detail=',
            'data-board-detail=',
        );
    }
    function credit(items) {
        const credits = unique(items)
            .filter(P.approvedMedia)
            .map(
                (item) =>
                    `<a href="${E(item.media.source_url)}" target="_blank" rel="noopener">${E(item.title)}: ${E(item.media.credit)}</a>`,
            )
            .join(' · ');
        return `Sample programme. AI illustrations depict the activity, not the listed event or venue.${credits ? '<br>' + credits : ''}`;
    }
    // Browsing (`discover`) leaves out events already featured above, so a
    // card appears once; filtered results show every match.
    function render(
        items,
        { discover = false, featured: shown = [], distance = () => '' } = {},
    ) {
        const events = unique(items);
        if (!events.length) return '';
        const options = (item) => ({ distance: distance(item) });
        const browse = events.filter((item) => !shown.includes(item.id));
        const content = !discover
            ? `<div class="festival-grid">${events.map((item, i) => card(item, i, false, options(item))).join('')}</div>`
            : browse.length
              ? collection(
                    'Make an afternoon of it',
                    'sun',
                    browse.filter((item) => item.start < '18:00'),
                    options,
                ) +
                collection(
                    'Let the evening happen',
                    'spark',
                    browse.filter((item) => item.start >= '18:00'),
                    options,
                )
              : `<p class="festival-featured-note">${events.length === 1 ? 'This event is' : 'These events are'} featured above.</p>`;
        return `${content}<p class="festival-credit">${credit(events)}</p>`;
    }
    function bindRail(track) {
        if (!track) return;
        const currentCards = () => [
            ...track.querySelectorAll('.festival-card'),
        ];
        if (currentCards().length < 2) return;
        let gesture = null,
            suppressClick = false;
        const limit = () => Math.max(0, track.scrollWidth - track.clientWidth);
        const offset = (card) =>
            Math.max(
                0,
                Math.min(
                    limit(),
                    card.getBoundingClientRect().left -
                        track.getBoundingClientRect().left +
                        track.scrollLeft,
                ),
            );
        const nearest = () => {
            const cards = currentCards();
            return cards.reduce(
                (best, card, index) =>
                    Math.abs(offset(card) - track.scrollLeft) <
                    Math.abs(offset(cards[best]) - track.scrollLeft)
                        ? index
                        : best,
                0,
            );
        };
        const snap = (index) => {
            const cards = currentCards();
            if (!cards.length) return;
            track.scrollTo({
                left: offset(
                    cards[Math.max(0, Math.min(cards.length - 1, index))],
                ),
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                    .matches
                    ? 'instant'
                    : 'smooth',
            });
        };
        track.addEventListener('pointerdown', (event) => {
            suppressClick = false;
            if (
                gesture ||
                event.pointerType !== 'mouse' ||
                event.button !== 0 ||
                event.isPrimary === false ||
                limit() <= 1 ||
                !event.target.closest('.festival-open')
            )
                return;
            gesture = {
                id: event.pointerId,
                x: event.clientX,
                y: event.clientY,
                left: track.scrollLeft,
                index: nearest(),
                moved: false,
            };
        });
        track.addEventListener('pointermove', (event) => {
            if (!gesture || gesture.id !== event.pointerId) return;
            const dx = event.clientX - gesture.x,
                dy = event.clientY - gesture.y;
            if (!gesture.moved) {
                if (Math.max(Math.abs(dx), Math.abs(dy)) < 8) return;
                if (Math.abs(dy) >= Math.abs(dx)) {
                    gesture = null;
                    return;
                }
                gesture.moved = true;
                track.dataset.dragging = 'true';
                track.setPointerCapture(event.pointerId);
            }
            event.preventDefault();
            track.scrollLeft = Math.max(
                0,
                Math.min(limit(), gesture.left - dx),
            );
        });
        function finish(event, cancelled = false) {
            if (!gesture || gesture.id !== event.pointerId) return;
            const previous = gesture;
            gesture = null;
            if (!previous.moved) return;
            suppressClick = true;
            delete track.dataset.dragging;
            if (track.hasPointerCapture(event.pointerId))
                track.releasePointerCapture(event.pointerId);
            const dx = event.clientX - previous.x;
            snap(
                previous.index +
                    (!cancelled && Math.abs(dx) >= 40 ? (dx < 0 ? 1 : -1) : 0),
            );
        }
        track.addEventListener('pointerup', (event) => finish(event));
        track.addEventListener('pointercancel', (event) => finish(event, true));
        track.addEventListener('lostpointercapture', (event) =>
            finish(event, true),
        );
        // The image must not start the browser's file drag, and release must not open details.
        track.addEventListener('dragstart', (event) => event.preventDefault());
        track.addEventListener(
            'click',
            (event) => {
                if (!suppressClick || event.detail === 0) return;
                suppressClick = false;
                event.preventDefault();
                event.stopImmediatePropagation();
            },
            true,
        );
        track.addEventListener('keydown', (event) => {
            if (
                event.target !== track ||
                limit() <= 1 ||
                !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)
            )
                return;
            event.preventDefault();
            snap(
                event.key === 'Home'
                    ? 0
                    : event.key === 'End'
                      ? currentCards().length - 1
                      : nearest() + (event.key === 'ArrowLeft' ? -1 : 1),
            );
        });
    }
    function bind(root) {
        const track = root.querySelector('.festival-track');
        const slides = track?.querySelectorAll('.festival-card--hero');
        if (!slides?.length) return;
        let current = 0,
            frame = null;
        let drag = null,
            suppressClick = false;
        const position = root.querySelector('.festival-position');
        const previous = root.querySelector('[data-festival-step="-1"]');
        const next = root.querySelector('[data-festival-step="1"]');
        const dots = [...root.querySelectorAll('[data-festival-slide]')];
        function sync() {
            frame = null;
            const left = track.getBoundingClientRect().left;
            current = [...slides].reduce(
                (best, slide, index) =>
                    Math.abs(slide.getBoundingClientRect().left - left) <
                    Math.abs(slides[best].getBoundingClientRect().left - left)
                        ? index
                        : best,
                0,
            );
            position.textContent = `${current + 1} / ${slides.length}`;
            previous.disabled = current === 0;
            next.disabled = current === slides.length - 1;
            dots.forEach((dot, index) =>
                dot.setAttribute('aria-current', String(index === current)),
            );
        }
        function go(index) {
            const target = Math.max(0, Math.min(slides.length - 1, index));
            const left =
                slides[target].getBoundingClientRect().left -
                track.getBoundingClientRect().left +
                track.scrollLeft;
            track.scrollTo({
                left,
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                    .matches
                    ? 'instant'
                    : 'smooth',
            });
        }
        root.addEventListener('click', (event) => {
            const dot = event.target.closest('[data-festival-slide]');
            const step = event.target.closest('[data-festival-step]');
            if (dot) go(Number(dot.dataset.festivalSlide));
            else if (step) go(current + Number(step.dataset.festivalStep));
        });
        // Native touch scrolling stays intact. Mouse users can drag the same rail.
        track.addEventListener('pointerdown', (event) => {
            suppressClick = false;
            if (
                event.pointerType !== 'mouse' ||
                event.button !== 0 ||
                !event.target.closest('.festival-open')
            )
                return;
            drag = {
                id: event.pointerId,
                x: event.clientX,
                y: event.clientY,
                left: track.scrollLeft,
                index: current,
                moved: false,
            };
        });
        track.addEventListener('pointermove', (event) => {
            if (!drag || event.pointerId !== drag.id) return;
            const distance = event.clientX - drag.x;
            if (!drag.moved) {
                if (Math.abs(event.clientY - drag.y) > Math.abs(distance)) {
                    drag = null;
                    return;
                }
                if (Math.abs(distance) < 8) return;
                drag.moved = true;
                track.setPointerCapture(event.pointerId);
                track.dataset.dragging = 'true';
            }
            event.preventDefault();
            track.scrollLeft = drag.left - distance;
        });
        function finishDrag(event, cancelled = false) {
            if (!drag || event.pointerId !== drag.id) return;
            const snapshot = drag;
            drag = null;
            delete track.dataset.dragging;
            if (!snapshot.moved) return;
            suppressClick = true;
            if (track.hasPointerCapture(event.pointerId))
                track.releasePointerCapture(event.pointerId);
            const distance = event.clientX - snapshot.x;
            go(
                snapshot.index +
                    (!cancelled && Math.abs(distance) > 45
                        ? distance < 0
                            ? 1
                            : -1
                        : 0),
            );
        }
        track.addEventListener('pointerup', (event) => finishDrag(event));
        track.addEventListener('pointercancel', (event) =>
            finishDrag(event, true),
        );
        track.addEventListener('lostpointercapture', (event) =>
            finishDrag(event, true),
        );
        track.addEventListener(
            'click',
            (event) => {
                if (!suppressClick) return;
                suppressClick = false;
                event.preventDefault();
                event.stopImmediatePropagation();
            },
            true,
        );
        track.addEventListener('keydown', (event) => {
            if (
                event.target !== track ||
                !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)
            )
                return;
            event.preventDefault();
            go(
                event.key === 'Home'
                    ? 0
                    : event.key === 'End'
                      ? slides.length - 1
                      : current + (event.key === 'ArrowLeft' ? -1 : 1),
            );
        });
        track.addEventListener(
            'scroll',
            () => {
                if (!frame) frame = requestAnimationFrame(sync);
            },
            { passive: true },
        );
        sync();
    }
    window.ExpaduEventFestival = {
        past,
        image,
        card,
        today,
        credit,
        featured,
        feature,
        render,
        bind,
        bindRail,
    };
})();
