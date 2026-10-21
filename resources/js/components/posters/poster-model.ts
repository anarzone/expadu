/* Posters set from an event's own listing: no photograph, nothing added.
   The hour picks the ink and the category picks the shape, so a listing
   without usable media still gets a recognisable, honest picture.
   Ported from the mock's events-next/poster-art.js. */

import type { IconName } from '@/components/ui/icon';

export type PosterInk = 'day' | 'evening' | 'night' | 'paper';
export type PosterFamily = 'rings' | 'checks' | 'waves' | 'bubbles' | 'dots';

/** One listing as a poster reads it. Times are Cologne wall-clock. */
export type PosterEvent = {
    id: string;
    /** YYYY-MM-DD in Europe/Berlin, or null when the listing has no date. */
    date: string | null;
    /** HH:MM, or null when the listing gives no time. */
    start: string | null;
    /** HH:MM, or null when the listing gives no end. */
    end: string | null;
    /** The title as listed (usually German). */
    title: string;
    /** The listed title's language (e.g. "de"), so it hyphenates as German. */
    titleLang: string | null;
    /** Its English meaning, only when it differs from the listed title. */
    titleEnglish: string | null;
    /** The app's category value (EventCategory). */
    category: string;
    venue: string | null;
    area: string | null;
    price: string | null;
    language: string | null;
    /** The venue is outdoors (place_features.setting), never guessed. */
    outdoor?: boolean;
    accessibility?: string | null;
};

/** The fields of the events card contract (EventOccurrencePresenter) a poster reads. */
export type PosterSource = {
    id: number | string;
    occurrence_start: string;
    occurrence_end: string | null;
    title: string;
    title_original?: string | null;
    title_lang?: string | null;
    time_listed?: boolean;
    category: string;
    price_text: string | null;
    language?: string | null;
    venue: { name: string | null; veedel: string | null };
};

const BERLIN = 'Europe/Berlin';

const clockFormat = new Intl.DateTimeFormat('en-GB', {
    timeZone: BERLIN,
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});
const dateFormat = new Intl.DateTimeFormat('en-CA', {
    timeZone: BERLIN,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});

/** HH:MM in Cologne for an ISO instant. */
export function berlinClock(iso: string): string {
    return clockFormat.format(new Date(iso));
}

/** YYYY-MM-DD in Cologne for an instant (default: now). */
export function berlinDate(at: string | Date = new Date()): string {
    return dateFormat.format(typeof at === 'string' ? new Date(at) : at);
}

/** "Free" from the feed's lower-case marker; any other text as listed. */
function admission(price: string | null): string | null {
    if (!price) {
        return null;
    }

    return price.trim().toLowerCase() === 'free' ? 'Free' : price;
}

/** A card-contract occurrence as a poster. Nothing is filled in: a listing
    without a time gets none, and only a listed end is shown. */
export function posterEvent(source: PosterSource): PosterEvent {
    const timed = source.time_listed !== false;
    const original = source.title_original?.trim() || source.title;
    const english =
        source.title_original && source.title !== source.title_original
            ? source.title
            : null;

    return {
        id: String(source.id),
        date: berlinDate(source.occurrence_start),
        start: timed ? berlinClock(source.occurrence_start) : null,
        end:
            timed && source.occurrence_end
                ? berlinClock(source.occurrence_end)
                : null,
        title: original,
        titleLang: source.title_original ? (source.title_lang ?? null) : null,
        titleEnglish: english,
        category: source.category,
        venue: source.venue.name,
        area: source.venue.veedel,
        price: admission(source.price_text),
        language: source.language ?? null,
    };
}

/* Before 17:00 is daylight yellow, until 20:00 evening purple, later night
   navy. Small hours (before 05:00) are night too: the mock never had them,
   and a 01:00 party in daylight yellow would read wrong. A listing without
   a time is plain paper. */
export function posterInk(start: string | null | undefined): PosterInk {
    if (!start || !/^\d\d:\d\d$/.test(start)) {
        return 'paper';
    }

    const hour = Number(start.slice(0, 2));

    if (hour < 5) {
        return 'night';
    }

    return hour < 17 ? 'day' : hour < 20 ? 'evening' : 'night';
}

/* The mock's four families are Culture (rings), Games (checks), Outdoors
   (waves) and Language (speech bubbles). The app's seven categories map
   onto them by what happens there:
     culture, party              → rings   (stage, music, a night out)
     sports                      → checks  (games and sport)
     language_exchange,
     stammtisch, intl_meetup     → bubbles (people meeting to talk)
     other                       → dots    (the calm fallback, ~71% of listings)
   Waves belong to outdoor venues and only come from the venue's recorded
   setting, never from the category or the title. */
const families: Record<string, PosterFamily> = {
    culture: 'rings',
    party: 'rings',
    sports: 'checks',
    language_exchange: 'bubbles',
    stammtisch: 'bubbles',
    intl_meetup: 'bubbles',
};

export function posterFamily(
    category: string | null | undefined,
    { outdoor = false }: { outdoor?: boolean } = {},
): PosterFamily {
    if (outdoor) {
        return 'waves';
    }

    return (category && families[category]) || 'dots';
}

/* One icon and one name per category on cards, chips and the sheet.
   "Other" reads as plain "Event". */
const categories: Record<string, { label: string; icon: IconName }> = {
    culture: { label: 'Culture', icon: 'museum' },
    party: { label: 'Party', icon: 'spark' },
    sports: { label: 'Sports', icon: 'ball' },
    language_exchange: { label: 'Language exchange', icon: 'chat' },
    stammtisch: { label: 'Stammtisch', icon: 'coffee' },
    intl_meetup: { label: 'International', icon: 'people' },
};

export function posterCategory(category: string | null | undefined): {
    label: string;
    icon: IconName;
} {
    return (
        (category && categories[category]) || {
            label: 'Event',
            icon: 'calendar',
        }
    );
}

const at = (date: string) => new Date(`${date}T12:00:00Z`);
const dayMonth = (date: string) =>
    at(date).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });

/** "Tue 6 Oct". */
export function posterWeekday(date: string): string {
    return at(date).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });
}

/** "Tonight · 30 Sept", "Past · 8 Sept" or "Tue 6 Oct". */
export function posterTape(
    event: Pick<PosterEvent, 'date' | 'start'>,
    today: string,
): string {
    if (!event.date) {
        return 'Date not listed';
    }

    if (event.date < today) {
        return `Past · ${dayMonth(event.date)}`;
    }

    if (event.date === today) {
        return `${event.start && event.start >= '17:00' ? 'Tonight' : 'Today'} · ${dayMonth(event.date)}`;
    }

    return posterWeekday(event.date);
}

export const isPast = (event: Pick<PosterEvent, 'date'>, today: string) =>
    !!event.date && event.date < today;

/** A past poster wears in one of three ways, always the same for it. */
export const posterWear = (id: string) =>
    [...id].reduce((sum, c) => sum + c.charCodeAt(0), 0) % 3;
