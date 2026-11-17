import Foundation

/// Routines learned from trips (GET /api/v1/routines): whether learning is on, the trips kept, the
/// routines they confirmed, and the one to offer now with the trips it rests on.
public struct RoutinesState: Equatable, Sendable {
    public struct Routine: Equatable, Sendable, Identifiable {
        public let id: Int
        public let toLabel: String
        public let fromKey: String?
        /// 1 (Monday) to 7.
        public var weekdays: [Int]
        /// "HH:mm"
        public var at: String
        public var remind: Bool

        public init(id: Int, toLabel: String, fromKey: String?, weekdays: [Int], at: String, remind: Bool) {
            self.id = id
            self.toLabel = toLabel
            self.fromKey = fromKey
            self.weekdays = weekdays
            self.at = at
            self.remind = remind
        }
    }

    public struct Trip: Equatable, Sendable, Hashable {
        /// "yyyy-MM-dd", Cologne's day.
        public let date: String
        public let weekday: Int
        public let at: String

        public init(date: String, weekday: Int, at: String) {
            self.date = date
            self.weekday = weekday
            self.at = at
        }

        public var minute: Int { Routines.minute(at) }
    }

    public struct Offer: Equatable, Sendable {
        public let toKey: String
        public let toLabel: String
        public let fromKey: String?
        public let weekdays: [Int]
        public let at: String
        public let times: Int
        public let trips: [Trip]

        public init(toKey: String, toLabel: String, fromKey: String?, weekdays: [Int], at: String, times: Int, trips: [Trip]) {
            self.toKey = toKey
            self.toLabel = toLabel
            self.fromKey = fromKey
            self.weekdays = weekdays
            self.at = at
            self.times = times
            self.trips = trips
        }
    }

    public var learning: Bool
    public var tripsKept: Int
    public var routines: [Routine]
    public var offer: Offer?

    public init(learning: Bool, tripsKept: Int, routines: [Routine], offer: Offer?) {
        self.learning = learning
        self.tripsKept = tripsKept
        self.routines = routines
        self.offer = offer
    }
}

public enum Routines {
    public static let dayShort = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"]

    /// "08:10" as minutes since midnight.
    public static func minute(_ clock: String) -> Int {
        let parts = clock.split(separator: ":").compactMap { Int($0) }
        return parts.count == 2 ? parts[0] * 60 + parts[1] : 0
    }

    public static func clock(_ minute: Int) -> String {
        String(format: "%02d:%02d", (minute / 60 + 24) % 24, ((minute % 60) + 60) % 60)
    }

    /// "Weekdays", "Mon, Wed, Thu", "Every day".
    public static func days(_ weekdays: [Int]) -> String {
        let set = Set(weekdays)
        if set == Set(1...7) { return "Every day" }
        if set == Set(1...5) { return "Weekdays" }
        if set == [6, 7] { return "Weekends" }
        return weekdays.sorted().compactMap { (1...7).contains($0) ? dayShort[$0 - 1] : nil }.joined(separator: ", ")
    }
}
