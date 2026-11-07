import ActivityKit
import Core
import Foundation

/// The trip's Live Activity: the Lock Screen and the Dynamic Island. Started 15 minutes before
/// leaving (the Leave card) or with the trip, moved on by the guidance as they go, ended when the
/// trip ends. Updates only when what it shows changes; getting off and trouble also light up.
@MainActor
final class TripActivity {
    static let shared = TripActivity()
    /// How long before leaving the island starts counting down.
    static let leaveWindow = 15

    private var shown: TripPhase?

    private var activity: Activity<TripActivityAttributes>? { Activity<TripActivityAttributes>.activities.first }

    /// On the way: the phase now.
    func sync(_ trip: TodayPage.Trip, here: Guide.Position?, offFor: TimeInterval, at now: Date = .now) {
        show(TripPhase.of(trip, here: here, offFor: offFor, at: now), to: trip.to, stale: trip.legs.last?.arriveAt.addingTimeInterval(1800))
    }

    /// Before leaving: counts down once the leave time is close; gone again if it isn't.
    func leave(_ leave: TodayPage.Leave?, at now: Date = .now) {
        guard let leave, let minutes = leave.leaveInMinutes, (0...Self.leaveWindow).contains(minutes), let phase = TripPhase.leave(leave, at: now) else {
            if shown?.kind == .leave { end() }
            return
        }
        show(phase, to: leave.to, stale: phase.until?.addingTimeInterval(600))
    }

    func end() {
        shown = nil
        Task { await Self.endAll(except: nil) }
    }

    /// Each task finds its own activities: an Activity can't cross into one.
    nonisolated private static func endAll(except id: String?) async {
        for activity in Activity<TripActivityAttributes>.activities where activity.id != id {
            await activity.end(nil, dismissalPolicy: .immediate)
        }
    }

    nonisolated private static func update(_ id: String, _ content: ActivityContent<TripPhase>, alert: AlertConfiguration?, ending: Bool) async {
        guard let activity = Activity<TripActivityAttributes>.activities.first(where: { $0.id == id }) else { return }
        await activity.update(content, alertConfiguration: alert)
        if ending { await activity.end(content, dismissalPolicy: .after(.now.addingTimeInterval(120))) }
    }

    private func show(_ phase: TripPhase, to: String, stale: Date?) {
        guard ActivityAuthorizationInfo().areActivitiesEnabled, phase != shown else { return }
        let before = shown
        shown = phase
        let content = ActivityContent(state: phase, staleDate: stale)
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
