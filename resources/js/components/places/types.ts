/** Optional source evidence supplied by the places catalogue. */
export type PlaceEvidenceFact<T = unknown> = {
    value?: T | null;
    status: string;
    source_url?: string | null;
    observed_at?: string | null;
    reviewed_at?: string | null;
};
export type PlaceEvidence = {
    identity?: {
        canonical_id: number;
        provider: string;
        provider_record_id: string;
    };
    name_kind?: string;
    aliases?: string[];
    location?: {
        map_point?: {
            lat: number | null;
            lng: number | null;
            status: string;
            kind?: string;
            source_url?: string | null;
        };
        entrance_point?: {
            lat: number | null;
            lng: number | null;
            status: string;
            source_url?: string | null;
        };
    };
    access?: PlaceEvidenceFact<string>;
    fee?: PlaceEvidenceFact<string> & {
        amount?: number | null;
        currency?: string | null;
        conditional?: string | null;
        charge?: string | null;
    };
    hours?: PlaceEvidenceFact & {
        raw?: string | null;
        parsed?: Record<string, string[][]> | null;
    };
    contact?: Record<string, PlaceEvidenceFact<string>>;
    description?: PlaceEvidenceFact<string>;
    practical?: Record<string, PlaceEvidenceFact>;
    negative_facts?: unknown[];
    conflicts?: string[];
    revision?: number;
};

export type PlaceFact = { label: string; value: string };

export type Place = {
    id: number;
    name: string;
    category: string;
    fine_label: string | null;
    emoji: string | null;
    veedel: string | null;
    park: string | null;
    lat: number | null;
    lng: number | null;
    routing_lat?: number | null;
    routing_lng?: number | null;
    place_facts?: PlaceEvidence | null;
    recommendation_status?: 'available' | 'unavailable';
    hours_assumed?: boolean;
    photo_url: string | null;
    photo_attribution: string | null;
    photo_source_url: string | null;
    photo_license_url: string | null;
    distance_min: number | null;
    distance_mode?: 'walk' | 'bike' | 'transit' | null;
    distance_km?: number | null;
    open_now: boolean | null;
    opening_hours_text: string | null;
    price_text: string | null;
    feature_chips: string[];
    summary?: string | null;
    tip: string | null;
    tip_is_generic: boolean;
    cluster_size: number;
    activities: Array<{ emoji: string; label: string }>;
    transit_hint: string | null;
    facts: PlaceFact[];
    feedback_state:
        | 'more_like_this'
        | 'saved'
        | 'been'
        | 'not_interested'
        | null;
    feedback_rating: 'up' | 'down' | null;
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
