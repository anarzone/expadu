/* The words for "Good for laptop work?" (the Designer's canvas, 23 Oct
   2026), shared by the place sheet, Around here's rows and Composer's
   cards: a café's own rule from its website ("No laptops at weekends"),
   with the days it names, and people's verdict by count only. */

import type { WorkTotal } from './work-answer';

const DAY_NAMES: Record<string, [string, string]> = {
    Mo: ['Monday', 'Mon'],
    Tu: ['Tuesday', 'Tue'],
    We: ['Wednesday', 'Wed'],
    Th: ['Thursday', 'Thu'],
    Fr: ['Friday', 'Fri'],
    Sa: ['Saturday', 'Sat'],
    Su: ['Sunday', 'Sun'],
};
const ORDER = Object.keys(DAY_NAMES);

/** The days an OSM-style rule names ("Sa-Su", "Mo-Fr", "Sa,Su"), in order. */
export function ruleDays(days: string | null | undefined): string[] {
    if (!days) {
        return [];
    }

    return days
        .split(/[,;]/)
        .flatMap((part) => {
            const [from, to] = part.trim().split('-');
            const a = ORDER.indexOf(from);
            const b = ORDER.indexOf(to ?? from);

            return a < 0 || b < 0 ? [] : ORDER.slice(a, b + 1);
        })
        .filter((day, i, all) => all.indexOf(day) === i);
}

/** "at weekends", "on weekdays", "on Saturdays", "on Mon, Wed". */
function daysPhrase(days: string[]): string {
    const key = days.join(',');

    if (key === 'Sa,Su') {
        return 'at weekends';
    }

    if (key === 'Mo,Tu,We,Th,Fr') {
        return 'on weekdays';
    }

    return days.length === 1
        ? `on ${DAY_NAMES[days[0]][0]}s`
        : `on ${days.map((d) => DAY_NAMES[d][1]).join(', ')}`;
}

/** "Saturday and Sunday". */
export const fullDays = (days: string[]) => {
    const names = days.map((d) => DAY_NAMES[d][0]);

    return names.length > 1
        ? `${names.slice(0, -1).join(', ')} and ${names.at(-1)}`
        : (names[0] ?? '');
};

/** The café's own rule as a title: "No laptops at weekends". */
export function ruleTitle(value: string, days: string[]): string {
    const when = days.length ? ` ${daysPhrase(days)}` : '';

    return value === 'no'
        ? `No laptops${when}`
        : value === 'yes'
          ? `Laptops welcome${when}`
          : `Laptops limited${when}`;
}

/** A people's verdict, short: "Good for laptop work · 9 of 11". */
export function verdictTitle(total: NonNullable<WorkTotal>): string {
    const words = {
        yes: 'Good for laptop work',
        no: 'Not good for laptop work',
        mixed: 'Mixed reports',
    }[total.value];

    return `${words} · ${total.yes} of ${total.total}`;
}

/** A row's laptop summary (OpenNearby rows): null when nothing is known. */
export type RowLaptop = {
    people: NonNullable<WorkTotal> | null;
    rule: { value: string; days: string | null } | null;
} | null;

/** Whether a rule's days include a date ("2026-10-24"); no days is every day. */
export function ruleApplies(days: string | null, date: string): boolean {
    const named = ruleDays(days);

    if (!named.length) {
        return true;
    }

    const day = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'][
        new Date(`${date}T12:00:00Z`).getUTCDay()
    ];

    return named.includes(day);
}

/**
 * The one tag a row shows: the café's own rule when it applies on the
 * list's day, else people's verdict, else none.
 */
export function rowLaptopTag(
    laptop: RowLaptop,
    date: string,
): { kind: 'good' | 'mixed' | 'not' | 'rule'; text: string } | null {
    if (!laptop) {
        return null;
    }

    if (laptop.rule && ruleApplies(laptop.rule.days, date)) {
        return {
            kind: 'rule',
            text: `${ruleTitle(laptop.rule.value, ruleDays(laptop.rule.days))} · café’s site`,
        };
    }

    if (!laptop.people) {
        return null;
    }

    return {
        kind: { yes: 'good', mixed: 'mixed', no: 'not' }[
            laptop.people.value
        ] as 'good' | 'mixed' | 'not',
        text: verdictTitle(laptop.people),
    };
}
