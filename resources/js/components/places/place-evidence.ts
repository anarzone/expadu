import type { Place } from '@/components/places/types';

function point(
    lat: unknown,
    lng: unknown,
): { lat: number; lng: number } | null {
    return typeof lat === 'number' &&
        Number.isFinite(lat) &&
        Math.abs(lat) <= 90 &&
        typeof lng === 'number' &&
        Number.isFinite(lng) &&
        Math.abs(lng) <= 180
        ? { lat, lng }
        : null;
}

export function placeMapPoint(place: Pick<Place, 'lat' | 'lng'>) {
    return point(place.lat, place.lng);
}

/** The server chooses a verified entrance when available; never infer one here. */
export function placeRoutingPoint(
    place: Pick<Place, 'lat' | 'lng' | 'routing_lat' | 'routing_lng'>,
) {
    return point(place.routing_lat, place.routing_lng) ?? placeMapPoint(place);
}

export function placePracticalDetails(
    place: Pick<
        Place,
        | 'place_facts'
        | 'hours_assumed'
        | 'open_now'
        | 'opening_hours_text'
        | 'price_text'
        | 'recommendation_status'
    >,
) {
    const facts = place.place_facts;
    const hoursKnown = facts?.hours?.status === 'known' && !place.hours_assumed;
    const available = place.recommendation_status !== 'unavailable';
    const openNow = hoursKnown && available ? (place.open_now ?? null) : null;
    const hours =
        hoursKnown && facts?.hours?.raw
            ? facts.hours.raw
            : facts?.hours?.status === 'conflicting'
              ? 'Opening hours have conflicting reports'
              : place.opening_hours_text && !place.hours_assumed && !facts
                ? `Reported hours: ${place.opening_hours_text} · not verified`
                : 'Opening hours not verified';
    const fee = !available
        ? 'Entry fee not verified'
        : facts?.fee?.status === 'conditional'
          ? 'Entry fee has conditions — check before visiting'
          : facts?.fee?.status === 'conflicting'
            ? 'Entry fee has conflicting reports'
            : facts?.fee?.status === 'known' && facts.fee.value === 'free'
              ? 'No entry fee reported'
              : facts?.fee?.status === 'known' && facts.fee.value === 'paid'
                ? place.price_text &&
                  !['free', 'paid'].includes(place.price_text.toLowerCase())
                    ? place.price_text
                    : 'Entry fee applies · amount not verified'
                : 'Entry fee not verified';
    const accessFact = facts?.access;
    const access =
        accessFact?.status === 'conflicting'
            ? 'Access has conflicting reports'
            : accessFact?.status === 'conditional'
              ? 'Access has conditions — check before visiting'
              : accessFact?.status === 'known'
                ? ({
                      public: 'Public access reported',
                      yes: 'Public access reported',
                      permissive: 'Access by permission',
                      private: 'Private access',
                      no: 'No public access',
                      customers: 'Customers only',
                      members: 'Members only',
                      permit: 'Permit required',
                  }[String(accessFact.value)] ?? 'Access not verified')
                : 'Access not verified';

    return { openNow, hours, fee, access };
}
