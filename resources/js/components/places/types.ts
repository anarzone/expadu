export type PlaceFact = { label: string; value: string };

export type Place = {
    id: number;
    name: string;
    category: string;
    fine_label: string | null;
    emoji: string | null;
    veedel: string | null;
    lat: number;
    lng: number;
    photo_url: string | null;
    distance_min: number | null;
    open_now: boolean | null;
    opening_hours_text: string | null;
    price_text: string | null;
    feature_chips: string[];
    tip: string | null;
    tip_is_generic: boolean;
    cluster_size: number;
    transit_hint: string | null;
    facts: PlaceFact[];
};

export type VeedelOption = {
    name: string;
    count: number;
    photo_url: string | null;
};
