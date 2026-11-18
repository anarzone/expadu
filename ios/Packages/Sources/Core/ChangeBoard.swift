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

    /// The tram that gets them there first of those still to come once they're at the stop.
    public func best(ready: Date) -> Run? {
        runs.filter { !$0.cancelled && $0.departAt >= ready }
            .min { ($0.arriveAt, $0.departAt) < ($1.arriveAt, $1.departAt) }
    }

    /// A better tram than theirs, and why: theirs leaves before they get there (or won't come), or another
    /// gets them there at least a minute sooner. Nil when theirs is the best, or theirs isn't on the board.
    public func better(ready: Date) -> (run: Run, gone: Bool)? {
        guard let yours, let best = best(ready: ready), best.id != yours.id else { return nil }
        let gone = yours.cancelled || yours.departAt < ready
        return gone || best.arriveAt.addingTimeInterval(60) < yours.arriveAt ? (best, gone) : nil
    }
}
