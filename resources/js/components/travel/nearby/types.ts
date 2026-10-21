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
        lines: string[];
        source: string | null;
        since: string | null;
        brief?: NoticeBrief | null;
    }[];
    /** A `?stop=` name KVB doesn't know: the closest station shows instead. */
    unknown_stop?: string | null;
};

export type FavouriteStop = {
    name: string;
    lat: number | null;
    lng: number | null;
    lines: string[];
};

/**
 * KVB's notice in plain English (App\Transit\NoticeBriefWriter), when one
 * is written: Travel shows it in place of KVB's own title. Its impact isn't
 * shown (the mock draws no label).
 */
export type NoticeBrief = {
    headline: string;
    text: string | null;
    impact?: string | null;
};

/** A notice's words: the brief's headline and text, else KVB's own title. */
export const noticeWords = (notice: {
    title: string;
    brief?: NoticeBrief | null;
}): { headline: string; text: string | null } =>
    notice.brief
        ? { headline: notice.brief.headline, text: notice.brief.text }
        : { headline: notice.title, text: null };
