/**
 * Cologne-time helpers. Expadu is a Cologne-only app, so every daypart the
 * composer reasons about ("afternoon", "evening") is a wall-clock time in
 * Europe/Berlin — never the device's local timezone. Doing the hour math with
 * the browser's own Date (setHours/getHours) shifts and mislabels the window
 * for anyone whose phone is still on another timezone: a just-arrived user on
 * New York time picking "afternoon" would otherwise compose a Cologne evening.
 *
 * No date library — Intl.DateTimeFormat with an explicit timeZone is enough.
 */

const BERLIN = 'Europe/Berlin';

type WallClock = {
    year: number;
    month: number; // 1-12
    day: number;
    hour: number; // 0-23
    minute: number;
    second: number;
};

/** The wall-clock parts of an instant as seen in Cologne. */
function berlinParts(date: Date): WallClock {
    const dtf = new Intl.DateTimeFormat('en-US', {
        timeZone: BERLIN,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    });
    const p = Object.fromEntries(
        dtf.formatToParts(date).map((part) => [part.type, part.value]),
    ) as Record<string, string>;

    return {
        year: Number(p.year),
        month: Number(p.month),
        day: Number(p.day),
        // Some engines render midnight as '24' — fold it back to 0.
        hour: p.hour === '24' ? 0 : Number(p.hour),
        minute: Number(p.minute),
        second: Number(p.second),
    };
}

/** Europe/Berlin's UTC offset (minutes) at a given instant — CET or CEST. */
function berlinOffsetMinutes(date: Date): number {
    const p = berlinParts(date);
    const asUtc = Date.UTC(
        p.year,
        p.month - 1,
        p.day,
        p.hour,
        p.minute,
        p.second,
    );

    return (asUtc - date.getTime()) / 60_000;
}

/** The Cologne wall-clock hour (0-23) of an ISO instant. */
export function berlinHour(iso: string): number {
    return berlinParts(new Date(iso)).hour;
}

/**
 * The ISO instant for `hour:00:00` Cologne time on the same Cologne day as
 * `iso`, regardless of the device timezone. Builds the wall-clock time then
 * corrects by Berlin's offset at that instant, so CET/CEST are both handled.
 */
export function berlinTimeOnDay(iso: string, hour: number): string {
    const { year, month, day } = berlinParts(new Date(iso));
    // Treat the target wall-clock as if it were UTC, then subtract the Berlin
    // offset at that instant to land on the real UTC moment.
    const guess = Date.UTC(year, month - 1, day, hour, 0, 0);

    return new Date(
        guess - berlinOffsetMinutes(new Date(guess)) * 60_000,
    ).toISOString();
}
