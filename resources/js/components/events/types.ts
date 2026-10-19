export type EventOccurrence = {
    id: number;
    occurrence_start: string;
    occurrence_end: string | null;
    title: string;
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
    };
    source_url: string | null;
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
