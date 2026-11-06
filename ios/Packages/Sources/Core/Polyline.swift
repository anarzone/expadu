import Foundation

/// MOTIS's encoded route lines: the standard polyline algorithm at precision 7 (the web's lib/polyline.ts).
public enum Polyline {
    /// The points, as latitude and longitude.
    public static func decode(_ text: String, precision: Double = 1e7) -> [(latitude: Double, longitude: Double)] {
        var points: [(latitude: Double, longitude: Double)] = []
        let bytes = Array(text.utf8)
        var index = 0
        var lat = 0
        var lng = 0
        func next() -> Int? {
            var result = 0
            var shift = 0
            while index < bytes.count {
                let byte = Int(bytes[index]) - 63
                index += 1
                result |= (byte & 0x1F) << shift
                shift += 5
                if byte < 0x20 { return (result & 1) != 0 ? ~(result >> 1) : result >> 1 }
            }
            return nil
        }
        while index < bytes.count {
            guard let dLat = next(), let dLng = next() else { break }
            lat += dLat
            lng += dLng
            points.append((Double(lat) / precision, Double(lng) / precision))
        }
        return points
    }
}
