/* A visit against the person's day (the mock's connected/handoffs.js
   suggestTimes and visit-timeline.js build): one-hour times that fit
   around what's planned, and the day as a timeline with the visit shown
   in it before it's added. The preview never adds or moves anything. */

export type DayEntry = {
    id: string;
    title: string;
    start: string | null;
    end: string | null;
};

export type DayRow =
    | { gap: number }
    | (DayEntry & {
          preview: boolean;
          current: boolean;
          duration: string;
          overlaps: string[];
      });

const CLOCK = /^([01]\d|2[0-3]):[0-5]\d$/;

export const minutesOf = (value: string | null): number | null => {
    if (!value || !CLOCK.test(value)) {
        return null;
    }

    const [hour, minute] = value.split(':').map(Number);

    return hour * 60 + minute;
};

const clock = (value: number) =>
    `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;

/** "45 min", "1 hr", "1 hr 30 min" */
export const duration = (value: number) =>
    value < 60
        ? `${value} min`
        : `${Math.floor(value / 60)} hr${value % 60 ? ` ${value % 60} min` : ''}`;

/**
 * Up to three one-hour times between 09:00 and 21:00 that overlap nothing
 * planned, starting on the half hour from `earliest` (minutes): the ones
 * nearest 10:00, 14:00 and 18:00, at least an hour apart.
 */
export function suggestTimes(
    items: DayEntry[],
    earliest: number,
): { start: string; end: string }[] {
    const occupied = items
        .filter((item) => minutesOf(item.start) !== null)
        .map((item) => [
            minutesOf(item.start)!,
            minutesOf(item.end) ?? 24 * 60,
        ]);
    const available: number[] = [];

    for (
        let start = Math.ceil(Math.max(9 * 60, earliest) / 30) * 30;
        start + 60 <= 21 * 60;
        start += 30
    ) {
        if (
            !occupied.some(
                ([from, until]) => start < until && start + 60 > from,
            )
        ) {
            available.push(start);
        }
    }

    const selected: number[] = [];

    for (const preferred of [10 * 60, 14 * 60, 18 * 60]) {
        const start = available
            .filter((value) =>
                selected.every((other) => Math.abs(value - other) >= 60),
            )
            .sort(
                (a, b) =>
                    Math.abs(a - preferred) - Math.abs(b - preferred) || a - b,
            )[0];

        if (start !== undefined) {
            selected.push(start);
        }
    }

    return selected
        .sort((a, b) => a - b)
        .map((start) => ({ start: clock(start), end: clock(start + 60) }));
}

/**
 * The day in time order, with the free time between, and the visit as a
 * preview where it would go (unless it's already in the day). A preview
 * names what it overlaps.
 */
export function dayTimeline(
    items: DayEntry[],
    visit: DayEntry,
): { rows: DayRow[]; existing: boolean; preview: boolean } {
    const existing = items.some((item) => item.id === visit.id);
    const from = minutesOf(visit.start);
    const until = minutesOf(visit.end);
    const preview =
        !existing && from !== null && until !== null && until > from;
    const entries = items.map((item) => ({
        ...item,
        preview: false,
        current: item.id === visit.id,
    }));

    if (preview) {
        entries.push({ ...visit, preview: true, current: false });
    }

    entries.sort(
        (a, b) =>
            (minutesOf(a.start) ?? Infinity) - (minutesOf(b.start) ?? Infinity),
    );

    const rows: DayRow[] = [];
    const unscheduled = items.some((item) => minutesOf(item.start) === null);
    let occupiedUntil: number | null = null;

    for (const entry of entries) {
        const start = minutesOf(entry.start);
        const end = minutesOf(entry.end);
        const knownEnd = start !== null && end !== null && end > start;

        if (
            !unscheduled &&
            occupiedUntil !== null &&
            start !== null &&
            start > occupiedUntil
        ) {
            rows.push({ gap: start - occupiedUntil });
        }

        const overlaps = entry.preview
            ? items
                  .filter((other) => {
                      const a = minutesOf(other.start);
                      const b = minutesOf(other.end);

                      return (
                          a !== null &&
                          start! < (b !== null && b > a ? b : 1440) &&
                          a < end!
                      );
                  })
                  .map((other) => other.title)
            : [];

        rows.push({
            ...entry,
            duration: knownEnd ? duration(end - start) : '',
            overlaps,
        });

        if (start !== null) {
            occupiedUntil = Math.max(occupiedUntil ?? 0, knownEnd ? end : 1440);
        }
    }

    return { rows, existing, preview };
}
