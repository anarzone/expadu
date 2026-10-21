'use strict';
/* A line through a stop, as Travel's Nearby draws it (owner, 14 Oct 2026,
   Travel directions canvas row 16): its badge, the stop's dot on the line's
   track, and at each end where it goes, via which stops and when. Nearby
   (nearby-ui.js) and pages outside Travel build their strips here, so
   departures look the same everywhere (Today's Check departures; owner,
   18 Oct 2026, Composer review canvas board 12). Styles: strip.css. */
(function (root) {
    const safe = (value) =>
        String(value ?? '').replace(
            /[&<>"']/g,
            (c) =>
                ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                })[c],
        );
    const arrow = (back) =>
        `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${back ? 'M20 12H4m6-6-6 6 6 6' : 'M4 12h16m-6-6 6 6-6 6'}"></path></svg>`;
    // "now", "3 min", or the clock once it's an hour or more away.
    function when(minutes, clock) {
        return minutes <= 0
            ? { big: 'now', unit: '' }
            : minutes >= 60
              ? { big: clock, unit: '' }
              : { big: String(minutes), unit: 'min' };
    }
    // "Now" is bold in the line's colour, not a pill (owner chose card B,
    // 11 Oct 2026); a paused time is quiet.
    function time(t, { paused = false } = {}) {
        if (t.big === 'now' && !paused)
            return `<span class="nearby-time is-now"><i aria-hidden="true"></i><strong>Now</strong></span>`;
        return `<span class="nearby-time${paused ? ' is-paused' : ''}"><strong>${safe(t.big)}</strong>${t.unit ? `<small>${safe(t.unit)}</small>` : ''}</span>`;
    }
    // One end: { dest, via: [], when: markup }; none says the other way.
    function end(dir, side, empty) {
        if (!dir)
            return `<span class="strip-end is-${side} is-empty"><small>${safe(empty)}</small></span>`;
        return `<span class="strip-end is-${side}"><span class="strip-dest">${side === 'left' ? arrow(true) : ''}<span>${safe(dir.dest)}</span>${side === 'right' ? arrow(false) : ''}</span>${dir.via?.length ? `<small class="strip-via">via ${safe(dir.via.join(' · '))}</small>` : ''}<span class="strip-when">${dir.when}</span></span>`;
    }
    // The sooner way on the right, the other way on the left.
    function line({
        tag = 'button',
        attrs = '',
        selected = false,
        look,
        badge,
        first,
        second,
        empty = 'Other way: not recorded',
        extra = '',
    }) {
        return `<${tag} class="nearby-strip${selected ? ' is-selected' : ''}" ${attrs} style="--line:${look.colour};--tint:${look.tint}"><span class="strip-badge">${badge}</span>${end(second, 'left', empty)}<span class="strip-track" aria-hidden="true"><i></i><b></b></span>${end(first, 'right', empty)}${extra}</${tag}>`;
    }
    root.TravelStrip = { line, time, when };
})(globalThis);
