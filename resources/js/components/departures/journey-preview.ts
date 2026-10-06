import type { Journey } from '../journey/take-me-there-sheet';
import type { TravelDestination } from './travel-request';

type Storage = Pick<globalThis.Storage, 'getItem' | 'setItem' | 'removeItem'>;
const MAX_AGE = 30 * 60_000;

export function previewRequestKey(destination: TravelDestination): string {
    return JSON.stringify([
        destination.name,
        destination.lat,
        destination.lng,
        destination.fromLat ?? null,
        destination.fromLng ?? null,
        destination.arriveBy ?? null,
        destination.departAt ?? null,
        destination.preferredMode ?? null,
    ]);
}

function validJourney(value: unknown): value is Journey {
    if (!value || typeof value !== 'object') {
        return false;
    }

    const journey = value as Journey;

    return (
        ['transit', 'bike', 'walk'].includes(journey.mode) &&
        Number.isFinite(Date.parse(journey.depart_at)) &&
        Number.isFinite(Date.parse(journey.arrive_at)) &&
        typeof journey.depart_time === 'string' &&
        typeof journey.arrive_time === 'string' &&
        Number.isFinite(journey.duration_min) &&
        Array.isArray(journey.legs) &&
        journey.legs.length > 0 &&
        journey.legs.every(
            (leg) =>
                leg &&
                typeof leg.mode === 'string' &&
                leg.from &&
                leg.to &&
                typeof leg.from.name === 'string' &&
                typeof leg.to.name === 'string' &&
                [
                    leg.from.lat,
                    leg.from.lng,
                    leg.to.lat,
                    leg.to.lng,
                    leg.duration_min,
                ].every(Number.isFinite) &&
                Number.isFinite(Date.parse(leg.depart_at)) &&
                Number.isFinite(Date.parse(leg.arrive_at)) &&
                (leg.intermediate_stops == null ||
                    Array.isArray(leg.intermediate_stops)),
        )
    );
}

/** A tab-local preview, scoped to the account and exact request; never starts a trip. */
export function readJourneyPreview(
    storage: Storage,
    userId: number | string,
    destination: TravelDestination,
    now = Date.now(),
): Journey | null {
    try {
        const raw = JSON.parse(
            storage.getItem(`expadu:travel-preview:${userId}`) ?? 'null',
        );

        if (
            !raw ||
            raw.version !== 1 ||
            raw.request !== previewRequestKey(destination) ||
            !Number.isFinite(raw.at) ||
            raw.at > now ||
            now - raw.at > MAX_AGE ||
            !validJourney(raw.journey)
        ) {
            return null;
        }

        return raw.journey;
    } catch {
        return null;
    }
}

export function writeJourneyPreview(
    storage: Storage,
    userId: number | string,
    destination: TravelDestination,
    journey: Journey | null,
    now = Date.now(),
): void {
    try {
        const key = `expadu:travel-preview:${userId}`;

        if (journey) {
            storage.setItem(
                key,
                JSON.stringify({
                    version: 1,
                    at: now,
                    request: previewRequestKey(destination),
                    journey,
                }),
            );
        } else {
            storage.removeItem(key);
        }
    } catch {
        /* A storage restriction must not block the journey. */
    }
}
