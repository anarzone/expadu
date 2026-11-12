import API
import Core
import DesignSystem
import SwiftUI

/// The next trip on their day before it starts: when to leave, counting down. The same row on Today
/// and the Travel board; a tap opens Leave at (Start trip, Other routes).
struct LeaveRow: View {
    let leave: TodayPage.Leave
    var dim = false
    let tap: () -> Void

    var body: some View {
        Button(action: tap) {
            HStack(spacing: 12) {
                let minutes = max(0, leave.leaveInMinutes ?? 0)
                ZStack {
                    Circle().stroke(Palette.border, lineWidth: 4)
                    Circle().trim(from: 0, to: max(0.04, min(1, Double(minutes) / 10)))
                        .stroke(minutes <= 2 ? Palette.action : Palette.navTeal, style: StrokeStyle(lineWidth: 4, lineCap: .round))
                        .rotationEffect(.degrees(-90))
                    Text("\(minutes)").font(.geist(15, .bold)).monospacedDigit().contentTransition(.numericText(countsDown: true))
                }
                .frame(width: 40, height: 40)
                VStack(alignment: .leading, spacing: 2) {
                    Text("Leave at \(leave.leaveTime ?? "")").font(.geist(16, .bold))
                    Text(sub).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1)
                }
                Spacer(minLength: 0)
            }
            .tripCard(dim: dim)
        }
        .buttonStyle(PressStyle())
        .accessibilityLabel("Leave at \(leave.leaveTime ?? ""), \(sub)")
    }

    private var sub: String {
        var parts = ["To \(leave.to)"]
        if let start = leave.start { parts.append(start) }
        if let line = leave.line { parts.append("line \(Board.badge(line))") }
        return parts.joined(separator: " · ")
    }
}

/// A trip being followed, the same card on Today and the Travel board (as Citymapper and Apple Maps
/// keep one in sight): what to do now (the step, its line, the minutes it counts down to) and where it
/// goes, moving on by itself, from the phone's place when it knows it (the one follower all screens
/// share), with a strip of how far along the trip is. Off the route or a ride gone, it says so. Tap
/// opens the trip screen; swipe looks at the step before or after (it comes back to now after a while);
/// hold for the map, a new route, spoken turns, I'm there or End.
struct TripCard: View {
    let trip: TodayPage.Trip
    var dim = false
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(TripPlanner.self) private var planner
    @AppStorage("speakTurns") private var speak = false
    @State private var askEnd = false
    @State private var problem: String?
    @State private var picked: Int?
    @State private var swipedLeft = true
    @State private var drag: CGFloat = 0
    @State private var back: Task<Void, Never>?

    private func open() { planner.showTrip = true }
    private func map() { planner.tripOnMap = true; planner.showTrip = true }
    private func end() { askEnd = true }

    /// A new route from where the phone is now, to the same place; nil while it hasn't said where it is.
    private var replan: (() -> Void)? {
        guard let point = planner.follower.point else { return nil }
        return { Task { problem = await today.newWay(session.api, trip: trip, from: point) } }
    }

    private func look(by delta: Int, from index: Int, current: Int) {
        let next = min(max(index + delta, 0), trip.steps.count - 1)
        guard next != index else { return }
        withAnimation(.spring(duration: 0.4, bounce: 0.2)) { picked = next == current ? nil : next }
        back?.cancel()
        back = Task {
            try? await Task.sleep(for: .seconds(6))
            guard !Task.isCancelled else { return }
            withAnimation(.spring(duration: 0.4, bounce: 0.2)) { swipedLeft = false; picked = nil }
        }
    }

    var body: some View {
        TimelineView(.periodic(from: .now, by: 15)) { timeline in
            card(trip.live(at: timeline.date, picked: picked, guided: planner.follower.here?.step), at: timeline.date)
        }
        .alert("End this trip?", isPresented: $askEnd) {
            Button("Keep", role: .cancel) {}
            Button("End trip", role: .destructive) {
                Task {
                    await today.endTrip(session.api, arrived: false)
                    planner.stopFollowing()
                }
            }
        } message: { Text("The live steps stop. The trip stays on your day.") }
        .alert("No new route", isPresented: Binding(get: { problem != nil }, set: { if !$0 { problem = nil } })) {
            Button("OK", role: .cancel) { problem = nil }
        } message: { Text(problem ?? "") }
    }

    private func card(_ live: TodayPage.Live, at date: Date) -> some View {
        // The line this step rides, or the next one it walks to.
        let ride = trip.steps.dropFirst(live.index).first { $0.kind == .ride || $0.kind == .getOff }
        let colours = LineLook(hex: ride?.color ?? "#00768B")
        return VStack(spacing: 10) {
            row(live, ride: ride, look: colours)
                .id(live.index)
                .transition(.asymmetric(insertion: .opacity.combined(with: .offset(x: swipedLeft ? 40 : -40)),
                                        removal: .opacity.combined(with: .offset(x: swipedLeft ? -40 : 40))))
            progress(Self.done(trip, at: date), colour: colours.base)
            // What just changed (a new route, a missed tram), said where the trip is.
            if let note = today.cardNote {
                CardSay(note: note).transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
        .offset(x: drag)
        .tripCard(dim: dim)
        .onTapGesture(perform: open)
        .gesture(swipe(live))
        .contentShape(.contextMenuPreview, .rect(cornerRadius: 18))
        .contextMenu { menu }
        .sensoryFeedback(.selection, trigger: picked)
        .animation(.smooth(duration: 0.4), value: live.index)
        .modifier(Spoken(text: spoken(live), open: open, end: end,
                         next: { look(by: 1, from: live.index, current: live.current) },
                         previous: { look(by: -1, from: live.index, current: live.current) }))
    }

    private func spoken(_ live: TodayPage.Live) -> String {
        let going = Self.going(trip.to)
        return going + ". Now: " + live.step.title + ", " + live.big + ", " + live.bigNote
    }

    /// VoiceOver: one element that opens the trip, with the swipe and End as actions.
    private struct Spoken: ViewModifier {
        let text: String
        let open: () -> Void
        let end: () -> Void
        let next: () -> Void
        let previous: () -> Void

        func body(content: Content) -> some View {
            content
                .accessibilityElement(children: .ignore)
                .accessibilityLabel(text)
                .accessibilityHint("Opens your trip. Hold for more.")
                .accessibilityAddTraits(.isButton)
                .accessibilityAction(.default, open)
                .accessibilityAction(named: "Next step", next)
                .accessibilityAction(named: "Previous step", previous)
                .accessibilityAction(named: "End trip", end)
        }
    }

    private func row(_ live: TodayPage.Live, ride: TodayPage.Step?, look: LineLook) -> some View {
        HStack(spacing: 12) {
            badge(ride, look: look)
            VStack(alignment: .leading, spacing: 2) {
                // Narrow columns (Today's day) shrink the words a little, then wrap; never cut "Leave at 23:…".
                Text(live.waiting ? live.label : live.step.title).font(.geist(16, .bold)).lineLimit(2).minimumScaleFactor(0.85)
                    .fixedSize(horizontal: false, vertical: true)
                Text(picked != nil ? live.label : trouble ?? destination(trip))
                    .font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(2)
                    .fixedSize(horizontal: false, vertical: true)
            }
            Spacer(minLength: 4)
            VStack(alignment: .trailing, spacing: 0) {
                Text(live.big).font(.posterGrotesque(20)).monospacedDigit().contentTransition(.numericText(countsDown: true))
                    .lineLimit(1).minimumScaleFactor(0.8)
                Text(live.bigNote).font(.geist(11.5)).foregroundStyle(Palette.textSoft).lineLimit(1)
            }
            .layoutPriority(-1)
            // No chevron: the trip slides up over the board, it isn't a page pushed in from the side.
        }
    }

    @ViewBuilder
    private func badge(_ ride: TodayPage.Step?, look: LineLook) -> some View {
        if let ride, let line = trip.badge(ride) {
            Text(line).font(.geist(17, .bold)).monospacedDigit().foregroundStyle(look.ink)
                .frame(minWidth: 40, minHeight: 40).padding(.horizontal, 2)
                .background(look.base, in: .rect(cornerRadius: 10))
        } else {
            Image(systemName: "figure.walk").font(.system(size: 17, weight: .semibold))
                .frame(width: 40, height: 40).background(Palette.quiet, in: .rect(cornerRadius: 10))
        }
    }

    /// How far along the trip is, by the clock, in the line's colour.
    private func progress(_ done: CGFloat, colour: Color) -> some View {
        GeometryReader { proxy in
            Capsule().fill(Palette.border)
                .overlay(alignment: .leading) { Capsule().fill(colour).frame(width: max(6, proxy.size.width * done)) }
        }
        .frame(height: 4)
        .accessibilityHidden(true)
    }

    private func swipe(_ live: TodayPage.Live) -> some Gesture {
        DragGesture(minimumDistance: 18)
            .onChanged { value in
                guard abs(value.translation.width) > abs(value.translation.height) * 1.4 else { return }
                drag = value.translation.width * 0.35
            }
            .onEnded { value in
                withAnimation(.spring(duration: 0.35, bounce: 0.3)) { drag = 0 }
                guard abs(value.translation.width) > 50, abs(value.translation.width) > abs(value.translation.height) * 1.4 else { return }
                swipedLeft = value.translation.width < 0
                look(by: swipedLeft ? 1 : -1, from: live.index, current: live.current)
            }
    }

    @ViewBuilder
    private var menu: some View {
        Button(action: open) { Label("Open steps", systemImage: "list.bullet") }
        Button(action: map) { Label("Show on the map", systemImage: "map") }
        if let replan { Button(action: replan) { Label("New route from here", systemImage: "arrow.triangle.turn.up.right.diamond") } }
        Toggle(isOn: $speak) { Label("Speak turns", systemImage: "speaker.wave.2") }
        Divider()
        if planner.follower.here?.arrived == true || trip.live(at: .now).current >= trip.steps.count - 2 {
            Button { Task { await today.endTrip(session.api, arrived: true); planner.stopFollowing() } } label: { Label("I’m there", systemImage: "checkmark") }
        }
        Button(role: .destructive, action: end) { Label("End trip", systemImage: "xmark") }
    }

    /// What went wrong, as the phone sees it: off the route, or the ride gone. Hold for a new route.
    private var trouble: String? {
        guard let here = planner.follower.here else { return nil }
        if let missed = here.missed { return "\(missed.name) has left · hold for a new route" }
        if here.offRoute, let since = planner.follower.offSince, Date.now.timeIntervalSince(since) > 15 { return "Off the route · hold for a new route" }
        return nil
    }

    /// "Going home · arrive 19:30".
    private func destination(_ trip: TodayPage.Trip) -> String {
        Self.going(trip.to) + (trip.arriveTime.map { " · arrive \($0)" } ?? "")
    }

    /// The share of the trip behind them by the clock, 0 before leaving.
    static func done(_ trip: TodayPage.Trip, at date: Date) -> CGFloat {
        guard let start = trip.steps.first?.at, let end = trip.steps.last?.at, end > start else { return 0 }
        return CGFloat(min(1, max(0, date.timeIntervalSince(start) / end.timeIntervalSince(start))))
    }

    static func going(_ to: String) -> String { TodayPage.Trip.going(to) }
}

extension View {
    /// The trip rows (Today, the Travel board): a light card set in from the edges, so it never reads as a departure band.
    func tripCard(dim: Bool) -> some View {
        foregroundStyle(Palette.text)
            .padding(.horizontal, 14).padding(.vertical, 12)
            .background(Palette.surface, in: .rect(cornerRadius: 18))
            .shadow(color: .black.opacity(0.12), radius: 10, y: 3)
            .padding(.horizontal, 12).padding(.vertical, 8)
            .opacity(dim ? 0.4 : 1)
            .contentShape(.rect)
    }
}

