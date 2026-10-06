export type SearchPoint = { lat: number; lng: number };

export function validSearchPoint(
    point?: SearchPoint | null,
): point is SearchPoint {
    return (
        !!point &&
        Number.isFinite(point.lat) &&
        Math.abs(point.lat) <= 90 &&
        Number.isFinite(point.lng) &&
        Math.abs(point.lng) <= 180
    );
}

/** Search bias never becomes a route origin or a selected destination. */
export function suggestionSearchParams(
    query: string,
    role: 'origin' | 'destination',
    bias: { point?: SearchPoint | null; area?: string | null },
): URLSearchParams {
    const params = new URLSearchParams({ role });

    if (query.trim()) {
        params.set('q', query.trim().slice(0, 100));
    }

    if (validSearchPoint(bias.point)) {
        params.set('lat', String(bias.point.lat));
        params.set('lng', String(bias.point.lng));
    } else if (bias.area?.trim()) {
        params.set('from_area', bias.area.trim().slice(0, 100));
    }

    return params;
}
