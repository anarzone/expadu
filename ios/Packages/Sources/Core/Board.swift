import Foundation

/// Travel's board: the stops a short walk away and what leaves them, soonest first.
public struct Board: Equatable, Sendable {
    public struct Stop: Equatable, Sendable {
        public let name: String
        public let walkMinutes: Int

        public init(name: String, walkMinutes: Int) {
            self.name = name
            self.walkMinutes = walkMinutes
        }
    }

    /// A line in one direction, and its next few departures.
    public struct Departure: Equatable, Sendable, Identifiable {
        public let line: String
        public let direction: String
        public let stop: String
        /// KVB's colour for the line, `#RRGGBB`.
        public let color: String
        public let minutes: [Int]
        public let delay: Int
        public let cancelled: Bool
        public let disrupted: Bool

        public var id: String { "\(line)→\(direction)@\(stop)" }

        /// The line as its badge says it: "RE1", not the feed's "RE1 (RRX)".
        public var badge: String { Board.badge(line) }

        public init(line: String, direction: String, stop: String, color: String, minutes: [Int], delay: Int, cancelled: Bool, disrupted: Bool) {
            self.line = line
            self.direction = direction
            self.stop = stop
            self.color = color
            self.minutes = minutes
            self.delay = delay
            self.cancelled = cancelled
            self.disrupted = disrupted
        }

        /// The next one, as the board's flaps show it: two digits.
        public var flaps: String {
            String(format: "%02d", min(minutes.first ?? 0, 99))
        }
    }

    public let stops: [Stop]
    public let departures: [Departure]

    public init(stops: [Stop], departures: [Departure]) {
        self.stops = stops
        self.departures = departures
    }

    /// The board's name: the closest stop.
    public var name: String { stops.first?.name ?? "Nearby" }

    /// Every line on the board once, trams first, in number order.
    public var lines: [String] {
        var seen = Set<String>()
        return departures.map(\.line).filter { seen.insert($0).inserted }.sorted { a, b in
            switch (Int(a), Int(b)) {
            case let (x?, y?): x < y
            case (.some, nil): true
            case (nil, .some): false
            case (nil, nil): a < b
            }
        }
    }

    public static func badge(_ line: String) -> String {
        line.split(separator: " (").first.map(String.init) ?? line
    }

    /// The board with one line, or all of them.
    public func showing(_ line: String?) -> [Departure] {
        guard let line else { return departures }
        return departures.filter { $0.line == line }
    }
}
