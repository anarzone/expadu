/* The Events page's filters (the mock's events-next event-model.js): one
   day, narrowed by a search, a category, an area, Free and Saved, in an
   order, measured from a starting point. They live in the URL, so a deep
   link opens the same view; the day's listings are filtered here, as the
   mock does, and api/events/other-day matches the same way. */

import type { EventOccurrence } from '@/components/events/types';
import { posterCategory } from '@/components/posters/poster-model';
import type { IconName } from '@/components/ui/icon';

export type EventSort = 'soonest' | 'evening' | 'closest';

export type EventFilters = {
    q: string;
    category: string | null;
    area: string | null;
    free: boolean;
    saved: boolean;
    sort: EventSort;
    /** The starting point, only as a district, stop or venue's own name or
        a saved place's key (home, work); never a typed address. */
    from: string | null;
};

export const noFilters: EventFilters = {
    q: '',
    category: null,
    area: null,
    free: false,
    saved: false,
    sort: 'soonest',
    from: null,
};

/** Anything that narrows the wall (sort and the starting point don't). */
export const narrowed = (f: EventFilters) =>
    !!(f.q.trim() || f.category || f.area || f.free || f.saved);

/** Anything a reset would clear. */
export const anyFilter = (f: EventFilters) =>
    narrowed(f) || f.sort !== 'soonest';

/** The page's URL for a day and its filters (defaults left out). */
export function eventsUrl(date: string, f: EventFilters): string {
    const params = new URLSearchParams({ date });

    if (f.q.trim()) {
        params.set('q', f.q.trim());
    }

    if (f.category) {
        params.set('category', f.category);
    }

    if (f.area) {
        params.set('area', f.area);
    }

    if (f.free) {
        params.set('free', '1');
    }

    if (f.saved) {
        params.set('saved', '1');
    }

    if (f.sort !== 'soonest') {
        params.set('sort', f.sort);
    }

    if (f.from) {
        params.set('from', f.from);
    }

    return `/events?${params.toString()}`;
}

const words = (q: string) =>
    q.trim().toLowerCase().split(/\s+/).filter(Boolean);

/** Whether a listing passes the filters; `savedKeys` are the person's own. */
export function matches(
    o: EventOccurrence,
    f: EventFilters,
    key: string,
    savedKeys: Set<string>,
): boolean {
    if (f.category && o.category !== f.category) {
        return false;
    }

    if (f.area && o.venue.veedel !== f.area) {
        return false;
    }

    if (f.free && !o.chips.includes('free')) {
        return false;
    }

    if (f.saved && !savedKeys.has(key)) {
        return false;
    }

    const hay = [
        o.title,
        o.title_original,
        o.venue.name,
        o.venue.veedel,
        o.category_label,
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    return words(f.q).every((w) => hay.includes(w));
}

/** The categories a day offers, in the app's order, with the chosen one. */
const ORDER = [
    'culture',
    'party',
    'sports',
    'language_exchange',
    'stammtisch',
    'intl_meetup',
    'other',
];

export function dayCategories(
    listings: EventOccurrence[],
    chosen: string | null,
): { value: string; label: string; icon: IconName }[] {
    const present = new Set(listings.map((o) => o.category));

    if (chosen) {
        present.add(chosen);
    }

    return ORDER.filter((c) => present.has(c)).map((value) => ({
        value,
        ...posterCategory(value),
    }));
}

/** The areas a day's listings are in, A to Z, with the chosen one. */
export function dayAreas(
    listings: EventOccurrence[],
    chosen: string | null,
): string[] {
    const areas = new Set(
        listings.map((o) => o.venue.veedel).filter((v): v is string => !!v),
    );

    if (chosen) {
        areas.add(chosen);
    }

    return [...areas].sort((a, b) => a.localeCompare(b, 'de'));
}
