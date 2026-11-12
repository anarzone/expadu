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
    /// On the map: the step card in view; its part of the route is drawn strong and fitted.
    @State private var mapStep: Int?
    /// On the map: the whole route fitted (on opening, and after Whole route), no step singled out.
    @State private var wholeRoute = true
    @State private var asking = false
    @State private var problem: String?
    @State private var working = false

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
        .onAppear {
            planner.follow(trip)
            if planner.tripOnMap { mapOn = true; planner.tripOnMap = false }
        }
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
                Text(TodayPage.Trip.going(trip.to)).font(.geist(17, .bold)).lineLimit(1)
                Text(meta).font(.geist(13)).opacity(0.9).lineLimit(1)
            }
            Spacer(minLength: 0)
            // A new route when the plan went wrong: a missed tram, or starting from somewhere else.
            Menu {
                if let ride = trip.legs.first(where: \.rides) {
                    Button { Task { await missed() } } label: { Label(ride.mode == "bus" ? "I missed the bus" : "I missed the tram", systemImage: "clock") }
                }
                Button { somewhereElse() } label: { Label("I’m somewhere else", systemImage: "location") }
            } label: {
                Text(working ? "Finding…" : "Re-plan").font(.geist(14, .semibold)).padding(.horizontal, 14).frame(height: 42)
            }
            .glassEffect(.clear.interactive(), in: .rect(cornerRadius: 14))
            // Ending the trip is always in sight; it asks first, so a stray tap can't end it.
            Button { asking = true } label: {
                Image(systemName: "xmark").font(.system(size: 16, weight: .bold)).frame(width: 42, height: 42).contentShape(.circle)
            }
            .buttonStyle(PressStyle())
            .glassEffect(.clear.interactive(), in: .circle)
            .accessibilityLabel("End trip")
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
                    // One list: each ride's stops fold into its step (Show stops), not a second list under it.
                    StepList(steps: steps, current: current, legs: trip.legs)
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

    /// The map view: the route and where you are on the whole screen, the step you're on in a band on
    /// top, and every step as a card along the bottom (as Citymapper and Apple Maps do): swipe or tap a
    /// card and the map shows that part of the route; Whole route goes back to all of it.
    private func fullMap(step: TodayPage.Step?, index: Int, count: Int, now: Date, look: LineLook) -> some View {
        let focusLeg = wholeRoute ? nil : mapStep.flatMap(leg(of:))
        let focused = focusLeg.map { WayDrawing.points(trip.legs[$0]) } ?? []
        let lines = WayDrawing.lines(trip.legs).enumerated().map { i, line in
            focusLeg == nil ? line : CityMap.Line(id: line.id, points: line.points, color: line.color, width: line.width, dashed: line.dashed, faint: i != focusLeg)
        }
        return ZStack(alignment: .top) {
            CityMap(lines: lines, pins: WayDrawing.pins(trip.legs, to: trip.to), fit: focused.count > 1 ? focused : WayDrawing.all(trip.legs),
                    padding: UIEdgeInsets(top: 250, left: 40, bottom: 250, right: 40), follow: followMe, showsUser: true,
                    fitID: fitTick * 100 + (focusLeg ?? -1) + 1, creditInset: 196)
                .ignoresSafeArea()
            band(step: step, index: index, count: count, now: now, look: look)
        }
        .overlay(alignment: .bottom) { mapControls(current: index) }
        .sensoryFeedback(.selection, trigger: followMe)
        .sensoryFeedback(.selection, trigger: mapStep)
        .animation(.smooth(duration: 0.35), value: wholeRoute)
    }

    /// The leg a step's part of the route is: its own, or (getting off) the ride before it.
    private func leg(of index: Int) -> Int? {
        let steps = trip.steps
        guard steps.indices.contains(index) else { return nil }
        let leg = steps[index].leg ?? (index > 0 ? steps[index - 1].leg : nil)
        return leg.flatMap { trip.legs.indices.contains($0) ? $0 : nil }
    }

    /// The step you're on, in the line's colour, over the top of the map.
    private func band(step: TodayPage.Step?, index: Int, count: Int, now: Date, look: LineLook) -> some View {
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

    /// Steps (back to the list), Whole route when one part is shown, follow me; the step cards under them.
    private func mapControls(current: Int) -> some View {
        VStack(spacing: 10) {
            HStack(spacing: 8) {
                Button { mapOn = false } label: {
                    Label("Steps", systemImage: "list.bullet").font(.geist(15, .semibold)).padding(.horizontal, 16).frame(height: 46)
                }
                .buttonStyle(PressStyle())
                .glassEffect(.regular.interactive(), in: .rect(cornerRadius: 15))
                Spacer(minLength: 0)
                if !wholeRoute && !followMe {
                    Button { withAnimation(.smooth) { wholeRoute = true; fitTick += 1 } } label: {
                        Label("Whole route", systemImage: "arrow.up.left.and.arrow.down.right").font(.geist(15, .semibold))
                            .padding(.horizontal, 14).frame(height: 46)
                    }
                    .buttonStyle(PressStyle())
                    .glassEffect(.regular.interactive(), in: .rect(cornerRadius: 15))
                    .transition(.opacity.combined(with: .scale(scale: 0.9)))
                }
                Button {
                    withAnimation(.smooth) {
                        followMe.toggle()
                        if !followMe { wholeRoute = true; fitTick += 1 }
                    }
                } label: {
                    Image(systemName: followMe ? "location.fill" : "location").font(.system(size: 18, weight: .semibold)).frame(width: 46, height: 46)
                }
                .buttonStyle(PressStyle())
                .glassEffect(.regular.interactive(), in: .rect(cornerRadius: 15))
                .accessibilityLabel(followMe ? "Stop following me" : "Follow me")
            }
            .padding(.horizontal, 16)
            stepCards(current: current)
        }
        .foregroundStyle(Palette.text)
        .padding(.bottom, 6)
    }

    private func stepCards(current: Int) -> some View {
        ScrollView(.horizontal) {
            LazyHStack(spacing: 10) {
                ForEach(Array(trip.steps.enumerated()), id: \.offset) { i, step in
                    stepCard(step, index: i, current: current)
                        .containerRelativeFrame(.horizontal) { width, _ in width - 64 }
                        .id(i)
                        .onTapGesture {
                            withAnimation(.smooth(duration: 0.35)) { followMe = false; mapStep = i; wholeRoute = false }
                        }
                }
            }
            .scrollTargetLayout()
        }
        .scrollTargetBehavior(.viewAligned)
        .scrollPosition(id: $mapStep)
        .contentMargins(.horizontal, 16, for: .scrollContent)
        .scrollIndicators(.hidden)
        .scrollClipDisabled()
        // A horizontal scroll takes all the height it's offered: the row is as tall as a card.
        .frame(height: 124)
        // A swipe singles out the card it lands on; opening the map shows the whole route at the step you're on.
        .onScrollPhaseChange { _, phase in
            if phase == .interacting { withAnimation(.smooth) { followMe = false; wholeRoute = false } }
        }
        .onAppear { mapStep = current }
    }

    private func stepCard(_ step: TodayPage.Step, index: Int, current: Int) -> some View {
        let on = !wholeRoute && mapStep == index
        return VStack(alignment: .leading, spacing: 5) {
            HStack(spacing: 6) {
                Text(step.time).font(.geist(13, .bold)).monospacedDigit()
                if index == current {
                    Text("Now").font(.geist(11.5, .bold)).foregroundStyle(Palette.onAction)
                        .padding(.horizontal, 6).frame(height: 18).background(Palette.action, in: .rect(cornerRadius: 5))
                }
                Spacer(minLength: 4)
                Text("\(index + 1) of \(trip.steps.count)").font(.geist(12)).foregroundStyle(Palette.textSoft)
            }
            HStack(spacing: 8) {
                if step.kind == .ride, let line = step.name.split(separator: " ").last {
                    let colours = LineLook(hex: step.color ?? "#00768B")
                    Text(String(line)).font(.geist(13, .bold)).foregroundStyle(colours.ink)
                        .padding(.horizontal, 6).frame(minWidth: 28).frame(height: 22)
                        .background(colours.base, in: .rect(cornerRadius: 6))
                }
                Text(step.title).font(.geist(16, .bold)).lineLimit(1).minimumScaleFactor(0.85)
            }
            if !step.sub.isEmpty { Text(step.sub).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(2) }
        }
        .padding(14)
        .frame(maxWidth: .infinity, minHeight: 112, maxHeight: 112, alignment: .topLeading)
        .background(Palette.surface, in: .rect(cornerRadius: 18))
        .overlay { RoundedRectangle(cornerRadius: 18).strokeBorder(on ? Palette.navTeal : .clear, lineWidth: 2) }
        .shadow(color: .black.opacity(0.15), radius: 10, y: 3)
        .foregroundStyle(Palette.text)
        .opacity(index < current ? 0.6 : 1)
        .accessibilityElement(children: .combine)
        .accessibilityHint("Shows this part on the map")
        .accessibilityAddTraits(on ? .isSelected : [])
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

extension TodayPage.Trip {
    /// Where a trip goes, said as a person would: "Going home", "Going to work", "Going to Neumarkt".
    static func going(_ to: String) -> String {
        switch to.lowercased() {
        case "home": "Going home"
        case "work": "Going to work"
        default: "Going to \(to)"
        }
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
