import { useState } from 'react';
import type { DayProjection } from '@/types/day';

/*
 * What Today puts first (the mock's priority() in brief/content.js): plans
 * still ahead lead; with none, a first visit leads with the way into
 * Paperwork, and any other free day with ideas, or with Paperwork when there
 * are none left.
 */

export type Lead = 'agenda' | 'essentials' | 'discovery';

export type Block =
    | 'agenda'
    | 'paperwork'
    | 'city'
    | 'around'
    | 'discovery'
    | 'more'
    | 'composer'
    | 'saved'
    | 'upcoming'
    | 'support';

/*
 * Where each part goes (owner, 28 Oct 2026: the departures stay put and the
 * page fills around them). The city's help, with the departures, always
 * heads the right column (on a phone, right after the day); the ideas head
 * the middle one; Paperwork goes where there's room: under Your day when the
 * day is free, under the ideas when plans make Your day long, first on a
 * first visit. The room left under the departures takes more events (`more`).
 */

/** A phone's one column, per lead. */
export const PHONE_ORDER: Record<Lead, Block[]> = {
    agenda: [
        'agenda',
        'city',
        'around',
        'discovery',
        'paperwork',
        'composer',
        'saved',
        'upcoming',
        'support',
    ],
    essentials: [
        'paperwork',
        'agenda',
        'city',
        'discovery',
        'around',
        'composer',
        'saved',
        'upcoming',
        'support',
    ],
    discovery: [
        'agenda',
        'city',
        'discovery',
        'around',
        'paperwork',
        'composer',
        'saved',
        'upcoming',
        'support',
    ],
};

/** The three columns from 1200px (two from 701px: the day, then the city's help over the ideas). */
export const COLUMNS: Record<
    Lead,
    { day: Block[]; ideas: Block[]; city: Block[] }
> = {
    agenda: {
        day: ['agenda', 'around', 'saved', 'upcoming', 'composer'],
        ideas: ['discovery', 'paperwork', 'support'],
        city: ['city', 'more'],
    },
    essentials: {
        day: ['paperwork', 'agenda', 'around', 'saved', 'upcoming', 'composer'],
        ideas: ['discovery', 'support'],
        city: ['city', 'more'],
    },
    discovery: {
        day: ['agenda', 'around', 'paperwork', 'saved', 'upcoming', 'composer'],
        ideas: ['discovery', 'support'],
        city: ['city', 'more'],
    },
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
