/** Kilometres in a straight line; unknown when a point isn't known. */
export function kmBetween(
    a: { lat: number; lng: number },
    b: { lat: number | null; lng: number | null },
): number | null {
    if (b.lat === null || b.lng === null) {
        return null;
    }

    const rad = Math.PI / 180;
    const dLat = (b.lat - a.lat) * rad;
    const dLng = (b.lng - a.lng) * rad;
    const h =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2;

    return 12742 * Math.asin(Math.sqrt(h));
}
