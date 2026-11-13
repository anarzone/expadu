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
    /// On the map: the steps in the lower left, over the map (the map moves up to keep you in sight).
    @State private var stepsOpen = false
    @State private var asking = false
    @AppStorage("speakTurns") private var speak = false
    @State private var problem: String?
    @State private var working = false
    /// Where the line's colour ends on the map: the bottom of the band, measured.
    @State private var bandBottom: CGFloat = 230
    /// Where the Map button sits on the screen, for the map to grow out of ("Grow").
    @State private var mapAnchor = UnitPoint(x: 0.85, y: 0.5)
    @State private var screen = CGSize(width: 402, height: 874)

    var body: some View {
        TimelineView(.periodic(from: .now, by: 15)) { timeline in
            let now = timeline.date
            let steps = trip.steps
            // The step they're on: where the phone is when walking, the clock on a ride (one rule, the card's too).
            let current = trip.live(at: now, guided: planner.follower.here?.step).current
            let shown = looking ?? current
            let step = steps.indices.contains(shown) ? steps[shown] : nil
            let look = LineLook(hex: trip.legs.first { $0.rides }?.color ?? "#00768B")
            ZStack(alignment: .top) {
                if mapOn {
                    // The map view: the whole screen is the map, the step you're on in a band on top.
                    mapLayer(current: current)
                        .transition(mapTransition)
                }
                // The line's colour: the whole screen with the steps; on the map it gathers into the band.
                UnevenRoundedRectangle(bottomLeadingRadius: mapOn ? 26 : 0, bottomTrailingRadius: mapOn ? 26 : 0)
                    .fill(look.fill)
                    .shadow(color: .black.opacity(mapOn ? 0.2 : 0), radius: 14, y: 4)
                    .frame(height: mapOn ? bandBottom : nil)
                    .frame(maxHeight: .infinity, alignment: .top)
                    .ignoresSafeArea()
                    .allowsHitTesting(false)
                if !mapOn, let line = trip.legs.first(where: \.rides)?.line {
                    Text(line).font(.posterGrotesque(360)).tracking(-16)
                        .foregroundStyle(look.ink.opacity(0.1)).lineLimit(1).fixedSize()
                        .offset(x: -20, y: 40).frame(maxWidth: .infinity, alignment: .leading)
                        .allowsHitTesting(false)
                        .transition(.opacity)
                }
                VStack(spacing: 0) {
                    VStack(alignment: .leading, spacing: mapOn ? 12 : 14) {
                        head(look)
                        if !mapOn {
                            bars(steps.count, current: current, shown: shown, ink: look.ink)
                                .transition(.opacity)
                        }
                        if let step {
                            // One step, two sizes: the old one goes quickly, the new one comes just after,
                            // so the two never sit on top of each other.
                            if mapOn {
                                bandMoment(step, index: shown, count: steps.count, now: now)
                                    .transition(stepSwap)
                            } else {
                                moment(step, index: shown, count: steps.count, now: now, look: look)
                                    .transition(stepSwap)
                            }
                        }
                    }
                    .padding(.horizontal, 16).padding(.top, 8).padding(.bottom, mapOn ? 16 : 18)
                    .foregroundStyle(look.ink)
                    .onGeometryChange(for: CGFloat.self) { $0.frame(in: .global).maxY } action: { bottom in
                        if mapOn { withAnimation(switchAnimation) { bandBottom = bottom } }
                    }
                    if !mapOn {
                        way(steps: steps, current: current)
                            .transition(wayTransition)
                    }
                    Spacer(minLength: 0)
                }
            }
            .onGeometryChange(for: CGSize.self) { $0.size } action: { screen = $0 }
            .animation(reduceMotion ? nil : .smooth(duration: 0.4), value: shown)
            .onChange(of: MotionStore.shared.replays[.tripMapSwitch]) { switchMap(!mapOn) }
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
        // A new route (re-planned here or on Today) is the one followed from now on.
        .onChange(of: trip) { planner.follow(trip) }
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
                Divider()
                Toggle(isOn: $speak) { Label("Speak turns", systemImage: "speaker.wave.2") }
                if planner.follower.here?.arrived == true || trip.live(at: .now).current >= trip.steps.count - 2 {
                    Button {
                        Task { await today.endTrip(session.api, arrived: true); planner.stopFollowing(); dismiss() }
                    } label: { Label("I’m there", systemImage: "checkmark") }
                }
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
                    if step.kind == .ride, let line = trip.badge(step) {
                        LineBadge(line: line, color: step.color, height: 24, mode: trip.legs[step.leg ?? 0].mode, icon: true, onColour: true)
                    }
                    Text(step.sub).font(.geist(15, .medium))
                }
                .padding(.horizontal, 12).padding(.vertical, 10)
                .glassEffect(.clear, in: .rect(cornerRadius: 14))
            }
            if let problem { Text(problem).font(.geist(14, .medium)).opacity(0.9) }
            // Walking: the next turn, from where the phone is.
            if index == current(now), step.kind == .walk, let here = planner.follower.here, let turn = here.turn, !here.offRoute {
                TurnRow(turn: turn, metres: here.metresToTurn)
                    .padding(10).background(Palette.surface, in: .rect(cornerRadius: 16)).foregroundStyle(Palette.text)
                    .transition(.opacity)
            }
            // Gone wrong: off the route for a while, or the ride left without them. A new route in one tap.
            if let trouble {
                TripTrouble(text: trouble, busy: working) { Task { await missed() } }
                    .padding(12).background(Palette.surface, in: .rect(cornerRadius: 16)).foregroundStyle(Palette.text)
                    .transition(.opacity)
            }
        }
        .accessibilityElement(children: .combine)
    }

    /// The step they're on now (the phone when walking, else the clock).
    private func current(_ now: Date) -> Int { trip.live(at: now, guided: planner.follower.here?.step).current }

    /// Off the route for 15 s, or the ride gone, as the follower sees it.
    private var trouble: String? {
        guard let here = planner.follower.here else { return nil }
        if let missed = here.missed { return "\(missed.name) has left. A new route from here?" }
        if here.offRoute, let since = planner.follower.offSince, Date.now.timeIntervalSince(since) > 15 {
            return "You’re off the route, \(TurnRow.distance(Int(here.offBy))) from it."
        }
        return nil
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
                Button { switchMap(true) } label: {
                    Label("Map", systemImage: "map").font(.geist(14, .semibold))
                        .padding(.horizontal, 12).frame(height: 38)
                }
                .onGeometryChange(for: CGRect.self) { $0.frame(in: .global) } action: { frame in
                    mapAnchor = UnitPoint(x: frame.midX / max(1, screen.width), y: frame.midY / max(1, screen.height))
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

    /// The map view's map: the route and where you are on the whole screen (the band lies over its top).
    /// Steps lays the steps over the lower left of the map, the one you're on at the bottom and the next
    /// ones above it fading out (as live comments do), and moves the map up so you stay in sight.
    private func mapLayer(current: Int) -> some View {
        CityMap(lines: WayDrawing.lines(trip.legs), pins: WayDrawing.pins(trip.legs, to: trip.to), fit: WayDrawing.all(trip.legs),
                padding: UIEdgeInsets(top: stepsOpen ? 225 : 250, left: 40, bottom: stepsOpen ? 16 : 130, right: 40), follow: followMe, showsUser: true,
                fitID: fitTick * 10 + (stepsOpen ? 1 : 0), creditInset: stepsOpen ? 8 : 70,
                // The list's top fades out, so the map only keeps clear of its solid lower part.
                viewInset: UIEdgeInsets(top: 0, left: 0, bottom: stepsOpen ? Self.feedHeight * 0.7 + 64 : 0, right: 0))
            .ignoresSafeArea()
            .overlay(alignment: .bottomLeading) { mapControls(current: current) }
            .sensoryFeedback(.selection, trigger: followMe)
            .sensoryFeedback(.selection, trigger: stepsOpen)
    }

    private static let feedHeight: CGFloat = 300

    /// The step you're on, compact, in the band over the map; its title is the same one as the big
    /// step's, gliding between the two.
    private func bandMoment(_ step: TodayPage.Step, index: Int, count: Int, now: Date) -> some View {
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
                .transition(.opacity)
            }
        }
        .contentTransition(.opacity)
    }

    // MARK: Switching between the steps and the map (Motion Lab token trip.mapSwitch)

    private var switchVariant: String { MotionStore.shared.variant(of: .tripMapSwitch).id }

    private var switchAnimation: Animation? { MotionStore.shared.animation(.tripMapSwitch, reduceMotion: reduceMotion) }

    private func switchMap(_ on: Bool) {
        guard on != mapOn else { return }
        withAnimation(switchAnimation) { mapOn = on }
    }

    /// How the map comes and goes: zooming in as the colour gathers up (Morph), growing out of the
    /// Map button (Grow), rising from the bottom (Slide up), or a plain fade.
    private var mapTransition: AnyTransition {
        guard !reduceMotion else { return .opacity }
        return switch switchVariant {
        case "Grow": .scale(scale: 0.06, anchor: mapAnchor).combined(with: .opacity)
        case "Slide up": .move(edge: .bottom)
        case "Fade": .opacity
        default: .scale(scale: 1.08).combined(with: .opacity)
        }
    }

    private var stepSwap: AnyTransition {
        .asymmetric(insertion: .opacity.animation(reduceMotion ? nil : .easeOut(duration: 0.22).delay(0.18)),
                    removal: .opacity.animation(reduceMotion ? nil : .easeIn(duration: 0.12)))
    }

    /// The steps card: sinking away under the map (Morph), or fading.
    private var wayTransition: AnyTransition {
        guard !reduceMotion else { return .opacity }
        return switch switchVariant {
        case "Morph": .move(edge: .bottom).combined(with: .opacity)
        default: .opacity
        }
    }

    /// Steps (the list over the map) at the lower left, with the list above it when open; the step
    /// list view and follow-me at the lower right.
    private func mapControls(current: Int) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            if stepsOpen {
                StepsFeed(trip: trip, current: current)
                    .frame(height: Self.feedHeight)
                    .transition(.opacity.combined(with: .move(edge: .bottom)))
            }
            HStack(spacing: 8) {
                Button { withAnimation(.smooth(duration: 0.35)) { stepsOpen.toggle() } } label: {
                    Label(stepsOpen ? "Hide steps" : "Steps", systemImage: stepsOpen ? "chevron.down" : "list.bullet")
                        .font(.geist(15, .semibold)).padding(.horizontal, 16).frame(height: 46)
                        .contentTransition(.opacity)
                }
                .buttonStyle(PressStyle())
                .glassEffect(.regular.interactive(), in: .rect(cornerRadius: 15))
                Spacer(minLength: 0)
                Button { switchMap(false) } label: {
                    Image(systemName: "list.bullet.rectangle.portrait").font(.system(size: 18, weight: .semibold)).frame(width: 46, height: 46)
                }
                .buttonStyle(PressStyle())
                .glassEffect(.regular.interactive(), in: .rect(cornerRadius: 15))
                .accessibilityLabel("Back to the step list")
                Button {
                    withAnimation(.smooth) {
                        followMe.toggle()
                        if !followMe { fitTick += 1 }
                    }
                } label: {
                    Image(systemName: followMe ? "location.fill" : "location").font(.system(size: 18, weight: .semibold)).frame(width: 46, height: 46)
                }
                .buttonStyle(PressStyle())
                .glassEffect(.regular.interactive(), in: .rect(cornerRadius: 15))
                .accessibilityLabel(followMe ? "Show the whole route" : "Follow me")
            }
        }
        .foregroundStyle(Palette.text)
        .padding(.horizontal, 16).padding(.bottom, 8)
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
        let point = planner.follower.point ?? today.place.map { TodayPage.Point(latitude: $0.latitude, longitude: $0.longitude) }
        guard let point else { problem = "Your phone hasn’t said where you are."; return }
        problem = await today.newWay(session.api, trip: trip, from: point)
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
    /// The line badge for a step ("12", "RE 1"), from its ride's own line, never parsed from the step's
    /// words ("RE1 (RRX)" read "(RRX)"). Getting off keeps the ride's line; walks have none.
    func badge(_ step: TodayPage.Step) -> String? {
        guard let at = steps.firstIndex(of: step) else { return nil }
        let ride = step.kind == .ride ? step : step.kind == .getOff ? steps[..<at].last { $0.kind == .ride } : nil
        guard let leg = ride?.leg, legs.indices.contains(leg), let line = legs[leg].line, !line.isEmpty else { return nil }
        return Board.badge(line)
    }

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

/// The steps over the map's lower left, as live comments sit over a video: the one you're on at the
/// bottom, the next ones above it fading out towards the top, the ones done below it (scroll down).
/// It scrolls, and moves on by itself as the trip does.
private struct StepsFeed: View {
    let trip: TodayPage.Trip
    let current: Int
    private var steps: [TodayPage.Step] { trip.steps }

    var body: some View {
        ScrollViewReader { reader in
            ScrollView {
                VStack(alignment: .leading, spacing: 6) {
                    ForEach(Array(steps.enumerated().reversed()), id: \.offset) { index, step in
                        row(step, index: index).id(index)
                    }
                }
                .padding(.top, 120).padding(.bottom, 4)
            }
            .scrollIndicators(.hidden)
            .defaultScrollAnchor(.bottom)
            .mask {
                LinearGradient(stops: [.init(color: .clear, location: 0), .init(color: .black.opacity(0.35), location: 0.25),
                                       .init(color: .black, location: 0.55), .init(color: .black, location: 1)],
                               startPoint: .top, endPoint: .bottom)
            }
            .onAppear { reader.scrollTo(current, anchor: .bottom) }
            .onChange(of: current) { _, now in withAnimation(.smooth(duration: 0.6)) { reader.scrollTo(now, anchor: .bottom) } }
        }
        .frame(maxWidth: 290, alignment: .leading)
    }

    private func row(_ step: TodayPage.Step, index: Int) -> some View {
        let now = index == current
        return HStack(alignment: .top, spacing: 8) {
            mark(step)
            VStack(alignment: .leading, spacing: 1) {
                HStack(spacing: 5) {
                    Text(step.time).font(.geist(12, .bold)).monospacedDigit().foregroundStyle(Palette.textSoft)
                    if now {
                        Text("Now").font(.geist(10.5, .bold)).foregroundStyle(Palette.onAction)
                            .padding(.horizontal, 5).frame(height: 16).background(Palette.action, in: .rect(cornerRadius: 4))
                    }
                }
                Text(step.title).font(.geist(now ? 15 : 14, .bold)).lineLimit(2).fixedSize(horizontal: false, vertical: true)
                if index >= current, !step.sub.isEmpty {
                    Text(step.sub).font(.geist(12)).foregroundStyle(Palette.textSoft).lineLimit(now ? 2 : 1)
                }
            }
        }
        .padding(.horizontal, 10).padding(.vertical, 8)
        .glassEffect(.regular, in: .rect(cornerRadius: 14))
        .overlay { if now { RoundedRectangle(cornerRadius: 14).strokeBorder(Palette.action.opacity(0.8), lineWidth: 1.5) } }
        .foregroundStyle(Palette.text)
        .opacity(index < current ? 0.55 : 1)
        .accessibilityElement(children: .combine)
        .accessibilityAddTraits(now ? .isSelected : [])
    }

    @ViewBuilder
    private func mark(_ step: TodayPage.Step) -> some View {
        if step.kind == .ride, let line = trip.badge(step) {
            LineBadge(line: line, color: step.color, height: 20)
        } else {
            Image(systemName: step.kind == .there ? "flag.fill" : step.kind == .getOff ? "arrow.down.right" : "figure.walk")
                .font(.system(size: 12, weight: .bold)).frame(width: 26, height: 20)
                .foregroundStyle(step.kind == .there ? Palette.action : Palette.textSoft)
        }
    }
}
