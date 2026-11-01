/**
 * Decode a MOTIS encoded polyline (precision 7) into `[lng, lat]` pairs for
 * MapLibre: the standard Google polyline algorithm, at MOTIS's precision.
 */
export function decodePolyline(encoded: string): [number, number][] {
    const coords: [number, number][] = [];
    const factor = 1e7;
    let index = 0;
    let lat = 0;
    let lng = 0;

    const next = () => {
        let result = 0;
        let shift = 0;
        let byte: number;

        do {
            byte = encoded.charCodeAt(index++) - 63;
            result |= (byte & 0x1f) << shift;
            shift += 5;
        } while (byte >= 0x20);

        return result & 1 ? ~(result >> 1) : result >> 1;
    };

    while (index < encoded.length) {
        lat += next();
        lng += next();
        coords.push([lng / factor, lat / factor]);
    }

    return coords;
}
