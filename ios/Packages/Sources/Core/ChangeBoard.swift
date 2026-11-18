import Foundation

/// A change as a live board (the canvas "When your tram is late", owner 18 Nov 2026): every tram on
/// the ride's stretch, any line, live as the feed knows them, soonest first; the one the trip is on
/// marked; and whether Expadu switches trams for them.
public struct ChangeBoard: Equatable, Sendable {
    public struct Run: Equatable, Sendable, Identifiable {
        public let line: String
        public let color: String?
        public let headsign: String?
        public let departAt: Date
        public let departAtText: String
        public let departTime: String
        public let arriveAt: Date
        public let arriveTime: String
        public let delayMin: Int?
        public let cancelled: Bool
        public let yours: Bool
        public let trip: String?

        public var id: String { trip ?? "\(line)@\(departAtText)" }

        public init(line: String, color: String?, headsign: String?, departAt: Date, departAtText: String, departTime: String,
                    arriveAt: Date, arriveTime: String, delayMin: Int?, cancelled: Bool, yours: Bool, trip: String?) {
            self.line = line; self.color = color; self.headsign = headsign; self.departAt = departAt; self.departAtText = departAtText
            self.departTime = departTime; self.arriveAt = arriveAt; self.arriveTime = arriveTime; self.delayMin = delayMin
            self.cancelled = cancelled; self.yours = yours; self.trip = trip
        }
    }

    public var runs: [Run]
    /// Switch trams for me: Expadu moves them to the best tram they can still make (with Undo); off, it suggests.
    public var autoSwitch: Bool

    public init(runs: [Run], autoSwitch: Bool) {
        self.runs = runs
        self.autoSwitch = autoSwitch
    }

    /// The tram the trip is on.
    public var yours: Run? { runs.first(where: \.yours) }

    /// What a tram on this board must still make: the ride after it, and the latest it may get them in.
    public struct Connection: Equatable, Sendable {
        public let line: String
        public let departTime: String
        /// Where that ride leaves from (the change stop).
        public let stop: String
        /// Getting in by this still makes it (its departure less the walk across).
        public let latest: Date
        /// When the trip gets there when the change is made.
        public let thereTime: String

        public init(line: String, departTime: String, stop: String, latest: Date, thereTime: String) {
            self.line = line; self.departTime = departTime; self.stop = stop; self.latest = latest; self.thereTime = thereTime
        }
    }

    /// Why Expadu would move them to another tram.
    public enum Reason: Equatable, Sendable {
        /// Theirs is cancelled.
        case cancelled
        /// Theirs leaves before they're at the stop.
        case missed
        /// Theirs gets in too late for the ride after it.
        case late
        /// Another gets them there at least a minute sooner (no ride after this one).
        case faster
    }

    /// Whether a tram still makes the ride after it (always, when there's none).
    public func makes(_ run: Run, _ connection: Connection?) -> Bool {
        connection.map { run.arriveAt <= $0.latest } ?? true
    }

    /// The tram to be on of those still to come once they're at the stop. With a ride after it: the
    /// earliest they can catch that still makes it (the owner: "earliest you can catch", a late tram
    /// then has room), else the one in soonest. Without: the one that gets them there first.
    public func best(ready: Date, connection: Connection? = nil) -> Run? {
        let catchable = runs.filter { !$0.cancelled && $0.departAt >= ready }
        if let connection, let run = catchable.filter({ $0.arriveAt <= connection.latest }).min(by: { $0.departAt < $1.departAt }) {
            return run
        }
        return catchable.min { ($0.arriveAt, $0.departAt) < ($1.arriveAt, $1.departAt) }
    }

    /// A better tram than theirs, and why. With a ride after it, theirs is only replaced when it won't
    /// do (cancelled, gone before they're there, or in too late for the change): an earlier tram to
    /// the same change isn't worth moving them for. Without one, also when another gets them there a
    /// minute sooner. Nil when theirs is the best, or theirs isn't on the board.
    public func better(ready: Date, connection: Connection? = nil) -> (run: Run, reason: Reason)? {
        guard let yours, let best = best(ready: ready, connection: connection), best.id != yours.id else { return nil }
        let reason: Reason? = yours.cancelled ? .cancelled
            : yours.departAt < ready ? .missed
            : !makes(yours, connection) ? .late
            : connection == nil && best.arriveAt.addingTimeInterval(60) < yours.arriveAt ? .faster
            : nil
        return reason.map { (best, $0) }
    }
}
