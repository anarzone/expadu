/* Your next trip as one card (trip-card.css): the ring with the minutes
   until you leave, what to do, the facts as tiles. Travel's Leave now
   (app.js) and Today's plan for a trip draw it from their own data, so it
   looks the same wherever it shows (owner, 18 Oct 2026, Composer review
   canvas board 11). Titles and places come escaped; facts carry markup
   (the line badge, labels.js). */
(function (root) {
    // The card's own icons, as Travel draws them (icons.js), so it looks
    // the same on a page without Travel's set.
    const shapes = {
        calendar:
            '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 2v6m10-6v6M3 11h18m-14 4h3m4 0h3"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1"/>',
        play: '<path d="m8 5 11 7-11 7Z"/>',
    };
    const icon = (name) =>
        `<svg viewBox="0 0 24 24" aria-hidden="true">${shapes[name]}</svg>`;
    const calendar = icon('calendar');
    // The arc is what's left of the last half hour; it's full when it's time.
    function ring(now) {
        if (now.kind === 'unplanned')
            return `<span class="trip-ring is-icon" aria-hidden="true">${calendar}</span>`;
        const left = Math.max(0, now.leaveIn);
        const go = now.kind !== 'trip' || left === 0;
        const arc = go ? 100 : Math.max(8, Math.round((Math.min(left, 30) / 30) * 100));
        const text =
            now.kind !== 'trip'
                ? '!'
                : go
                  ? 'now'
                  : left >= 60
                    ? `${Math.floor(left / 60)} h`
                    : left;
        return `<span class="trip-ring" aria-hidden="true"><svg viewBox="0 0 36 36"><circle cx="18" cy="18" r="15.9" pathLength="100"></circle><circle cx="18" cy="18" r="15.9" pathLength="100" stroke-dasharray="${arc} 100"></circle></svg><span><strong>${text}</strong>${go || left >= 60 ? '' : '<small>min</small>'}</span></span>`;
    }
    // What to do and where you're going.
    const head = (id, now, title, place) =>
        `<div class="trip-head">${ring(now)}<p class="trip-title"><strong id="${id}-title">${title}</strong>${place ? `<span>${place}</span>` : ''}</p></div>`;
    // One fact as a tile you can read at a glance.
    const fact = (label, value, sub, tone = '') =>
        `<div class="trip-fact${tone ? ` is-${tone}` : ''}"><span class="trip-fact-label">${label}</span><strong>${value}</strong><small>${sub}</small></div>`;
    // What changed (a label), the facts, then a quiet line under them.
    const body = (flag, facts, note = '') =>
        `<div class="trip-body">${flag ? `<div class="trip-flags">${flag}</div>` : ''}<div class="trip-facts" style="--facts:${facts.length}">${facts.join('')}</div>${note}</div>`;
    root.TravelTripCard = { ring, head, fact, body, icon };
})(globalThis);
