import Foundation
#if canImport(ActivityKit) && os(iOS)
import ActivityKit
#endif

/// The trip on the Lock Screen and in the Dynamic Island: what the island shows at each phase,
/// worked out from the trip, where the phone is (Guide) and the clock. The app and the widget
/// share it; the widget only draws it.
public struct TripPhase: Codable, Hashable, Sendable {
    public enum Kind: String, Codable, Hashable, Sendable { case leave, walk, wait, ride, getOff, trouble, arrived }

    public let kind: Kind
    /// The compact island's left: a line's badge on its colour, or an SF Symbol.
    public let badge: String?
    public let color: String?
    public let symbol: String
    /// The compact island's right when it isn't a countdown: "80 m", "3 stops", "There", "+4".
    public let short: String
    /// Counting down to this in the island, without an update: leaving, the ride coming.
    public let until: Date?
    /// The expanded island and the Lock Screen: what to do now, and what comes after.
    public let title: String
    public let detail: String
    /// The trip's bars: how many steps, which one now.
    public let steps: Int
    public let current: Int
    /// When they'll be there, and the minutes a ride runs late.
    public let arrive: String
    public let delay: Int?

    public init(kind: Kind, badge: String?, color: String?, symbol: String, short: String, until: Date?, title: String, detail: String,
                steps: Int, current: Int, arrive: String, delay: Int? = nil) {
        self.kind = kind
        self.badge = badge
        self.color = color
        self.symbol = symbol
        self.short = short
        self.until = until
        self.title = title
        self.detail = detail
        self.steps = steps
        self.current = current
        self.arrive = arrive
        self.delay = delay
    }

    /// Before leaving: the Leave card's countdown.
    public static func leave(_ leave: TodayPage.Leave, at: Date) -> TripPhase? {
        guard let leaveTime = leave.leaveTime, let minutes = leave.leaveInMinutes else { return nil }
        let line = leave.ways.first?.lines.first
        let how = leave.line.map { "\(Self.rideName(leave.ways.first?.legs.first(where: \.rides)) ?? "Line \($0)") from \(leave.from ?? "the stop")" }
            ?? (leave.ways.first?.mode == "bike" ? "By bike" : "On foot")
        // The leave time itself, so the countdown is the same at every update.
        let until = TodayPage.today(leaveTime, on: at) ?? at.addingTimeInterval(TimeInterval(max(minutes, 0) * 60))
        return TripPhase(kind: .leave, badge: line.map(\.line), color: line?.color, symbol: "figure.walk", short: "\(max(minutes, 0))′",
                         until: until, title: "Leave at \(leaveTime)", detail: how,
                         steps: 0, current: 0, arrive: leave.arriveTime ?? "")
    }

    /// On the way: walking with the next turn, waiting for the ride, riding, getting off, gone wrong, there.
    public static func of(_ trip: TodayPage.Trip, here: Guide.Position?, offFor: TimeInterval, at now: Date) -> TripPhase {
        // As late as the ride runs: measured by the phone on board, else the live board's delay.
        let behind = trip.late(here: here, at: now)
        // Where they are only while the phone knows it; else nothing is said from it.
        let fresh = here.flatMap { $0.trusted(at: now) ? $0 : nil }
        let live = trip.live(at: now, here: here)
        let steps = trip.steps
        let step = live.step
        // There as late as it runs ("There 20:32", not the plan's 20:29 behind a late tram).
        let arrive = behind >= 60 ? trip.legs.last.map { TodayPage.clock($0.arriveAt.addingTimeInterval((behind / 60).rounded() * 60)) } ?? "" : trip.arriveTime ?? steps.last?.time ?? ""
        let leg = step.leg.map { trip.legs[$0] }
        // The ride this moment is about (the one on, or the one walked to), as the live board reads it.
        let upcoming = steps.dropFirst(live.current).first { $0.kind == .ride }
        let ride = (step.kind == .ride ? leg : nil) ?? upcoming?.leg.map { trip.legs[$0] }
        // Said as the phone measured it on board, else as the board says.
        let measured = here?.ride?.onBoard == true && behind >= 120 ? Int((behind / 60).rounded()) : nil
        let late = measured ?? ride.flatMap { $0.cancelled ? nil : $0.delayMin }.flatMap { $0 >= 2 ? $0 : nil }
        func make(_ kind: Kind, badge: String? = nil, color: String? = nil, symbol: String, short: String, until: Date? = nil, title: String, detail: String) -> TripPhase {
            TripPhase(kind: kind, badge: badge, color: color, symbol: symbol, short: short, until: until, title: title,
                      detail: late.map { "\($0) min late · " + detail } ?? detail,
                      steps: steps.count, current: live.current, arrive: arrive, delay: late)
        }

        if live.current == steps.count - 1 || fresh?.arrived == true {
            let early = trip.startTime.flatMap { start in TodayPage.minutes(from: arrive, to: start) }
            return make(.arrived, symbol: "checkmark", short: "There", title: "You’re there",
                        detail: early.map { $0 > 0 ? "\($0) min early" : "Enjoy it" } ?? "Enjoy it")
        }
        if let ride, ride.cancelled, live.current < steps.count - 1 {
            return make(.trouble, symbol: "exclamationmark.triangle.fill", short: "Off", title: "\(ride.name) is cancelled",
                        detail: "Open Expadu for a new route from here")
        }
        if let missed = fresh?.missed {
            return make(.trouble, symbol: "exclamationmark.triangle.fill", short: "Gone", title: "\(missed.name) has left",
                        detail: "Open Expadu for a new way from here")
        }
        if let here = fresh, here.offRoute, offFor > 15 {
            return make(.trouble, symbol: "exclamationmark.triangle.fill", short: "Off", title: "You’re off the way",
                        detail: "\(Int(here.offBy)) m from it · a new way from here")
        }
        let next = steps.indices.contains(live.current + 1) ? steps[live.current + 1] : nil
        switch step.kind {
        case .walk:
            // Close to the stop with the ride still to come: waiting for it.
            if let next, next.kind == .ride, let ride = next.leg.map({ trip.legs[$0] }), fresh?.atEnd == true || now >= step.at.addingTimeInterval(TimeInterval((leg?.minutes ?? 0) * 60)) {
                // When it comes, as late as the board says.
                let leaves = ride.departAt.addingTimeInterval(behind)
                return make(.wait, badge: ride.line, color: ride.color, symbol: "tram.fill", short: "\(minutes(until: leaves, from: now))′",
                            until: leaves, title: "\(ride.name) to \(ride.to) at \(ride.departTime)", detail: ride.headsign.map { "Towards \($0) · from \(ride.from)" } ?? "From \(ride.from)")
            }
            if let turn = fresh?.turn, let metres = fresh?.metresToTurn {
                return make(.walk, symbol: turn.symbol, short: Self.distance(metres), title: turn.words,
                            detail: next.map { $0.kind == .ride ? "\($0.name) at \($0.time)" : "There at \(arrive)" } ?? "There at \(arrive)")
            }
            return make(.walk, symbol: leg?.mode == "bike" ? "bicycle" : "figure.walk", short: "\(live.big)", title: step.title,
                        detail: next.map { $0.kind == .ride ? "\($0.name) at \($0.time)" : "There at \(arrive)" } ?? "There at \(arrive)")
        case .ride:
            guard let ride = leg, let index = step.leg else { fallthrough }
            // The ride's times as it runs: late as the phone measured on board, or as the board says.
            let leaves = ride.departAt.addingTimeInterval(behind)
            let gets = ride.arriveAt.addingTimeInterval(behind)
            // At the stop before it comes (the phone not yet moving with it): waiting for it, counting down.
            if now < leaves, here?.ride?.onBoard != true {
                return make(.wait, badge: ride.line, color: ride.color, symbol: "tram.fill", short: "\(minutes(until: leaves, from: now))′",
                            until: leaves, title: "\(ride.name) to \(ride.to) at \(ride.departTime)", detail: ride.headsign.map { "Towards \($0) · from \(ride.from)" } ?? "From \(ride.from)")
            }
            // The stops still to come, from where the phone is (and on at the timetable's pace from there).
            let left = Guide(trip: trip).rideLeft(leg: index, at: now, late: behind)
            // Leaving the stop before theirs, the island says so: one stop before, as promised.
            if let left, left.stops <= 1 {
                return make(.getOff, badge: ride.line, color: ride.color, symbol: "figure.walk.departure", short: left.stops == 0 ? "Now" : "Next",
                            until: gets, title: "Get off at \(ride.to)",
                            detail: (left.stops == 0 ? "" : "Next stop · ") + (next.map { $0.kind == .ride ? "then \($0.name) at \($0.time)" : "then \($0.title.lowercased())" } ?? "there at \(arrive)"))
            }
            // Without every stop known: the time alone, a minute before getting off.
            if left == nil, gets.timeIntervalSince(now) <= 60 {
                return make(.getOff, badge: ride.line, color: ride.color, symbol: "figure.walk.departure", short: "Now",
                            title: "Get off at \(ride.to)", detail: next.map { $0.kind == .ride ? "Then \($0.name) at \($0.time)" : "Then \($0.title.lowercased())" } ?? "There at \(arrive)")
            }
            let count = left?.stops ?? ride.stops.map { stops in
                max(1, stops - Int((now.timeIntervalSince(leaves) / max(gets.timeIntervalSince(leaves), 60)) * Double(stops)))
            }
            // The island's corner has room for minutes; the stops go with the words.
            let stops = count.map { $0 == 1 ? "1 stop · " : "\($0) stops · " } ?? ""
            return make(.ride, badge: ride.line, color: ride.color, symbol: "tram.fill", short: "\(minutes(until: gets, from: now))′",
                        until: gets, title: "Get off at \(ride.to)", detail: "\(stops)\(TodayPage.clock(gets))")
        case .getOff:
            return make(.getOff, badge: leg?.line, color: leg?.color, symbol: "figure.walk.departure", short: "Now", title: step.title, detail: step.sub)
        case .there:
            return make(.arrived, symbol: "checkmark", short: "There", title: "You’re there", detail: "Enjoy it")
        }
    }

    static func minutes(until date: Date, from now: Date) -> Int {
        max(0, Int((date.timeIntervalSince(now) / 60).rounded(.up)))
    }

    static func distance(_ metres: Int) -> String {
        metres < 1000 ? "\(max(10, (metres / 10) * 10)) m" : String(format: "%.1f km", Double(metres) / 1000)
    }

    static func rideName(_ leg: TodayPage.Trip.Leg?) -> String? { leg?.name }
}

#if canImport(ActivityKit) && os(iOS)
/// The Live Activity: where to (fixed), and the phase (changes as they go).
public struct TripActivityAttributes: ActivityAttributes {
    public typealias ContentState = TripPhase
    public let to: String

    public init(to: String) {
        self.to = to
    }
}
#endif
