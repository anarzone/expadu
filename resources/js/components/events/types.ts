export type EventOccurrence = {
    id: number;
    occurrence_start: string;
    occurrence_end: string | null;
    title: string;
    /** The title as listed, and its language; `title` is its English meaning when there is one. */
    title_original?: string;
    title_lang?: string | null;
    /** False for a listing that gives a date but no time. */
    time_listed?: boolean;
    language?: string | null;
    category: string;
    category_label: string;
    emoji: string;
    meta: string;
    photo_url: string | null;
    photo_attribution: string | null;
    photo_source_url: string | null;
    photo_license_url: string | null;
    chips: string[];
    tip: string | null;
    summary: string | null;
    price_text: string | null;
    venue: {
        name: string | null;
        veedel: string | null;
        lat: number | null;
        lng: number | null;
        place_id: number | null;
        place_name: string | null;
        /** The venue's recorded setting and step-free access (Events). */
        setting?: 'indoor' | 'outdoor' | null;
        wheelchair?: string | null;
    };
    source_url: string | null;
    /** Where the listing came from (e.g. stadt-koeln.de). */
    source?: string | null;
    /** When it was last checked there, YYYY-MM-DD. */
    checked_at?: string | null;
    venue_id?: number | null;
    is_recurring: boolean;
    recurrence_text: string | null;
    verified: boolean;
    distance_km: number | null;
    travel_min: number | null;
};

export type EventReminderEntry = {
    id: number;
    event_id: number;
    occurrence_start: string;
    offset_minutes: number;
};
