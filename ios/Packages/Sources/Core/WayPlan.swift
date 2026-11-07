import Foundation

/// Getting there: the planner's ways to a place, each with its fit to the start, and what's good to
/// know on the way. `plan` names the ways when one is started.
public struct WayPlan: Equatable, Sendable {
    public struct Ticket: Equatable, Sendable {
        public let covered: Bool
        public let label: String?
        public let price: Double?
        public let how: String?

        public init(covered: Bool, label: String?, price: Double?, how: String?) {
            self.covered = covered
            self.label = label
            self.price = price
            self.how = how
        }
    }

    public struct Notice: Equatable, Sendable {
        public let title: String
        public let lines: [String]
        public let major: Bool

        public init(title: String, lines: [String], major: Bool) {
            self.title = title
            self.lines = lines
            self.major = major
        }
    }

    public let plan: String
    public let from: String
    public let to: String
    public let ways: [TodayPage.Way]
    public let ticket: Ticket?
    /// When rain is likely on the way, and how likely.
    public let rain: (at: String, chance: Int)?
    /// Lifts out on the first way.
    public let lifts: [String]
    public let notices: [Notice]

    public init(plan: String, from: String, to: String, ways: [TodayPage.Way], ticket: Ticket?, rain: (at: String, chance: Int)?, lifts: [String], notices: [Notice]) {
        self.plan = plan
        self.from = from
        self.to = to
        self.ways = ways
        self.ticket = ticket
        self.rain = rain
        self.lifts = lifts
        self.notices = notices
    }

    public static func == (a: WayPlan, b: WayPlan) -> Bool {
        a.plan == b.plan && a.ways == b.ways && a.ticket == b.ticket && a.rain?.at == b.rain?.at && a.rain?.chance == b.rain?.chance && a.lifts == b.lifts && a.notices == b.notices
    }
}
