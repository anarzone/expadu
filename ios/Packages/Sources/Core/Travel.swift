import Foundation

/*
 * Travel, as the server's Travel page reads it (app/Transit/NearbyStation.php and friends): one station
 * by its boarding areas, each line both ways with its runs, the notices and lifts, and the planner's
 * ways. Every type decodes the server's own JSON; nothing is filled in on the phone.
 */

enum TravelJSON {
    static let decoder: JSONDecoder = {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        return decoder
    }()

    private nonisolated(unsafe) static let iso: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        return f
    }()

    private nonisolated(unsafe) static let isoFraction: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return f
    }()

    static func date(_ text: String?) -> Date? {
        guard let text else { return nil }
        return iso.date(from: text) ?? isoFraction.date(from: text)
    }
}

/// Travel's decoder (snake_case keys) for the API package's small wrappers.
public enum TravelJSONAccess {
    public static func decode<T: Decodable>(_ type: T.Type, _ data: Data) throws -> T {
        try TravelJSON.decoder.decode(type, from: data)
    }
}

/// Whether a time is the feed's live reading or the timetable's.
public enum TimeBasis: String, Decodable, Sendable {
    case realtime, scheduled, stale, unavailable

    public init(from decoder: any Decoder) throws {
        self = TimeBasis(rawValue: try decoder.singleValueContainer().decode(String.self)) ?? .scheduled
    }

    public var isLive: Bool { self == .realtime }
}

public struct Station: Decodable, Equatable, Sendable {
    /// Where the person boards: one platform group, the lines that stop there.
    public struct Area: Decodable, Equatable, Sendable, Identifiable {
        public let id: String
        public let lines: [String]
        public let mode: String
        public let lat: Double?
        public let lng: Double?
        public let platform: String?
    }

    /// One departure: its time, how late, cancelled, and a notice about it.
    public struct Run: Decodable, Equatable, Sendable, Identifiable {
        public let id: String
        public let at: String
        public let time: String
        public let scheduledAt: String?
        public let delay: Int?
        public let cancelled: Bool
        public let timeBasis: TimeBasis
        public let notice: String?

        public var date: Date? { TravelJSON.date(at) }

        enum CodingKeys: String, CodingKey { case id, at, time, scheduledAt, delay, cancelled, timeBasis, notice }

        public init(from decoder: any Decoder) throws {
            let c = try decoder.container(keyedBy: CodingKeys.self)
            id = try c.decode(String.self, forKey: .id)
            at = try c.decode(String.self, forKey: .at)
            time = try c.decode(String.self, forKey: .time)
            scheduledAt = try c.decodeIfPresent(String.self, forKey: .scheduledAt)
            delay = try c.decodeIfPresent(Int.self, forKey: .delay)
            cancelled = try c.decodeIfPresent(Bool.self, forKey: .cancelled) ?? false
            timeBasis = try c.decodeIfPresent(TimeBasis.self, forKey: .timeBasis) ?? .scheduled
            // A notice may come as text or as a small object with its headline.
            if let text = try? c.decodeIfPresent(String.self, forKey: .notice) {
                notice = text
            } else if let object = try? c.decodeIfPresent([String: String?].self, forKey: .notice) {
                notice = (object["headline"] ?? nil) ?? (object["title"] ?? nil)
            } else {
                notice = nil
            }
        }

        /// Whole minutes from `now`, never below 0.
        public func minutes(from now: Date) -> Int {
            guard let date else { return 0 }
            return max(0, Int((date.timeIntervalSince(now) / 60).rounded(.down)))
        }
    }

    /// A line one way: where it goes, by way of which stops, and its next runs.
    public struct Direction: Decodable, Equatable, Sendable, Identifiable {
        public let id: String
        public let dest: String
        public let via: [String]
        public let direction: Int?
        public let next: Run?
        public let later: [Run]
        public let cancelled: Bool
        public let disrupted: Bool
        public let headway: Int?

        public var runs: [Run] { (next.map { [$0] } ?? []) + later }
    }

    public struct Line: Decodable, Equatable, Sendable, Identifiable {
        public let line: String
        public let mode: String
        public let area: String?
        public let dirs: [Direction]
        /// KVB's colour for the line, `#RRGGBB`.
        public let color: String?

        public var id: String { line }
        public var isBus: Bool { mode == "bus" }
    }

    public struct Lifts: Decodable, Equatable, Sendable {
        public struct Out: Decodable, Equatable, Sendable {
            public let label: String
            public let since: String?
        }

        public let known: Bool
        public let out: [Out]
    }

    /// KVB's notice: their German words, and the English brief written from them.
    public struct Notice: Decodable, Equatable, Sendable, Identifiable {
        public struct Brief: Decodable, Equatable, Sendable {
            public let headline: String?
            public let text: String?
        }

        public let id: String
        public let title: String
        public let severity: String?
        public let lines: [String]
        public let brief: Brief?
        public let details: String?
        public let url: String?
        public let since: String?

        public var isMajor: Bool { severity == "major" }
        public var headline: String { brief?.headline ?? title }
    }

    /// Runs on a line that are cancelled or late, counted.
    public struct Trouble: Decodable, Equatable, Sendable {
        public let line: String
        public let dest: String?
        public let kind: String
        public let count: Int
        public let of: Int?
    }

    public let name: String
    public let kind: String?
    public let walkMin: Int?
    public let source: String?
    public let timeBasis: TimeBasis
    public let isStale: Bool
    public let observedAt: String?
    public let areas: [Area]
    public let lines: [Line]
    public let lifts: Lifts?
    public let notices: [Notice]
    public let trouble: [Trouble]
    public let unknownStop: String?

    enum CodingKeys: String, CodingKey {
        case name, kind, walkMin, source, timeBasis, isStale, observedAt, areas, lines, lifts, notices, trouble, unknownStop
    }

    public init(from decoder: any Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        name = try c.decode(String.self, forKey: .name)
        kind = try c.decodeIfPresent(String.self, forKey: .kind)
        walkMin = try c.decodeIfPresent(Int.self, forKey: .walkMin)
        source = try c.decodeIfPresent(String.self, forKey: .source)
        timeBasis = try c.decodeIfPresent(TimeBasis.self, forKey: .timeBasis) ?? .scheduled
        isStale = try c.decodeIfPresent(Bool.self, forKey: .isStale) ?? false
        observedAt = try c.decodeIfPresent(String.self, forKey: .observedAt)
        areas = (try? c.decodeIfPresent([Lossy<Area>].self, forKey: .areas))?.compactMap(\.value) ?? []
        lines = (try? c.decodeIfPresent([Lossy<Line>].self, forKey: .lines))?.compactMap(\.value) ?? []
        lifts = try? c.decodeIfPresent(Lifts.self, forKey: .lifts)
        notices = (try? c.decodeIfPresent([Lossy<Notice>].self, forKey: .notices))?.compactMap(\.value) ?? []
        trouble = (try? c.decodeIfPresent([Lossy<Trouble>].self, forKey: .trouble))?.compactMap(\.value) ?? []
        unknownStop = try c.decodeIfPresent(String.self, forKey: .unknownStop)
    }

    /// Every departure here as one list, soonest first: a line one way and its run.
    public func departures(now: Date) -> [Departure] {
        lines.flatMap { line in
            line.dirs.compactMap { dir in
                // The next one that runs; a cancelled one stays shown until its time.
                guard let run = dir.next ?? dir.later.first else { return nil }
                return Departure(line: line, dir: dir, run: run)
            }
        }
        .sorted { ($0.run.date ?? .distantFuture) < ($1.run.date ?? .distantFuture) }
    }

    /// The lines here, trams first, in number order.
    public var lineNames: [String] { lines.map(\.line).sorted(by: Station.lineOrder) }

    /// The notice that matters most here: KVB's major one on a line that stops here.
    public var majorNotice: Notice? { notices.first { $0.isMajor && !$0.lines.isEmpty } }

    public func notice(for line: String) -> Notice? { notices.first { $0.lines.contains(line) } }

    public func area(of line: String) -> Area? {
        areas.first { $0.lines.contains(line) }
    }

    public static func lineOrder(_ a: String, _ b: String) -> Bool {
        switch (Int(a), Int(b)) {
        case let (x?, y?): x < y
        case (.some, nil): true
        case (nil, .some): false
        case (nil, nil): a.localizedStandardCompare(b) == .orderedAscending
        }
    }

    public static func decode(_ data: Data) throws -> StationReply {
        try TravelJSON.decoder.decode(StationReply.self, from: data)
    }
}

/// A line one way and the run the board shows for it.
public struct Departure: Equatable, Sendable, Identifiable {
    public let line: Station.Line
    public let dir: Station.Direction
    public let run: Station.Run

    public var id: String { dir.id }
}

public struct StationReply: Decodable, Sendable {
    public let station: Station?
    public let favourite: Bool
    public let stepFreeNeed: Bool
    public let deutschlandticket: Bool

    public init(station: Station?, favourite: Bool, stepFreeNeed: Bool, deutschlandticket: Bool) {
        self.station = station
        self.favourite = favourite
        self.stepFreeNeed = stepFreeNeed
        self.deutschlandticket = deutschlandticket
    }
}

/// The stop picker's answer: a KVB station and its lines.
public struct StationHit: Decodable, Equatable, Sendable, Identifiable, Hashable {
    public let name: String
    public let lines: [String]
    public let lat: Double?
    public let lng: Double?
    /// Each line's KVB colour, `#RRGGBB`.
    public let colors: [String: String]?

    public var id: String { name }

    public static func list(_ data: Data, key: String) throws -> [StationHit] {
        let object = try JSONSerialization.jsonObject(with: data) as? [String: Any]
        let inner = try JSONSerialization.data(withJSONObject: object?[key] ?? [])
        return try TravelJSON.decoder.decode([Lossy<StationHit>].self, from: inner).compactMap(\.value)
    }
}

/// A line's whole route both ways, from the timetable's most common trip.
public struct LineRoute: Decodable, Equatable, Sendable {
    public struct Stop: Decodable, Equatable, Sendable {
        public let name: String
        public let city: String?
        public let here: Bool
        public let changes: [String]
    }

    public struct Way: Decodable, Equatable, Sendable {
        public let towards: String
        public let minutes: Int?
        public let stops: [Stop]
    }

    public let line: String
    public let mode: String
    public let ways: [Way]
}

/// The stop's own board for one line, read as its row comes into view.
public struct LineStop: Decodable, Equatable, Sendable {
    public let name: String
    public let timeBasis: TimeBasis
    public let isStale: Bool
    public let dirs: [Station.Direction]

    enum CodingKeys: String, CodingKey { case name, timeBasis, isStale, dirs }

    public init(from decoder: any Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        name = try c.decode(String.self, forKey: .name)
        timeBasis = try c.decodeIfPresent(TimeBasis.self, forKey: .timeBasis) ?? .scheduled
        isStale = try c.decodeIfPresent(Bool.self, forKey: .isStale) ?? false
        dirs = (try? c.decodeIfPresent([Lossy<Station.Direction>].self, forKey: .dirs))?.compactMap(\.value) ?? []
    }
}

/// Travel's map: the lines through a station, each way's next two stops and where it ends, as points.
public struct LinesMap: Decodable, Equatable, Sendable {
    public struct Point: Decodable, Equatable, Sendable {
        public let name: String
        public let lat: Double
        public let lng: Double
    }

    public struct Way: Decodable, Equatable, Sendable {
        public let towards: String
        public let stops: [Point]
        public let end: Point?
        public let between: Int?
    }

    public struct Line: Decodable, Equatable, Sendable {
        public let line: String
        public let mode: String
        public let ways: [Way]
    }

    public let station: Point
    public let lines: [Line]
    public let buses: [String]
}

/// Where a trip can go: their ★ stops, saved places (Home and Work first), recents, and today's plans.
public struct TravelPlaces: Decodable, Equatable, Sendable {
    public struct Saved: Decodable, Equatable, Sendable, Identifiable {
        public let id: Int
        public let name: String
        public let category: String
        public let emoji: String?
        public let address: String?
        public let lat: Double?
        public let lng: Double?

        public var hasPoint: Bool { lat != nil && lng != nil }
    }

    public struct Recent: Decodable, Equatable, Sendable {
        public let name: String
        public let area: String?
        public let lat: Double?
        public let lng: Double?
    }

    public struct Plan: Decodable, Equatable, Sendable, Identifiable {
        public let id: String
        public let title: String
        public let startTime: String?
        public let startAt: String?
        public let place: String?
        public let lat: Double
        public let lng: Double

        public var startDate: Date? { TravelJSON.date(startAt) }
    }

    public let favouriteStops: [StationHit]
    public let savedPlaces: [Saved]
    public let recentDestinations: [Recent]
    public let today: [Plan]

    public init(favouriteStops: [StationHit] = [], savedPlaces: [Saved] = [], recentDestinations: [Recent] = [], today: [Plan] = []) {
        self.favouriteStops = favouriteStops
        self.savedPlaces = savedPlaces
        self.recentDestinations = recentDestinations
        self.today = today
    }

    public var home: Saved? { savedPlaces.first { $0.category == "home" } }
    public var work: Saved? { savedPlaces.first { $0.category == "work" } }

    public static func decode(_ data: Data) throws -> TravelPlaces {
        try TravelJSON.decoder.decode(TravelPlaces.self, from: data)
    }

    public static func saved(_ data: Data) throws -> Saved {
        struct Reply: Decodable { let place: Saved }
        return try TravelJSON.decoder.decode(Reply.self, from: data).place
    }
}

/// A place or stop offered as they type (saved places, recents, then the geocoder).
public struct TripSuggestion: Decodable, Equatable, Sendable, Hashable, Identifiable {
    public let kind: String
    public let name: String
    public let area: String?
    public let lat: Double
    public let lng: Double
    public let emoji: String?

    public var id: String { "\(kind)|\(name)|\(lat)|\(lng)" }

    public static func list(_ data: Data) throws -> [TripSuggestion] {
        try TravelJSON.decoder.decode([Lossy<TripSuggestion>].self, from: data).compactMap(\.value)
    }
}

/// One end of a trip: a name and a point.
public struct TripPoint: Equatable, Sendable, Hashable {
    public let name: String
    public let lat: Double
    public let lng: Double

    public init(name: String, lat: Double, lng: Double) {
        self.name = name
        self.lat = lat
        self.lng = lng
    }
}

/// Plan a trip's answer: the ways, the ticket, and what was checked on the way.
public struct TripPlan: Sendable {
    public struct Leg: Decodable, Equatable, Sendable {
        public struct End: Decodable, Equatable, Sendable {
            public let name: String?
            public let lat: Double?
            public let lng: Double?
        }

        public struct Stop: Decodable, Equatable, Sendable {
            public let name: String
            public let arriveTime: String?
            public let lat: Double?
            public let lng: Double?
        }

        public let mode: String
        public let line: String?
        public let headsign: String?
        public let from: End
        public let to: End
        public let departAt: String
        public let arriveAt: String
        public let departTime: String
        public let arriveTime: String
        public let durationMin: Int
        public let stops: Int?
        public let intermediateStops: [Stop]?
        public let color: String?
        public let delayMin: Int?
        public let realtime: Bool?
        public let cancelled: Bool?
        public let polyline: String?
        public let distanceM: Int?

        public var isRide: Bool { !["walk", "bike", "car"].contains(mode) }
    }

    public struct Journey: Decodable, Equatable, Sendable {
        public let departAt: String
        public let arriveAt: String
        public let departTime: String
        public let arriveTime: String
        public let durationMin: Int
        public let transfers: Int
        public let mode: String
        public let lines: [String]
        public let legs: [Leg]
        public let tight: Bool?

        public var departDate: Date? { TravelJSON.date(departAt) }
        public var arriveDate: Date? { TravelJSON.date(arriveAt) }
        public var rides: [Leg] { legs.filter(\.isRide) }
        public var walkMinutes: Int { legs.filter { $0.mode == "walk" }.reduce(0) { $0 + $1.durationMin } }
        public var cancelled: Bool { legs.contains { $0.cancelled == true } }
        /// When to set off: the first leg's start.
        public var leaveTime: String { legs.first?.departTime ?? departTime }
    }

    public struct Ticket: Decodable, Equatable, Sendable {
        public struct Shop: Decodable, Equatable, Sendable {
            public let label: String
            public let url: String
        }

        public let coveredByDeutschlandticket: Bool
        public let priceEur: Double?
        public let label: String?
        public let reason: String?
        public let howToBuy: [Shop]?
    }

    public struct Disruption: Decodable, Equatable, Sendable {
        public struct Brief: Decodable, Equatable, Sendable { public let headline: String? }
        public let title: String
        public let lines: [String]?
        public let on: String?
        public let brief: Brief?

        public var headline: String { brief?.headline ?? title }
    }

    public struct Hour: Decodable, Equatable, Sendable {
        public let at: String
        public let condition: String?
        public let temperature: Double?
        public let precipProbability: Int?
    }

    public struct Lift: Decodable, Equatable, Sendable {
        public let label: String
        public let stop: String?
    }

    public let journeys: [Journey]
    public let from: TripPoint?
    public let to: TripPoint?
    public let ticket: Ticket?
    public let disruptions: [Disruption]
    public let weather: [Hour]
    /// Lifts out of order on each way, by its index.
    public let lifts: [[Lift]]
    public let checks: String?
    public let degraded: Bool

    public static func decode(_ data: Data) throws -> TripPlan {
        guard let json = try JSONSerialization.jsonObject(with: data) as? [String: Any] else { throw DecodingError.dataCorrupted(.init(codingPath: [], debugDescription: "Not an object")) }
        func part<T: Decodable>(_ value: Any?, as type: T.Type) -> T? {
            guard let value, !(value is NSNull), let data = try? JSONSerialization.data(withJSONObject: value, options: .fragmentsAllowed) else { return nil }
            return try? TravelJSON.decoder.decode(T.self, from: data)
        }
        func end(_ value: Any?) -> TripPoint? {
            guard let object = value as? [String: Any], let lat = object["lat"] as? Double, let lng = object["lng"] as? Double else { return nil }
            return TripPoint(name: object["name"] as? String ?? "", lat: lat, lng: lng)
        }
        let conditions = json["conditions"] as? [String: Any]
        let weather = conditions?["weather"] as? [String: Any]
        let lifts = conditions?["lifts"] as? [String: Any]
        return TripPlan(
            journeys: part(json["journeys"], as: [Lossy<Journey>].self)?.compactMap(\.value) ?? [],
            from: end(json["from"]),
            to: end(json["to"]),
            ticket: part(json["ticket"], as: Ticket.self),
            disruptions: part(json["disruptions"], as: [Lossy<Disruption>].self)?.compactMap(\.value) ?? [],
            weather: (weather?["available"] as? Bool ?? false) ? part(weather?["hours"], as: [Lossy<Hour>].self)?.compactMap(\.value) ?? [] : [],
            lifts: part(lifts?["by_journey"], as: [[Lift]].self) ?? [],
            checks: json["checks"] as? String,
            degraded: json["degraded"] as? Bool ?? false
        )
    }

    /// The journey as the server stores it (Add to plan, I'm on my way): sent back as it came.
    public static func raw(_ data: Data, index: Int) -> Data? {
        guard let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let journeys = json["journeys"] as? [[String: Any]], journeys.indices.contains(index) else { return nil }
        return try? JSONSerialization.data(withJSONObject: journeys[index])
    }
}

/// A way kept for a plan on their day, and when they'll be reminded to leave.
public struct PlannedTrip: Decodable, Equatable, Sendable {
    public let id: Int
    public let targetId: String?
    public let departTime: String?
    public let arriveTime: String?
    public let remind: Bool
    public let remindAt: String?

    public var remindDate: Date? { TravelJSON.date(remindAt) }

    public static func decode(_ data: Data) throws -> PlannedTrip {
        struct Reply: Decodable { let planned: PlannedTrip }
        return try TravelJSON.decoder.decode(Reply.self, from: data).planned
    }
}
