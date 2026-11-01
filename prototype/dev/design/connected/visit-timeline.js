/* A visit preview uses the saved day; it never adds or reschedules an activity. */
(function (root) {
    const minutes = (value) => {
        if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(value || '')) return null;
        const [hour, minute] = value.split(':').map(Number);
        return hour * 60 + minute;
    };
    const duration = (value) =>
        value < 60
            ? `${value} min`
            : `${Math.floor(value / 60)} hr${value % 60 ? ` ${value % 60} min` : ''}`;
    const same = (a, b) =>
        a.id != null &&
        b.id != null &&
        String(a.id).replace(/^event:/, '') ===
            String(b.id).replace(/^event:/, '');
    function build(items, visit) {
        const original = items.find((item) => same(item, visit));
        const existing = !!original;
        const start = minutes(visit.start),
            end = minutes(visit.end);
        const preview =
            !existing &&
            !visit.previewHidden &&
            start !== null &&
            end !== null &&
            end > start;
        const editing =
            existing &&
            visit.editing &&
            start !== null &&
            end !== null &&
            end > start &&
            (original.start !== visit.start || original.end !== visit.end);
        const entries = items.map((item) => ({
            ...item,
            current: same(item, visit),
            ...(same(item, visit) && editing
                ? {
                      start: visit.start,
                      end: visit.end,
                      preview: true,
                      editing: true,
                      previous: `${item.start}–${item.end}`,
                  }
                : {}),
        }));
        if (preview) entries.push({ ...visit, preview: true });
        entries.sort(
            (a, b) =>
                (minutes(a.start) ?? Infinity) - (minutes(b.start) ?? Infinity),
        );
        const rows = [];
        let occupiedUntil = null;
        const hasUnscheduled = items.some(
            (item) => minutes(item.start) === null,
        );
        for (const item of entries) {
            const from = minutes(item.start),
                until = minutes(item.end);
            const knownEnd = from !== null && until !== null && until > from;
            if (
                !hasUnscheduled &&
                occupiedUntil !== null &&
                from !== null &&
                from > occupiedUntil
            )
                rows.push({ gap: from - occupiedUntil });
            const overlaps = item.preview
                ? items
                      .filter((other) => {
                          const a = minutes(other.start),
                              b = minutes(other.end);
                          return (
                              !same(other, item) &&
                              a !== null &&
                              from < (b !== null && b > a ? b : 1440) &&
                              a < until
                          );
                      })
                      .map((other) => other.title)
                : [];
            rows.push({
                ...item,
                duration: knownEnd ? duration(until - from) : '',
                overlaps,
            });
            if (from !== null)
                occupiedUntil = Math.max(
                    occupiedUntil ?? 0,
                    knownEnd ? until : 1440,
                );
        }
        return { rows, existing, preview };
    }
    function render(items, visit, esc, confirmingId) {
        const { rows, existing, preview } = build(items, visit);
        const eventIcons = visit.type === 'event' && root.ExpaduIcon;
        if (!rows.length && visit.previewHidden) return '';
        if (!rows.length && eventIcons)
            return `<div class="event-day-empty">${eventIcons('calendar')}<div><strong>Your day is open</strong><p>Choose a time to see your visit here.</p></div></div>`;
        if (!rows.length)
            return '<p class="visit-day-empty">Nothing planned here yet. Choose a time to see this visit in your day.</p>';
        return `<ol class="visit-day-timeline" aria-label="Day timeline">${rows
            .map((row) => {
                if (row.gap)
                    return `<li class="visit-day-gap"><span>${duration(row.gap)} free</span></li>`;
                const meta = [
                    row.duration || 'End time to check',
                    row.editing
                        ? 'Unsaved change'
                        : row.preview
                          ? 'Preview'
                          : row.planStatus !== 'Confirmed'
                            ? row.planStatus
                            : '',
                    row.editing
                        ? `Was ${row.previous}`
                        : row.current
                          ? 'Already in your day'
                          : '',
                ].filter(Boolean);
                const removable = !row.editing;
                const confirming =
                    removable &&
                    confirmingId != null &&
                    String(row.id) === String(confirmingId);
                const confirmation = confirming
                    ? `<div class="visit-day-delete"><button type="button" data-confirm-day-remove="${esc(row.id)}" aria-label="Delete ${esc(row.title)}${row.preview ? ' preview' : ' from this day'}" title="Delete">${root.ExpaduIcon ? root.ExpaduIcon('trash') : 'Delete'}</button></div>`
                    : '';
                const action = row.editing
                    ? `<button type="button" class="visit-day-action" data-reset-visit aria-label="Reset visit times" title="Reset visit times">${eventIcons ? eventIcons('back') : 'Reset'}</button>`
                    : '';
                const interaction = removable
                    ? ` data-day-swipe="${esc(row.id)}" tabindex="0" role="button" aria-expanded="${confirming}" aria-label="${esc(row.title)}, ${esc(row.start || 'Time not set')}${row.end ? '–' + esc(row.end) : ''}. ${confirming ? 'Hide' : 'Show'} delete action" aria-description="Swipe left or double-tap to show Delete. Press Enter or Space with a keyboard. Swipe right, tap the activity or press Escape to close."`
                    : '';
                return `<li class="visit-day-entry${row.preview ? ' is-preview' : ''}${row.current ? ' is-current' : ''}"><div class="visit-day-time"><time>${esc(row.start || '—')}</time><small>${esc(row.end || 'Not set')}${row.endEstimated ? ' est.' : ''}</small></div><div class="visit-day-rail${confirming ? ' is-revealed' : ''}" data-day-id="${esc(row.id)}"><div class="visit-day-card"${interaction}><div class="visit-day-description"><strong>${esc(row.title)}</strong><div class="visit-day-meta">${meta.map((text, index) => `<span${row.preview && index === 1 ? ' class="visit-day-state"' : ''}>${esc(text)}</span>`).join('')}</div>${row.overlaps.length ? `<small class="visit-day-overlap">Overlaps ${row.overlaps.map(esc).join(', ')}</small>` : ''}</div>${action}</div>${confirmation}</div></li>`;
            })
            .join(
                '',
            )}</ol>${!existing && !preview && !visit.previewHidden ? '<p class="visit-day-prompt">Choose a start and end time to preview your visit here.</p>' : ''}`;
    }
    root.ExpaduVisitTimeline = { build, render };
    if (typeof module !== 'undefined')
        module.exports = root.ExpaduVisitTimeline;
})(globalThis);
