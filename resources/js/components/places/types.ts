export type PlaceFact = { label: string; value: string };

export type Place = {
    id: number;
    name: string;
    category: string;
    fine_label: string | null;
    emoji: string | null;
    veedel: string | null;
    park: string | null;
    lat: number;
    lng: number;
    photo_url: string | null;
    photo_attribution: string | null;
    distance_min: number | null;
    open_now: boolean | null;
    opening_hours_text: string | null;
    price_text: string | null;
    feature_chips: string[];
    tip: string | null;
    tip_is_generic: boolean;
    cluster_size: number;
    activities: Array<{ emoji: string; label: string }>;
    transit_hint: string | null;
    facts: PlaceFact[];
};

export type VeedelOption = {
    name: string;
    count: number;
    photo_url: string | null;
};

export type NearbyPlace = {
    id: number;
    name: string;
    category: string;
    emoji: string;
    walk_min: number;
    lat: number;
    lng: number;
};

export type PlaceContext = {
    now: { text: string; tone: 'good' | 'bad' } | null;
    nearby: NearbyPlace[];
};
