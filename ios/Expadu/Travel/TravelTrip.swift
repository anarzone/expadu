import API
import Core
import CoreLocation
import DesignSystem
import Maps
import SwiftUI

/// On your way (the canvas's trip screen): the whole screen in the line's colour, the step you're on
/// in big type with the minutes to it on flaps, what to look for, and the steps as bars along the top
/// (tap one to look ahead). Under it, the map and your way. "Not right?" for a missed tram, another
/// starting point, or stopping. Minimised, the trip goes on in the Dynamic Island.
struct TravelTrip: View {
    let trip: TodayPage.Trip
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(TripPlanner.self) private var planner
    @Environment(\.dismiss) private var dismiss
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var looking: Int?
    /// The map view (the whole screen) instead of the steps.
    @State private var mapOn = false
    /// On the map: the camera follows the phone; off, it shows the whole way.
    @State private var followMe = false
    @State private var fitTick = 0
    @State private var asking = false
    @State private var problem: String?
    @State private var working = false
    @State private var openStops: Set<Int> = []

    var body: some View {
        TimelineView(.periodic(from: .now, by: 15)) { timeline in
            let now = timeline.date
            let steps = trip.steps
            let current = steps.lastIndex { $0.at <= now } ?? 0
            let shown = looking ?? current
            let step = steps.indices.contains(shown) ? steps[shown] : nil
            let look = LineLook(hex: trip.legs.first { $0.rides }?.color ?? "#00768B")
            ZStack(alignment: .top) {
                if mapOn {
                    // The map view: the whole screen is the map, the step you're on in a band on top.
                    fullMap(step: step, index: shown, count: steps.count, now: now, look: look)
                        .transition(.opacity)
                } else {
                    look.fill.ignoresSafeArea()
                    if let line = trip.legs.first(where: \.rides)?.line {
                        Text(line).font(.posterGrotesque(360)).tracking(-16)
                            .foregroundStyle(look.ink.opacity(0.1)).lineLimit(1).fixedSize()
                            .offset(x: -20, y: 40).frame(maxWidth: .infinity, alignment: .leading)
                            .allowsHitTesting(false)
                    }
                    VStack(spacing: 0) {
                        VStack(alignment: .leading, spacing: 14) {
                            head(look)
                            bars(steps.count, current: current, shown: shown, ink: look.ink)
                            if let step { moment(step, index: shown, count: steps.count, now: now, look: look) }
                        }
                        .padding(.horizontal, 16).padding(.top, 8).padding(.bottom, 18)
                        .foregroundStyle(look.ink)
                        way(steps: steps, current: current)
                    }
                    .transition(.opacity)
                }
            }
            .animation(reduceMotion ? nil : .smooth(duration: 0.4), value: shown)
            .animation(reduceMotion ? nil : .smooth(duration: 0.35), value: mapOn)
        }
        .task(id: looking) {
            // A look ahead comes back to the step they're on after a while.
            guard looking != nil else { return }
            try? await Task.sleep(for: .seconds(6))
            withAnimation(.smooth) { looking = nil }
        }
        .alert("End this trip?", isPresented: $asking) {
            Button("Keep", role: .cancel) {}
            Button("End trip", role: .destructive) {
                Task {
                    await today.endTrip(session.api, arrived: false)
                    planner.stopFollowing()
                    dismiss()
                }
            }
        } message: { Text("The live steps stop. The trip stays on your day.") }
        .onAppear { planner.follow(trip) }
    }

    private func head(_ look: LineLook) -> some View {
        HStack(spacing: 10) {
            Button { dismiss() } label: {
                Image(systemName: "chevron.down").font(.system(size: 17, weight: .semibold)).frame(width: 42, height: 42).contentShape(.circle)
            }
            .buttonStyle(PressStyle())
            .glassEffect(.clear.interactive(), in: .circle)
            .accessibilityLabel("Minimise")
            VStack(alignment: .leading, spacing: 1) {
                Text("To \(trip.to)").font(.geist(17, .bold)).lineLimit(1)
                Text(meta).font(.geist(13)).opacity(0.9).lineLimit(1)
            }
            Spacer(minLength: 0)
            Menu {
                if let ride = trip.legs.first(where: \.rides) {
                    Button { Task { await missed() } } label: { Label(ride.mode == "bus" ? "I missed the bus" : "I missed the tram", systemImage: "clock") }
                }
                Button { somewhereElse() } label: { Label("I’m somewhere else", systemImage: "location") }
                Button(role: .destructive) { asking = true } label: { Label("End trip", systemImage: "xmark") }
            } label: {
                Text(working ? "Finding…" : "Trip options").font(.geist(14, .semibold)).padding(.horizontal, 14).frame(height: 42)
            }
            .glassEffect(.clear.interactive(), in: .rect(cornerRadius: 14))
        }
    }

    private var meta: String {
        var parts: [String] = []
        if let first = trip.legs.first?.departTime { parts.append(first + (trip.arriveTime.map { " → \($0)" } ?? "")) }
        if let start = trip.startTime { parts.append("starts \(start)") }
        return parts.joined(separator: " · ")
    }

    private func bars(_ count: Int, current: Int, shown: Int, ink: Color) -> some View {
        HStack(spacing: 4) {
            ForEach(0..<count, id: \.self) { i in
                Button { withAnimation(.smooth) { looking = i == current ? nil : i } } label: {
                    Capsule().fill(ink.opacity(i < current ? 0.9 : i == current ? 1 : 0.3))
                        .frame(height: i == shown ? 6 : 4)
                        .frame(maxWidth: .infinity, minHeight: 30)
                        .contentShape(.rect)
                }
                .buttonStyle(.plain)
                .accessibilityLabel("Step \(i + 1) of \(count)")
            }
        }
        .sensoryFeedback(.selection, trigger: shown)
    }

    private func moment(_ step: TodayPage.Step, index: Int, count: Int, now: Date, look: LineLook) -> some View {
        // Before a step starts, the minutes until it does (leave in…); once on it, until the next one.
        let minutes = minutesLeft(step, index: index, now: now)
        return VStack(alignment: .leading, spacing: 10) {
            Text(index == count - 1 ? "Arrived" : "\(index + 1) of \(count)").font(.geist(13, .semibold)).opacity(0.9)
            Text(step.title).font(.posterGrotesque(36)).tracking(-0.4).fixedSize(horizontal: false, vertical: true)
                .contentTransition(.opacity)
            if index < count - 1 {
                HStack(alignment: .lastTextBaseline, spacing: 8) {
                    Text(countdownLabel(step, index: index, now: now)).font(.geist(17, .semibold))
                    HStack(spacing: 2) {
                        ForEach(Array(String(format: "%02d", min(minutes, 99)).enumerated()), id: \.offset) { _, digit in
                            FlapDigit(character: digit, width: 40, height: 58, size: 44)
                        }
                    }
                    Text("min").font(.geist(17, .semibold))
                }
            }
            if !step.sub.isEmpty {
                HStack(spacing: 8) {
                    if step.kind == .ride, let line = step.name.split(separator: " ").last {
                        Label(String(line), systemImage: "tram.fill").font(.geist(13, .bold))
                            .padding(.horizontal, 7).frame(height: 24).background(.white, in: .rect(cornerRadius: 6)).foregroundStyle(Palette.text)
                    }
                    Text(step.sub).font(.geist(15, .medium))
                }
                .padding(.horizontal, 12).padding(.vertical, 10)
                .glassEffect(.clear, in: .rect(cornerRadius: 14))
            }
            if let problem { Text(problem).font(.geist(14, .medium)).opacity(0.9) }
        }
        .accessibilityElement(children: .combine)
    }

    /// What the minutes count down to: leaving, the next tram, getting off, or being there.
    private func countdownLabel(_ step: TodayPage.Step, index: Int, now: Date) -> String {
        if step.at > now { return index == 0 ? "Leave in" : "Starts in" }
        guard trip.steps.indices.contains(index + 1) else { return "" }
        let next = trip.steps[index + 1]
        switch next.kind {
        case .ride: return "\(next.name) in"
        case .getOff: return "Get off in"
        case .there: return step.kind == .ride ? "Get off in" : "There in"
        case .walk: return step.kind == .ride ? "Get off in" : "Next in"
        }
    }

    /// The whole way, on the page under the step you're on: every step (the one you're on marked, the ones
    /// done faded), each ride's stops, and the map in the same card.
    private func way(steps: [TodayPage.Step], current: Int) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Text("All steps").font(.geist(16, .bold))
                Text("· \(steps.count)").font(.geist(15)).foregroundStyle(Palette.textSoft)
                Spacer(minLength: 0)
                Button { mapOn = true } label: {
                    Label("Map", systemImage: "map").font(.geist(14, .semibold))
                        .padding(.horizontal, 12).frame(height: 38)
                }
                .buttonStyle(PressStyle())
                .background(Palette.quiet, in: .rect(cornerRadius: 12))
                .accessibilityHint("Shows the way on a full-screen map")
            }
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    StepList(steps: steps, current: current)
                    // Every stop on each ride, with when the tram is there.
                    ForEach(Array(trip.legs.enumerated()), id: \.offset) { i, leg in
                        if leg.rides, !leg.stopsOnTheWay.isEmpty {
                            RideStops(leg: leg, open: openStops.contains(i)) {
                                withAnimation(.smooth(duration: 0.3)) {
                                    if openStops.contains(i) { openStops.remove(i) } else { openStops.insert(i) }
                                }
                            }
                            .padding(.top, 12)
                        }
                    }
                    Text(trip.legs.contains(where: \.rides)
                         ? "We’ll tell you one stop before. Your phone buzzes here, even with the screen off (with notifications on). The trip ends by itself."
                         : "It moves on by itself as you go. The trip ends by itself.")
                        .font(.geist(12.5)).foregroundStyle(Palette.textSoft).padding(.top, 12)
                }
                .padding(.bottom, 20)
            }
            .scrollIndicators(.hidden)
        }
        .foregroundStyle(Palette.text)
        .padding(16)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
        .background(Palette.surface, in: .rect(cornerRadius: 26))
        .padding(.horizontal, 10)
        .padding(.bottom, 6)
    }

    /// The map view: the way and where you are on the whole screen; the step you're on stays in a band
    /// on top in the line's colour. Steps goes back; the location button follows you or shows the whole way.
    private func fullMap(step: TodayPage.Step?, index: Int, count: Int, now: Date, look: LineLook) -> some View {
        ZStack(alignment: .top) {
            CityMap(lines: WayDrawing.lines(trip.legs), pins: WayDrawing.pins(trip.legs, to: trip.to), fit: WayDrawing.all(trip.legs),
                    padding: UIEdgeInsets(top: 250, left: 40, bottom: 130, right: 40), follow: followMe, showsUser: true, fitID: fitTick)
                .ignoresSafeArea()
            VStack(alignment: .leading, spacing: 12) {
                head(look)
                if let step {
                    HStack(alignment: .center, spacing: 12) {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(step.title).font(.posterGrotesque(24)).lineLimit(2).minimumScaleFactor(0.8)
                            if !step.sub.isEmpty { Text(step.sub).font(.geist(13.5, .medium)).opacity(0.9).lineLimit(1) }
                        }
                        Spacer(minLength: 6)
                        if index < count - 1 {
                            VStack(alignment: .trailing, spacing: 0) {
                                Text(countdownLabel(step, index: index, now: now)).font(.geist(12.5, .semibold)).opacity(0.9)
                                HStack(alignment: .lastTextBaseline, spacing: 3) {
                                    Text("\(minutesLeft(step, index: index, now: now))").font(.posterGrotesque(32)).monospacedDigit()
                                        .contentTransition(.numericText())
                                    Text("min").font(.geist(14, .semibold))
                                }
                            }
                        }
                    }
                    .contentTransition(.opacity)
                }
            }
            .padding(.horizontal, 16).padding(.top, 8).padding(.bottom, 16)
            .foregroundStyle(look.ink)
            .background {
                UnevenRoundedRectangle(bottomLeadingRadius: 26, bottomTrailingRadius: 26)
                    .fill(look.fill)
                    .shadow(color: .black.opacity(0.2), radius: 14, y: 4)
                    .ignoresSafeArea(edges: .top)
            }
        }
        .overlay(alignment: .bottom) {
            // Steps in the middle, clear of the map's credit (i) at the left.
            ZStack(alignment: .trailing) {
                Button { mapOn = false } label: {
                    Label("Steps", systemImage: "list.bullet").font(.geist(15, .semibold)).padding(.horizontal, 18).frame(height: 50)
                }
                .buttonStyle(PressStyle())
                .glassEffect(.regular.interactive(), in: .rect(cornerRadius: 16))
                .frame(maxWidth: .infinity)
                Button {
                    withAnimation(.smooth) {
                        followMe.toggle()
                        if !followMe { fitTick += 1 }
                    }
                } label: {
                    Image(systemName: followMe ? "location.fill" : "location").font(.system(size: 18, weight: .semibold)).frame(width: 50, height: 50)
                }
                .buttonStyle(PressStyle())
                .glassEffect(.regular.interactive(), in: .rect(cornerRadius: 16))
                .accessibilityLabel(followMe ? "Show the whole way" : "Follow me")
            }
            .foregroundStyle(Palette.text)
            .padding(.horizontal, 16).padding(.bottom, 8)
        }
        .sensoryFeedback(.selection, trigger: followMe)
    }

    /// The minutes the countdown shows: until the step starts, or until the next one once on it.
    private func minutesLeft(_ step: TodayPage.Step, index: Int, now: Date) -> Int {
        let next = trip.steps.indices.contains(index + 1) ? trip.steps[index + 1].at : nil
        let until = step.at > now ? step.at : (next ?? step.at)
        return max(0, Int((until.timeIntervalSince(now) / 60).rounded(.up)))
    }

    /// The next way from where they are now: the trip goes on with it.
    private func missed() async {
        working = true
        defer { working = false }
        guard let place = today.place else { problem = "Your phone hasn’t said where you are."; return }
        problem = await today.newWay(session.api, trip: trip, from: TodayPage.Point(latitude: place.latitude, longitude: place.longitude))
    }

    /// Plan again from another starting point.
    private func somewhereElse() {
        dismiss()
        planner.shown = true
        planner.field = .from
        planner.query = ""
    }
}

extension TripPlanner {
    /// The trip's Live Activity keeps moving while it's followed, on the screen or not.
    func follow(_ trip: TodayPage.Trip) {
        follower.trip = trip
        if !following { follower.start(); following = true }
    }

    func stopFollowing() {
        if following { follower.stop(); following = false }
    }
}

/// A ride's stops, folded to one row ("Tram 12 · 15 stops"); opened, each stop with its time on the line,
/// down to the one to get off at.
private struct RideStops: View {
    let leg: TodayPage.Trip.Leg
    let open: Bool
    let toggle: () -> Void

    /// The stops ridden, the one to get off at included, as the step counts them.
    private var ridden: Int { leg.stops ?? leg.stopsOnTheWay.count + 1 }

    private func row(_ name: String, time: String, colour: Color, off: Bool) -> some View {
        HStack(spacing: 12) {
            Circle().fill(off ? colour : Palette.surface).overlay { Circle().strokeBorder(colour, lineWidth: 2.5) }.frame(width: 11, height: 11)
                .frame(width: 17)
                .background { Rectangle().fill(colour).frame(width: 3).padding(.top, -10).padding(.bottom, off ? 15 : -10) }
            Text(name).font(.geist(13.5, off ? .semibold : .regular))
            if off { Text("Get off").font(.geist(12.5, .semibold)).foregroundStyle(Palette.textSoft) }
            Spacer(minLength: 4)
            Text(time).font(.geist(13)).monospacedDigit().foregroundStyle(Palette.textSoft)
        }
        .frame(minHeight: 30)
    }

    var body: some View {
        let colour = LineLook(hex: leg.color ?? "#1A4CD4").base
        VStack(alignment: .leading, spacing: 0) {
            Button(action: toggle) {
                HStack(spacing: 8) {
                    Text(leg.line ?? "").font(.geist(12.5, .bold)).foregroundStyle(LineLook(hex: leg.color ?? "#1A4CD4").ink)
                        .padding(.horizontal, 6).frame(minWidth: 26).frame(height: 20)
                        .background(colour, in: .rect(cornerRadius: 5))
                    Text("\(leg.from) → \(leg.to)").font(.geist(14, .semibold)).lineLimit(1)
                    Spacer(minLength: 4)
                    Text("\(ridden) stop\(ridden == 1 ? "" : "s")").font(.geist(13)).foregroundStyle(Palette.textSoft)
                    Image(systemName: "chevron.down").font(.system(size: 12, weight: .semibold)).foregroundStyle(Palette.textSoft)
                        .rotationEffect(.degrees(open ? 180 : 0))
                }
                .frame(minHeight: 40).contentShape(.rect)
            }
            .buttonStyle(PressStyle())
            .accessibilityLabel("Stops on line \(leg.line ?? ""), \(ridden)")
            if open {
                VStack(alignment: .leading, spacing: 0) {
                    ForEach(Array(leg.stopsOnTheWay.enumerated()), id: \.offset) { _, stop in
                        row(stop.name, time: stop.time, colour: colour, off: false)
                    }
                    row(leg.to, time: leg.arriveTime, colour: colour, off: true)
                }
                .clipped()
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
    }
}
