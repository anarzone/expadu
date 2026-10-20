export type PlaceFact = { label: string; value: string };

type EvidenceFact<T> = {
    value: T;
    status: 'known' | 'unknown' | 'conflicting';
    source_url: string | null;
    observed_at: string | null;
    reviewed_at: string | null;
};

export type PlaceFactSnapshot = {
    name_kind: 'source' | 'descriptive' | 'reviewed';
    aliases: string[];
    location: {
        map_point: {
            lat: number | null;
            lng: number | null;
            kind: 'source_node' | 'source_center' | 'legacy_unknown';
            boundary_reference: string | null;
            status: 'known' | 'unknown' | 'conflicting';
            source_url: string | null;
            observed_at: string | null;
            reviewed_at: string | null;
        };
        entrance_point: {
            lat: number | null;
            lng: number | null;
            status: 'verified' | 'candidate' | 'unknown';
            source_url: string | null;
            observed_at: string | null;
            reviewed_at: string | null;
        };
    };
    access: EvidenceFact<
        'public' | 'private' | 'customers' | 'members' | 'permit' | 'unknown'
    > & { raw: string | null; conditional: string | null };
    fee: EvidenceFact<'free' | 'paid' | 'unknown'> & {
        raw: string | null;
        amount: number | null;
        currency: string | null;
    };
    hours: EvidenceFact<Record<string, unknown> | null> & {
        raw: string | null;
        parsed: Record<string, unknown> | null;
    };
    contact: {
        website: EvidenceFact<string | null>;
        phone: EvidenceFact<string | null>;
        address: EvidenceFact<string | null>;
    };
    description: EvidenceFact<string | null>;
    negative_facts: Record<string, string>;
    conflicts: string[];
    revision: number;
};

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
    routing_lat: number;
    routing_lng: number;
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
    tip: string | null;
    tip_is_generic: boolean;
    cluster_size: number;
    activities: Array<{ emoji: string; label: string }>;
    transit_hint: string | null;
    facts: PlaceFact[];
    place_facts: PlaceFactSnapshot;
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
