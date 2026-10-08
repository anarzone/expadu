/* Posters set from an event's own listing: no photograph, nothing added.
   The hour picks the ink and the category picks the shape, so a listing
   without usable media still gets a recognisable, honest picture. */
(function (root) {
    const U = root.ExpaduUI;
    const E = U.esc,
        I = U.icon;
    // Before 17:00 is daylight yellow, until 20:00 evening purple, later
    // night navy. A listing without a time is plain paper.
    function ink(item) {
        if (!/^\d\d:\d\d$/.test(item?.start || '')) return 'paper';
        const hour = Number(item.start.slice(0, 2));
        return hour < 17 ? 'day' : hour < 20 ? 'evening' : 'night';
    }
    const shapes = {
        Culture: 'rings',
        Games: 'checks',
        Outdoors: 'waves',
        Language: 'bubbles',
    };
    const shape = (item) => shapes[item?.category] || 'dots';
    const categoryIcons = {
        Culture: 'museum',
        Games: 'ball',
        Outdoors: 'leaf',
        Language: 'chat',
    };
    const icon = (category) => categoryIcons[category] || 'calendar';
    const dayMonth = (date) =>
        new Date(date + 'T12:00:00').toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
        });
    const weekday = (date) =>
        new Date(date + 'T12:00:00').toLocaleDateString('en-GB', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
        });
    // "Tonight · 30 Sept", "Past · 8 Sept" or "Tue 6 Oct".
    function tape(item, today = '') {
        if (!item.date) return 'Date not listed';
        if (today && item.date < today) return `Past · ${dayMonth(item.date)}`;
        if (item.date === today)
            return `${item.start && item.start >= '17:00' ? 'Tonight' : 'Today'} · ${dayMonth(item.date)}`;
        return weekday(item.date);
    }
    // The picture alone, for places that already print the words
    // (a photo slot on a card, the event sheet's cover).
    const art = (item, extra = '') =>
        `<span class="pp-art pp--${ink(item)} pp--${shape(item)}${extra ? ' ' + extra : ''}" aria-hidden="true"><span></span></span>`;
    const minutes = (time) => {
        const [h, m] = String(time).split(':').map(Number);
        return h * 60 + m;
    };
    // How an event sits after the person's own plan that day, from their
    // recorded appointment only.
    function fits(item, appointment, today) {
        if (
            !appointment?.end ||
            !item.start ||
            item.date !== today ||
            item.start <= appointment.end
        )
            return '';
        const gap = minutes(item.start) - minutes(appointment.end);
        const hours = Math.floor(gap / 60),
            rest = gap % 60;
        const span = hours
            ? `${hours} ${hours === 1 ? 'hour' : 'hours'}${rest ? ` ${rest} min` : ''}`
            : `${rest} min`;
        return `It starts ${span} after your ${appointment.title} ends.`;
    }
    const fact = (label, value) =>
        `<div><dt>${E(label)}</dt><dd${value ? '' : ' class="is-unknown"'}>${value ? E(value) : 'Not listed'}</dd></div>`;
    const calendarPlus =
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4m8-4v4M3 10h18M12 21H6a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v6M18 16v6m-3-3h6"/></svg>';
    const turnIcon =
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 1 0-.6 4"/><path d="M20 20v-5h-5"/></svg>';
    /* A poster with a front (the listing set in type) and a back (its facts
       and actions). `size` is wall, column or mini. Past listings carry no
       Save or Plan. Planning is not available for a listing without a
       time, so it only offers its source. */
    function poster(
        item,
        {
            size = 'wall',
            today = '',
            distance = '',
            access = '',
            appointment = null,
            listing = false,
        } = {},
    ) {
        const id = E(item.id);
        const gone = !!today && !!item.date && item.date < today;
        const plannable = !gone && !listing && !!item.start;
        const time = item.start || 'Time not listed yet';
        const until = item.end ? `until ${E(item.end)}` : item.start ? 'end not listed' : '';
        const place = [item.venue, distance].filter(Boolean).map(E).join(' · ');
        const price = listing ? 'Price not listed yet' : item.price || 'Price not listed';
        const fit = plannable ? fits(item, appointment, today) : '';
        const label = `${item.title}, ${tape(item, today)}${item.start ? ' ' + item.start : ''}`;
        const english = item.titleEnglish
            ? `<span class="pp-translation">${E(item.titleEnglish)}</span>`
            : '';
        const front = `<div class="pp-face pp-front">
            ${art(item)}
            <button class="pp-open" type="button" data-poster-turn aria-expanded="false" aria-label="${E(label)}. Turn the poster over for details">
                <span class="pp-top"><span class="pp-category">${I(icon(item.category))}<span>${E(item.category || 'Event')}</span></span></span>
                <span class="pp-tape">${E(tape(item, today))}</span>
                <strong class="pp-title"${item.titleEnglish ? ' lang="de"' : ''}>${E(item.title)}</strong>
                ${english}
                <span class="pp-bottom">
                    <span class="pp-time">${E(time)}</span>
                    <span class="pp-meta">${[until, place].filter(Boolean).join(' · ')}</span>
                    <span class="pp-price">${E(price)}</span>
                </span>
            </button>
            ${gone || listing ? '' : `<button class="pp-save" type="button" data-save-item="${id}" aria-pressed="false" aria-label="Save ${E(item.title)}" title="Save for later">${I('bookmark')}</button>`}
            ${plannable ? `<button class="pp-plan" type="button" data-plan-item="${id}" aria-label="Add ${E(item.title)} to your plan" title="Add to plan">${calendarPlus}</button>` : ''}
        </div>`;
        const back = `<div class="pp-face pp-back" hidden>
            <div class="pp-back-head"><span class="pp-tape">${E(tape(item, today))}</span><span class="pp-back-note">Back of the poster</span><button class="pp-turn-back" type="button" data-poster-turn aria-label="Turn the poster back over">${turnIcon}</button></div>
            <div class="pp-back-body">
                <strong class="pp-back-title"${item.titleEnglish ? ' lang="de"' : ''}>${E(item.title)}</strong>
                ${english}
                <p class="pp-back-when">${E(item.date ? weekday(item.date) : '')}${item.start ? ` · ${E(item.start)}${item.end ? '–' + E(item.end) : ''}${gone ? '' : item.attendancePolicy === 'window' ? ' · visit any time while it’s open' : ' · listed times'}` : ' · time not listed yet'}</p>
                <p class="pp-back-where">${E(item.venue || '')}${item.area ? `, ${E(item.area)}` : ''}${distance ? ` · ${E(distance)}` : ''}</p>
                ${gone ? '<p class="pp-back-past">Already happened. You can read it, but not plan it.</p>' : ''}
                ${listing ? `<p class="pp-back-past">${E(item.aboutSource || 'A later listing in the same series')}. Its time and price aren’t listed yet.</p>` : ''}
                ${fit ? `<p class="pp-fit"><span aria-hidden="true"></span>${E(fit)}</p>` : ''}
                <dl class="pp-facts">${[
                    ['Admission', listing ? '' : item.price],
                    [
                        'When to arrive',
                        listing
                            ? ''
                            : {
                                  fixed: 'By the start time',
                                  window: 'Any time while it’s open',
                              }[item.attendancePolicy],
                    ],
                    ['Language', item.language],
                    ['Who it’s for', item.ages ? `Ages ${item.ages}` : ''],
                    ['Accessibility', item.accessibility || access],
                ]
                    .map(([l, v]) => fact(l, v))
                    .join('')}</dl>
            </div>
            <div class="pp-back-actions">
                ${plannable ? `<button class="pp-back-plan" type="button" data-plan-item="${id}">${calendarPlus}Add to plan</button>` : ''}
                ${gone || listing ? '' : `<button class="pp-back-save" type="button" data-save-item="${id}" aria-pressed="false" aria-label="Save ${E(item.title)}" title="Save for later">${I('bookmark')}</button>`}
                ${listing ? (item.sourceUrl ? `<a class="pp-back-more" href="${E(item.sourceUrl)}" target="_blank" rel="noopener">City listing${I('arrow')}</a>` : '') : `<button class="pp-back-more" type="button" data-detail="${id}">More about it</button>`}
            </div>
        </div>`;
        // A past poster wears in one of three ways, always the same for it.
        const wear = gone
            ? ` data-wear="${[...String(item.id)].reduce((sum, c) => sum + c.charCodeAt(0), 0) % 3}"`
            : '';
        return `<article class="pp pp--size-${size} pp--${ink(item)} pp--${shape(item)}${gone ? ' is-past' : ''}${listing ? ' is-listing' : ''}" data-poster="${id}"${listing ? '' : ` data-event-card="${id}"`}${wear}>${front}${back}</article>`;
    }
    // Turning a poster over keeps focus on the matching control.
    function turn(button) {
        const card = button.closest('.pp');
        if (!card) return;
        const toBack = !card.classList.contains('is-turned');
        const reduce = root.matchMedia?.(
            '(prefers-reduced-motion: reduce)',
        ).matches;
        const swap = () => {
            card.classList.toggle('is-turned', toBack);
            card.querySelector('.pp-front').hidden = toBack;
            card.querySelector('.pp-back').hidden = !toBack;
            card.querySelector('.pp-open').setAttribute(
                'aria-expanded',
                String(toBack),
            );
            (toBack
                ? card.querySelector('.pp-turn-back')
                : card.querySelector('.pp-open')
            ).focus({ preventScroll: true });
        };
        if (reduce || !card.animate) return swap();
        // A paper turn in depth: fold to the edge, swap faces, unfold.
        card.animate(
            [
                { transform: 'perspective(1100px) rotateY(0)' },
                { transform: 'perspective(1100px) rotateY(90deg) scale(1.03)' },
            ],
            { duration: 140, easing: 'cubic-bezier(.4,0,1,1)' },
        ).onfinish = () => {
            swap();
            card.animate(
                [
                    { transform: 'perspective(1100px) rotateY(-90deg) scale(1.03)' },
                    { transform: 'perspective(1100px) rotateY(0)' },
                ],
                { duration: 180, easing: 'cubic-bezier(.22,1,.36,1)' },
            );
        };
    }
    root.ExpaduPosterArt = { ink, shape, icon, tape, art, fits, poster, turn };
})(globalThis);
