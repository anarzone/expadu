import type { TravelDestination } from './travel-request';

export type SavedJourneyPoint = {
    name: string;
    lat: number;
    lng: number;
    id?: string | null;
};
export type SavedJourneyRequest = {
    mode: 'all' | 'transit' | 'bike' | 'walk';
    origin: SavedJourneyPoint | null;
    destination: SavedJourneyPoint;
};
export type SavedJourney = {
    id: number;
    title: string;
    request: SavedJourneyRequest;
};

export function reusableJourney(
    destination: TravelDestination,
): SavedJourneyRequest {
    return {
        mode: destination.preferredMode ?? 'all',
        origin:
            destination.fromLat != null && destination.fromLng != null
                ? {
                      name: destination.fromName ?? 'Starting point',
                      lat: destination.fromLat,
                      lng: destination.fromLng,
                  }
                : null,
        destination: {
            name: destination.name,
            lat: destination.lat,
            lng: destination.lng,
            id: destination.destinationId ?? null,
        },
    };
}

export function sameSavedJourney(
    a: SavedJourneyRequest,
    b: SavedJourneyRequest,
): boolean {
    const point = (value: SavedJourneyPoint | null) =>
        value
            ? [value.id ?? null, value.lat.toFixed(6), value.lng.toFixed(6)]
            : null;

    return (
        JSON.stringify([a.mode, point(a.origin), point(a.destination)]) ===
        JSON.stringify([b.mode, point(b.origin), point(b.destination)])
    );
}
