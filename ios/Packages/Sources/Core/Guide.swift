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

    struct Line: Sendable {
        let leg: Int
        let points: [TodayPage.Point]
        /// Metres from the line's start to each point.
        let along: [Double]
    }

    let trip: TodayPage.Trip
    let lines: [Line]

    public init(trip: TodayPage.Trip) {
        self.trip = trip
        lines = trip.legs.enumerated().compactMap { index, leg in
            let points = (leg.polyline.map { Polyline.decode($0) } ?? []).map { TodayPage.Point(latitude: $0.latitude, longitude: $0.longitude) }
            guard points.count > 1 else { return nil }
            var along = [0.0]
            for i in 1..<points.count { along.append(along[i - 1] + Self.metres(points[i - 1], points[i])) }
            return Line(leg: index, points: points, along: along)
        }
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
    }

    /// Where `point` is on the way at `date`, never going back before the leg they were on (`after`).
    public func position(of point: TodayPage.Point, at date: Date, after: Int = 0) -> Position? {
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
        if !leg.rides, !leg.turns.isEmpty {
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

        // Walking to a ride whose time has passed while they're still short of its stop.
        var missed: TodayPage.Trip.Leg?
        if !leg.rides, best.line.leg + 1 < trip.legs.count {
            let ride = trip.legs[best.line.leg + 1]
            if ride.rides, date.timeIntervalSince(ride.departAt) > 60, total - best.along > 40 { missed = ride }
        }

        // Still getting to where the way starts (the router begins on the nearest path, not the doorstep).
        let starting = lines.first.flatMap { first in first.points.first.map { Self.metres(point, $0) <= Self.startMetres && best.line.leg == first.leg } } ?? false
        return Position(leg: best.line.leg, step: step, offBy: best.offBy, metresLeft: max(0, total - best.along),
                        offRoute: best.offBy > Self.offRouteMetres && !arrived && !starting, arrived: arrived,
                        turn: turn, metresToTurn: toTurn, missed: missed)
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
