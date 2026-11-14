import ActivityKit
import Core
import Foundation

/// The trip's Live Activity: the Lock Screen and the Dynamic Island. Started 15 minutes before
/// leaving (the Leave card) or with the trip, moved on by the guidance as they go, ended when the
/// trip ends. Updates only when what it shows changes; getting off and trouble also light up.
///
/// It only ever shows the trip the server has running: the guidance follows the phone and keeps
/// sending fixes for a moment after a trip ends, and one of those must never bring the trip back
/// (the owner, 14 Nov: the island said "Tram 12 at 13:27" at 13:32, with no trip in the app). And
/// should the app stop, what it shows goes out of date by the clock (a minute or two after the ride
/// it names leaves) and says so, rather than naming a tram that has gone.
@MainActor
final class TripActivity {
    static let shared = TripActivity()
    /// How long before leaving the island starts counting down.
    static let leaveWindow = 15

    private var shown: TripPhase?
    /// When what's shown goes out of date, should no update come.
    private var shownStale: Date?
    /// The trip the server has running, by where it goes (the last word from it); nil: none.
    private var running: String?

    private var activity: Activity<TripActivityAttributes>? { Activity<TripActivityAttributes>.activities.first }

    /// The server's word on the trip, at every Today load. None: any trip on the island goes, one
    /// left by an earlier launch too (the countdown to leaving stays, for `leave`). One: it may show.
    func running(_ trip: TodayPage.Trip?) {
        running = trip?.to
        guard trip == nil else { return }
        if shown?.kind != .leave {
            shown = nil
            shownStale = nil
        }
        Task { await Self.endTrips() }
    }

    /// On the way: the phase now, for the trip the server has running only.
    func sync(_ trip: TodayPage.Trip, here: Guide.Position?, offFor: TimeInterval, at now: Date = .now) {
        guard running == trip.to else { return }
        let phase = TripPhase.of(trip, here: here, offFor: offFor, at: now)
        show(phase, to: trip.to, stale: Self.outOfDate(phase, trip, at: now), at: now)
    }

    /// Before leaving: counts down once the leave time is close; gone again if it isn't.
    func leave(_ leave: TodayPage.Leave?, at now: Date = .now) {
        guard let leave, let minutes = leave.leaveInMinutes, (0...Self.leaveWindow).contains(minutes), let phase = TripPhase.leave(leave, at: now) else {
            if shown?.kind == .leave { end() }
            return
        }
        show(phase, to: leave.to, stale: phase.until?.addingTimeInterval(600), at: now)
    }

    /// The trip ended here: the island goes, and nothing from the trip that was brings it back.
    func end() {
        running = nil
        shown = nil
        shownStale = nil
        Task { await Self.endAll(except: nil) }
    }

    /// When what the island says goes out of date by the clock alone, should no update come (the app
    /// stopped): two minutes after the time it's about (the ride it waits for leaves, the ride gets
    /// in) or, on foot, after the next ride leaves (as late as the board says); never later than half
    /// an hour after getting there. While the app runs, the phase has moved on well before.
    static func outOfDate(_ phase: TripPhase, _ trip: TodayPage.Trip, at now: Date) -> Date? {
        let end = trip.legs.last?.arriveAt.addingTimeInterval(1800)
        let next = trip.legs.lazy.filter(\.rides)
            .map { $0.departAt.addingTimeInterval(TimeInterval(max(0, $0.delayMin ?? 0) * 60)) }
            .first { $0 > now.addingTimeInterval(-60) }
        guard let due = phase.until ?? (phase.kind == .walk ? next : nil) else { return end }
        let stale = due.addingTimeInterval(120)
        return end.map { min($0, stale) } ?? stale
    }

    /// Each task finds its own activities: an Activity can't cross into one.
    nonisolated private static func endAll(except id: String?) async {
        for activity in Activity<TripActivityAttributes>.activities where activity.id != id {
            await activity.end(nil, dismissalPolicy: .immediate)
        }
    }

    /// Every trip on the island (not a countdown to leaving), whichever launch started it.
    nonisolated private static func endTrips() async {
        for activity in Activity<TripActivityAttributes>.activities where activity.content.state.kind != .leave {
            await activity.end(nil, dismissalPolicy: .immediate)
        }
    }

    nonisolated private static func update(_ id: String, _ content: ActivityContent<TripPhase>, alert: AlertConfiguration?, ending: Bool) async {
        guard let activity = Activity<TripActivityAttributes>.activities.first(where: { $0.id == id }) else { return }
        await activity.update(content, alertConfiguration: alert)
        if ending { await activity.end(content, dismissalPolicy: .after(.now.addingTimeInterval(120))) }
    }

    private func show(_ phase: TripPhase, to: String, stale: Date?, at now: Date) {
        // The same phase again only to keep it from going out of date while it's still right (the
        // app is running and says so).
        let renew = phase == shown && (shownStale.map { $0 <= now.addingTimeInterval(60) } ?? false)
        guard ActivityAuthorizationInfo().areActivitiesEnabled, phase != shown || renew else { return }
        let before = shown
        shown = phase
        // Never already out of date when the app itself says it.
        let staleDate = stale.map { max($0, now.addingTimeInterval(120)) }
        shownStale = staleDate
        let content = ActivityContent(state: phase, staleDate: staleDate)
        if let activity, activity.attributes.to == to {
            // Getting off and trouble light the island up and sound once; the rest change quietly.
            let alert: AlertConfiguration? = before?.kind != phase.kind && (phase.kind == .getOff || phase.kind == .trouble)
                ? AlertConfiguration(title: LocalizedStringResource(stringLiteral: phase.title), body: LocalizedStringResource(stringLiteral: phase.detail), sound: .default)
                : nil
            let id = activity.id
            let ending = phase.kind == .arrived
            Task {
                // One trip, one activity: a second one splits the island into two small bubbles.
                await Self.endAll(except: id)
                await Self.update(id, content, alert: alert, ending: ending)
            }
            return
        }
        // Another trip's activity, or none: this one starts.
        let started = try? Activity.request(attributes: TripActivityAttributes(to: to), content: content, pushType: nil)
        let keep = started?.id
        Task { await Self.endAll(except: keep) }
    }
}
