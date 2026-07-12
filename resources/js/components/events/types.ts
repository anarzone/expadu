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

/** Maps an event category onto a CategoryIllustration visual key. */
export function eventIllustrationKey(category: string): string {
    return (
        {
            culture: 'event_culture',
            sports: 'event_sports',
            other: 'event_other',
        }[category] ?? category
    );
}
