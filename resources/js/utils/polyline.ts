/**
 * Decode an encoded polyline string into an array of [lng, lat] coordinates.
 * Valhalla uses precision 6 (Google's format uses 5).
 *
 * @see https://valhalla.github.io/valhalla/decoding/
 */
export function decodePolyline(
    encoded: string,
    precision = 6,
): [number, number][] {
    const factor = Math.pow(10, precision);
    const coordinates: [number, number][] = [];
    let index = 0;
    let lat = 0;
    let lng = 0;

    while (index < encoded.length) {
        let shift = 0;
        let result = 0;
        let byte: number;

        do {
            byte = encoded.charCodeAt(index++) - 63;
            result |= (byte & 0x1f) << shift;
            shift += 5;
        } while (byte >= 0x20);

        lat += result & 1 ? ~(result >> 1) : result >> 1;

        shift = 0;
        result = 0;

        do {
            byte = encoded.charCodeAt(index++) - 63;
            result |= (byte & 0x1f) << shift;
            shift += 5;
        } while (byte >= 0x20);

        lng += result & 1 ? ~(result >> 1) : result >> 1;

        // GeoJSON uses [lng, lat] order
        coordinates.push([lng / factor, lat / factor]);
    }

    return coordinates;
}

/**
 * Convert decoded coordinates to a GeoJSON LineString feature.
 */
export function polylineToGeoJSON(
    encoded: string,
    precision = 6,
): GeoJSON.Feature<GeoJSON.LineString> {
    return {
        type: 'Feature',
        properties: {},
        geometry: {
            type: 'LineString',
            coordinates: decodePolyline(encoded, precision),
        },
    };
}
