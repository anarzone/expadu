/** Travel's Nearby station, as App\Transit\NearbyStation sends it. */

export type TimeBasis = 'realtime' | 'scheduled' | 'stale' | 'unavailable';

export type StationRun = {
    id: string;
    /** When it leaves, ISO with the Europe/Berlin offset (live when the feed is). */
    at: string;
    time: string;
    minutes: number;
    scheduled_at: string | null;
    delay: number;
    cancelled: boolean;
    time_basis: TimeBasis;
    /** The notice that explains it (an id in the station's `notices`), when one matches. */
    notice?: string | null;
};

export type StationDir = {
    id: string;
    dest: string;
    via: string[];
    direction: number | null;
    next: StationRun | null;
    later: StationRun[];
    /** False when the feed had only this one departure: later ones aren't recorded. */
    later_recorded: boolean;
    cancelled: boolean;
    disrupted: boolean;
    /** Its usual minutes between runs here now, by the timetable; null when it doesn't say. */
    headway?: number | null;
};

export type StationLine = {
    line: string;
    mode: 'tram' | 'bus' | 'rail';
    area: string;
    dirs: StationDir[];
};

export type StationArea = {
    id: string;
    lines: string[];
    mode: 'tram' | 'bus' | 'rail';
    places: { lat: number; lng: number }[];
    lat: number | null;
    lng: number | null;
    /** Where to stand isn't recorded yet (plan call #9). */
    platform: string | null;
};

export type Station = {
    name: string;
    kind: 'closest' | 'picked';
    /** How the closest station was found: from a fix of the last few minutes (fresh), or from where you last were. */
    located?: {
        fresh: boolean;
        source: string;
        /** What it's near: Home, or a Veedel ('place' and 'area' sources). */
        label?: string | null;
        at: string | null;
    } | null;
    station: number | null;
    lat: number | null;
    lng: number | null;
    walk_min: number | null;
    source: string;
    time_basis: TimeBasis;
    is_stale: boolean;
    observed_at: string | null;
    generated_at: string;
    areas: StationArea[];
    lines: StationLine[];
    lifts: {
        known: boolean;
        out: { id: string; label: string; since: string | null }[];
    };
    notices: {
        id: string;
        title: string;
        severity: string;
        /** A major line problem (a headline) or an everyday one (small, in place). */
        scale?: 'major' | 'minor';
        /** Why (NoticeScale): suspended, cut_short, replacement, split, strike, graded, many_lines, diverted, stops_skipped, stop_moved, delays; not_started (announced ahead). */
        reasons?: string[];
        lines: string[];
        source: string | null;
        since: string | null;
        brief?: NoticeBrief | null;
        /** KVB's own text (German), for when there's no brief. */
        details?: string | null;
        /** KVB's page for the notice; null when it isn't KVB's. */
        url?: string | null;
    }[];
    /** A line in trouble here by its own runs, one way: mostly cancelled in the next hour, or 15+ min late (NearbyStation::trouble). */
    trouble?: {
        line: string;
        mode: 'tram' | 'bus' | 'rail';
        dest: string;
        kind: 'cancelled' | 'late';
        count: number;
        of: number;
        runs: { time: string; delay: number; cancelled: boolean }[];
    }[];
    /** A `?stop=` name KVB doesn't know: the closest station shows instead. */
    unknown_stop?: string | null;
    /** Who gave the board something it carries, as their licence asks. */
    credits?: {
        for: string;
        name: string;
        url: string;
        licence: string;
        licence_url: string;
    }[];
};

export type FavouriteStop = {
    name: string;
    lat: number | null;
    lng: number | null;
    lines: string[];
};

/** Whether a closest station is closest to where you are now. */
export const closestNow = (station: Pick<Station, 'kind' | 'located'>) =>
    station.kind === 'closest' && station.located?.fresh !== false;

/**
 * How a closest station was found, in words: "Closest" from a fix of the
 * last few minutes; near Home or a Veedel when found from one of those;
 * else near where you last were, and how long ago (nothing fake: owner,
 * 21 Oct 2026).
 */
export function closestWords(
    station: Pick<Station, 'kind' | 'located'>,
    now: number = Date.now(),
): string | null {
    if (station.kind !== 'closest') {
        return null;
    }

    if (closestNow(station)) {
        return 'Closest';
    }

    const { source, label } = station.located ?? {};

    if ((source === 'place' || source === 'area') && label) {
        return `Near ${label}`;
    }

    const at = station.located?.at ? Date.parse(station.located.at) : NaN;

    if (!Number.isFinite(at)) {
        return 'Near your last location';
    }

    const minutes = Math.max(1, Math.round((now - at) / 60_000));

    return `Near your last location · ${minutes < 60 ? `${minutes} min` : `${Math.round(minutes / 60)} h`} ago`;
}

/**
 * KVB's notice in plain English (App\Transit\NoticeBriefWriter), when one
 * is written: Travel shows it in place of KVB's own title. Its impact isn't
 * shown (the mock draws no label).
 */
export type NoticeBrief = {
    headline: string;
    text: string | null;
    impact?: string | null;
    /** A brief, or (when no brief passed its checks) a literal translation. */
    kind?: 'brief' | 'translation';
};

/**
 * A notice's words: the brief's headline and text (English), else KVB's own
 * title and text, said in German as they are (`lang` marks which).
 */
export const noticeWords = (notice: {
    title: string;
    brief?: NoticeBrief | null;
    details?: string | null;
}): { headline: string; text: string | null; lang: 'en' | 'de' } =>
    notice.brief
        ? {
              headline: notice.brief.headline,
              text: notice.brief.text,
              lang: 'en',
          }
        : { headline: notice.title, text: notice.details ?? null, lang: 'de' };
