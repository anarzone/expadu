type PlaceFactsOptions = {
    access?:
        | 'public'
        | 'private'
        | 'customers'
        | 'members'
        | 'permit'
        | 'unknown';
    description?: string | null;
};

/**
 * Keep mocked place responses aligned with the public Places API contract.
 */
export function placeFacts(
    lat: number,
    lng: number,
    options: PlaceFactsOptions = {},
) {
    const access = options.access ?? 'public';

    return {
        name_kind: 'source',
        aliases: [],
        location: {
            map_point: {
                lat,
                lng,
                kind: 'source_node',
                boundary_reference: null,
                status: 'known',
                source_url: 'https://www.openstreetmap.org',
                observed_at: '2026-09-17T12:00:00+02:00',
                reviewed_at: null,
            },
            entrance_point: {
                lat: null,
                lng: null,
                status: 'unknown',
                source_url: null,
                observed_at: null,
                reviewed_at: null,
            },
        },
        access: {
            value: access,
            status: access === 'unknown' ? 'unknown' : 'known',
            raw: access === 'public' ? 'yes' : null,
            conditional: null,
            source_url: 'https://www.openstreetmap.org',
            observed_at: '2026-09-17T12:00:00+02:00',
            reviewed_at: null,
        },
        fee: {
            value: 'free',
            status: 'known',
            raw: 'no',
            amount: null,
            currency: null,
            source_url: 'https://www.openstreetmap.org',
            observed_at: '2026-09-17T12:00:00+02:00',
            reviewed_at: null,
        },
        hours: {
            value: null,
            status: 'unknown',
            raw: null,
            parsed: null,
            source_url: null,
            observed_at: null,
            reviewed_at: null,
        },
        contact: {
            website: {
                value: null,
                status: 'unknown',
                source_url: null,
                observed_at: null,
                reviewed_at: null,
            },
            phone: {
                value: null,
                status: 'unknown',
                source_url: null,
                observed_at: null,
                reviewed_at: null,
            },
            address: {
                value: null,
                status: 'unknown',
                source_url: null,
                observed_at: null,
                reviewed_at: null,
            },
        },
        description: {
            value: options.description ?? null,
            status: options.description ? 'known' : 'unknown',
            source_url: options.description
                ? 'https://www.openstreetmap.org'
                : null,
            observed_at: options.description
                ? '2026-09-17T12:00:00+02:00'
                : null,
            reviewed_at: null,
        },
        negative_facts: {},
        conflicts: [],
        revision: 1,
    };
}
