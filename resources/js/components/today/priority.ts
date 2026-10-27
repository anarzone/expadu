import { useState } from 'react';
import type { DayProjection } from '@/types/day';

/*
 * What Today puts first (the mock's priority() in brief/content.js): plans
 * still ahead lead; with none, a first visit leads with the city's help
 * and the way into Paperwork, and any other free day with ideas, or with
 * the city's help when there are none left.
 */

export type Lead = 'agenda' | 'essentials' | 'discovery';

export type Block =
    | 'agenda'
    | 'essentials'
    | 'around'
    | 'discovery'
    | 'composer'
    | 'saved'
    | 'upcoming'
    | 'support';

/** A phone's one column, per lead (the mock's priority().order). */
export const PHONE_ORDER: Record<Lead, Block[]> = {
    agenda: [
        'agenda',
        'essentials',
        'around',
        'discovery',
        'composer',
        'saved',
        'upcoming',
        'support',
    ],
    essentials: [
        'essentials',
        'agenda',
        'around',
        'discovery',
        'composer',
        'saved',
        'upcoming',
        'support',
    ],
    // A free day opens with ideas, then the way to add a plan.
    discovery: [
        'discovery',
        'agenda',
        'essentials',
        'around',
        'composer',
        'saved',
        'upcoming',
        'support',
    ],
};

const leadOf = (
    day: DayProjection,
    firstVisit: boolean,
    ideas: boolean,
): Lead => {
    if (day.items.some((item) => !item.completed && item.state !== 'elapsed')) {
        return 'agenda';
    }

    // A free day opens with ideas. With none left (late in the evening), the
    // city's help leads instead, as on a first visit, not an empty heading.
    return firstVisit || ideas === false ? 'essentials' : 'discovery';
};

/**
 * The lead, steady while the page is read: it's set when the page loads,
 * when whether there are ideas left changes, and when the person changes
 * their plans (adds, removes or completes one), never because the clock
 * passed a plan's end or something was saved, so the sections don't move
 * under the reader (the mock's arrange() runs on page actions, "never on a
 * clock or save sync").
 */
export function useSteadyLead(
    day: DayProjection,
    firstVisit: boolean,
    ideas: boolean,
): Lead {
    const key = `${day.items.map((item) => `${item.id}:${item.completed}`).join('|')}#${ideas}`;
    const [steady, setSteady] = useState(() => ({
        key,
        lead: leadOf(day, firstVisit, ideas),
    }));

    if (steady.key !== key) {
        const next = { key, lead: leadOf(day, firstVisit, ideas) };
        setSteady(next);

        return next.lead;
    }

    return steady.lead;
}
