import Foundation

/// Our own navigation: where the phone is on a trip's route, by its lines (our router's), never a
/// map provider's. It picks the step they're on, the next turn on foot, and says when they've left
/// the way or missed the ride they were walking to.
public struct Guide: Sendable {
    /// Further than this from every line is off the way.
    public static let offRouteMetres = 60.0
    /// Within this of the end is there.
    public static let arrivedMetres = 30.0
    /// Within this of where the way begins is still setting off, never off the way.
    public static let startMetres = 150.0
    /// Still at the stop this long after the ride left: it left without them.
    public static let leftStopSeconds = 180.0
    /// This far along a ride's line from where it boards: on it, not waiting at the stop.
    public static let onBoardMetres = 120.0
    /// Within this of a stop on a ride's line: at it (passed once beyond).
    public static let atStopMetres = 25.0
    /// Within this of where a walk ends (or within the fix's own uncertainty): at the stop.
    public static let nearStopMetres = 40.0
    /// The phone is gone by after this long without a fix (underground, indoors): the clock goes on.
    public static let freshSeconds = 60.0
    /// A fix rougher than this doesn't say which step they're on.
    public static let trustMetres = 65.0
    /// Turns, and metres to them, only from a fix this good.
    public static let turnMetres = 30.0

    struct Line: Sendable {
        let leg: Int
        let points: [TodayPage.Point]
        /// Metres from the line's start to each point.
        let along: [Double]
    }

    /// A stop of a ride: where it sits on the ride's line, and when the ride is due there.
    struct Mark: Sendable {
        let name: String
        let along: Double
        let at: Date
    }

    let trip: TodayPage.Trip
    let lines: [Line]
    /// Each ride's stops on its line, from where it boards to where they get off (by leg).
    let marks: [Int: [Mark]]

    public init(trip: TodayPage.Trip) {
        self.trip = trip
        lines = trip.legs.enumerated().compactMap { index, leg in
            let points = (leg.polyline.map { Polyline.decode($0) } ?? []).map { TodayPage.Point(latitude: $0.latitude, longitude: $0.longitude) }
            guard points.count > 1 else { return nil }
            var along = [0.0]
            for i in 1..<points.count { along.append(along[i - 1] + Self.metres(points[i - 1], points[i])) }
            // A change within one stop has no path (0 m): nothing to follow, nothing to snap the phone to.
            guard (along.last ?? 0) >= 10 else { return nil }
            return Line(leg: index, points: points, along: along)
        }
        var marks: [Int: [Mark]] = [:]
        for line in lines where trip.legs[line.leg].rides {
            let leg = trip.legs[line.leg]
            var stops = [Mark(name: leg.from, along: 0, at: leg.departAt)]
            for stop in leg.stopsOnTheWay where stop.name != leg.from && stop.name != leg.to {
                guard let latitude = stop.latitude, let longitude = stop.longitude, let last = stops.last,
                      var at = TodayPage.today(stop.time, on: last.at) else { continue }
                // Past midnight the clock starts again.
                if at < last.at.addingTimeInterval(-60) { at = at.addingTimeInterval(86_400) }
                let along = Self.snap(TodayPage.Point(latitude: latitude, longitude: longitude), onto: line).along
                guard along > last.along, at >= last.at else { continue }
                stops.append(Mark(name: stop.name, along: along, at: at))
            }
            stops.append(Mark(name: leg.to, along: max(line.along.last ?? 0, (stops.last?.along ?? 0) + 1), at: max(leg.arriveAt, stops.last?.at ?? leg.arriveAt)))
            marks[line.leg] = stops
        }
        self.marks = marks
    }

    /// Where they are on the way.
    public struct Position: Equatable, Sendable {
        /// The leg they're on.
        public let leg: Int
        /// The step that leg is in the trip's step list.
        public let step: Int
        /// Metres from the line, and metres left on this leg.
        public let offBy: Double
        public let metresLeft: Double
        public let offRoute: Bool
        public let arrived: Bool
        /// On foot: the next turn and how far it is.
        public let turn: TodayPage.Turn?
        public let metresToTurn: Int?
        /// The ride they were walking to has left without them.
        public let missed: TodayPage.Trip.Leg?
        /// On a ride: how it's going, by the phone.
        public var ride: Ride?
        /// When the fix was, and how far off it may be (metres).
        public var at: Date = .distantPast
        public var accuracy: Double = 0

        /// The phone knows where they are: a fix in the last minute, good enough to go by. Without one
        /// nothing is said from where they are (no "walk to the stop", no metres): the clock goes on.
        public func trusted(at now: Date) -> Bool {
            now.timeIntervalSince(at) <= Guide.freshSeconds && accuracy <= Guide.trustMetres
        }

        /// At the end of the leg they're on (the stop they walk to): within 40 m, or within the fix's
        /// own uncertainty, so a rough fix in a station never says "walk to the station".
        public var atEnd: Bool { metresLeft <= max(Guide.nearStopMetres, accuracy) }

        /// A ride as the phone sees it: where it is against its timetable. Late is measured where the
        /// phone is; between fixes (underground, standing still) the ride goes on at its timetable's
        /// pace from there, so the stops and minutes left keep counting, late as it was.
        public struct Ride: Equatable, Sendable {
            /// On it: moved off along its line from where it boards (not still waiting at the stop).
            public let onBoard: Bool
            /// Seconds behind its timetable where the phone was (negative: ahead).
            public let late: TimeInterval
            /// When the phone said so.
            public let at: Date
        }
    }

    /// Where `point` is on the way at `date`, never going back before the leg they were on (`after`).
    public func position(of point: TodayPage.Point, at date: Date, after: Int = 0, accuracy: Double = 10) -> Position? {
        guard !lines.isEmpty else { return nil }
        // The nearest line from the leg they were on; at a stop where two lines meet, the earlier
        // one, until the next has clearly taken over (15 m).
        var best: (line: Line, offBy: Double, along: Double)?
        for line in lines where line.leg >= after {
            let (offBy, along) = Self.snap(point, onto: line)
            if best == nil || offBy < best!.offBy - 15 { best = (line, offBy, along) }
        }
        guard let best else { return nil }
        let leg = trip.legs[best.line.leg]
        let total = best.line.along.last ?? 0
        let end = lines.last.flatMap(\.points.last)
        let arrived = end.map { Self.metres(point, $0) <= Self.arrivedMetres } ?? false
        let steps = trip.steps
        let step = arrived ? steps.count - 1 : (steps.firstIndex { $0.leg == best.line.leg } ?? 0)

        // On foot: the first turn that starts ahead of them.
        var turn: TodayPage.Turn?
        var toTurn: Int?
        if !leg.rides, !leg.turns.isEmpty, accuracy <= Self.turnMetres {
            var start = 0.0
            for candidate in leg.turns {
                if start > best.along + 5 {
                    turn = candidate
                    toTurn = Int((start - best.along).rounded())
                    break
                }
                start += Double(candidate.metres)
            }
        }

        // Walking to a ride whose time has passed while they're still short of its stop, or still
        // at the stop long after it left (on board, the phone would have moved on with it).
        var missed: TodayPage.Trip.Leg?
        if !leg.rides, best.line.leg + 1 < trip.legs.count {
            let ride = trip.legs[best.line.leg + 1]
            let gone = date.timeIntervalSince(ride.departAt)
            if ride.rides, gone > 60, total - best.along > max(Self.nearStopMetres, accuracy) || gone > Self.leftStopSeconds { missed = ride }
        }

        // On a ride: how late it runs where the phone is, against the ride's own stop times.
        var ride: Position.Ride?
        if leg.rides, let stops = marks[best.line.leg], stops.count >= 2 {
            let onBoard = best.along > Self.onBoardMetres
            // Waiting at the stop past the time: at least that late (the board says by how much more).
            let late = onBoard ? date.timeIntervalSince(Self.due(best.along, stops)) : max(0, date.timeIntervalSince(leg.departAt))
            ride = Position.Ride(onBoard: onBoard, late: min(max(late, -300), 3600), at: date)
        }

        // Still getting to where the way starts (the router begins on the nearest path, not the doorstep).
        let starting = lines.first.flatMap { first in first.points.first.map { Self.metres(point, $0) <= Self.startMetres && best.line.leg == first.leg } } ?? false
        // Off the way only beyond what the fix itself may be off by.
        return Position(leg: best.line.leg, step: step, offBy: best.offBy, metresLeft: max(0, total - best.along),
                        offRoute: best.offBy - accuracy > Self.offRouteMetres && !arrived && !starting, arrived: arrived,
                        turn: turn, metresToTurn: toTurn, missed: missed, ride: ride, at: date, accuracy: accuracy)
    }

    /// When the ride is due at a point of its line: between two stops, as far on in time as in metres.
    static func due(_ along: Double, _ stops: [Mark]) -> Date {
        guard let first = stops.first, along > first.along else { return stops.first?.at ?? .now }
        for (a, b) in zip(stops, stops.dropFirst()) where along <= b.along {
            let part = (along - a.along) / max(b.along - a.along, 1)
            return a.at.addingTimeInterval(part * b.at.timeIntervalSince(a.at))
        }
        return stops.last!.at
    }

    /// A ride at `date`, running `late` behind its timetable: the stops still to come (the one to get
    /// off at counted, so 1 is "get off at the next stop"), and the next one.
    public func rideLeft(leg: Int, at date: Date, late: TimeInterval) -> (stops: Int, next: String?)? {
        // Every stop known (with where it is): else the stops can't be counted, only the time.
        guard let stops = marks[leg], stops.count >= 2, stops.count - 1 >= (trip.legs[leg].stops ?? 0) else { return nil }
        let timetable = date.addingTimeInterval(-late)
        let ahead = stops.dropFirst().filter { $0.at > timetable }
        return (ahead.count, ahead.first?.name)
    }

    /// The nearest point of a line to `point`: how far off it is, and how far along the line.
    static func snap(_ point: TodayPage.Point, onto line: Line) -> (offBy: Double, along: Double) {
        var best = (offBy: Double.infinity, along: 0.0)
        for i in 1..<line.points.count {
            let a = line.points[i - 1], b = line.points[i]
            // Flat metres around `a`: fine across a city.
            let (bx, by) = offset(from: a, to: b), (px, py) = offset(from: a, to: point)
            let length = bx * bx + by * by
            let t = length == 0 ? 0 : max(0, min(1, (px * bx + py * by) / length))
            let dx = px - t * bx, dy = py - t * by
            let offBy = (dx * dx + dy * dy).squareRoot()
            if offBy < best.offBy { best = (offBy, line.along[i - 1] + t * length.squareRoot()) }
        }
        return best
    }

    static func offset(from a: TodayPage.Point, to b: TodayPage.Point) -> (Double, Double) {
        let metresPerDegree = 111_320.0
        return ((b.longitude - a.longitude) * metresPerDegree * cos(a.latitude * .pi / 180), (b.latitude - a.latitude) * metresPerDegree)
    }

    public static func metres(_ a: TodayPage.Point, _ b: TodayPage.Point) -> Double {
        let (x, y) = offset(from: a, to: b)
        return (x * x + y * y).squareRoot()
    }
}
