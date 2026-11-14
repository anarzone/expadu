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
    @State private var stepsOpen = true
    @State private var asking = false
    /// Each step swiped to, for its tick.
    @State private var swiped = 0
    @AppStorage("speakTurns") private var speak = false
    @State private var problem: String?
    /// Where the Map button sits on the screen, for the map to grow out of ("Grow").
    @State private var mapAnchor = UnitPoint(x: 0.85, y: 0.5)
    /// The line's colour, one shape between the whole screen and the band over the map.
    @Namespace private var colourSpace

    var body: some View {
        TimelineView(.periodic(from: .now, by: 15)) { timeline in
            let now = timeline.date
            let steps = trip.steps
            // The step they're on: the phone while it knows where they are, else the clock, as late as
            // the ride runs (one rule, the card's and the island's too).
            let current = trip.live(at: now, here: planner.follower.here).current
            let shown = looking ?? current
            let step = steps.indices.contains(shown) ? steps[shown] : nil
            let look = LineLook(hex: trip.legs.first { $0.rides }?.color ?? "#00768B")
            ZStack(alignment: .top) {
                if mapOn {
                    // The map view: the whole screen is the map, the step you're on in a band on top.
                    mapLayer(current: current, now: now)
                        .transition(mapTransition)
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
                    // Swipes on the step: left for the next step, right for the one before (as the bars
                    // do), down to minimise (as the chevron does).
                    .contentShape(.rect)
                    .gesture(stepSwipe(count: steps.count, current: current, shown: shown))
                    // On the map, the line's colour is the band behind the step you're on: as tall as the
                    // step by layout, never measured. A measured band fed its height back into the layout it
                    // was measured from, and the screen froze on Minimise (iOS killed it, 13 Nov).
                    .background {
                        if mapOn { colour(look, band: true) }
                    }
                    if !mapOn {
                        way(steps: steps, current: current, now: now)
                            .transition(wayTransition)
                    }
                    Spacer(minLength: 0)
                }
                // With the steps, the line's colour is the whole screen, its line large behind them; on the
                // map it gathers into the band (the same shape, matched).
                .background(alignment: .topLeading) {
                    if !mapOn {
                        ZStack(alignment: .topLeading) {
                            colour(look, band: false)
                            if let line = trip.legs.first(where: \.rides)?.line {
                                Text(line).font(.posterGrotesque(360)).tracking(-16)
                                    .foregroundStyle(look.ink.opacity(0.1)).lineLimit(1).fixedSize()
                                    .offset(x: -20, y: 40)
                                    .allowsHitTesting(false)
                            }
                        }
                    }
                }
            }
            .coordinateSpace(.named(Self.screenSpace))
            .sensoryFeedback(.selection, trigger: swiped)
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
            if let view = planner.tripOpens { mapOn = view == .map; planner.tripOpens = nil }
        }
        // Asked for while it's open (the island's Steps or Map): that view, switched in place.
        .onChange(of: planner.tripOpens) { _, view in
            guard let view else { return }
            switchMap(view == .map)
            planner.tripOpens = nil
        }
        // A new route (re-planned here or on Today) is the one followed from now on.
        .onChange(of: trip) { planner.follow(trip) }
        // New routes from here (a missed tram, off the way): the list over the trip, to pick one.
        .fullScreenCover(isPresented: Binding(get: { planner.newRoutes }, set: { if !$0 { closeNewRoutes() } })) {
            TripRoutesView(planner: planner, back: closeNewRoutes, close: closeNewRoutes)
                .task { await newRoutesFromHere() }
        }
    }

    private static let screenSpace = "trip.screen"

    /// The line's colour: the whole screen, or the band over the map with its round lower corners.
    private func colour(_ look: LineLook, band: Bool) -> some View {
        UnevenRoundedRectangle(bottomLeadingRadius: band ? 26 : 0, bottomTrailingRadius: band ? 26 : 0)
            .fill(look.fill)
            .shadow(color: .black.opacity(band ? 0.2 : 0), radius: 14, y: 4)
            .matchedGeometryEffect(id: "colour", in: colourSpace)
            .ignoresSafeArea(edges: band ? .top : .all)
            .allowsHitTesting(false)
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
                    Button { planner.newRoutes = true } label: { Label(ride.mode == "bus" ? "I missed the bus" : "I missed the tram", systemImage: "clock") }
                }
                Button { somewhereElse() } label: { Label("I’m somewhere else", systemImage: "location") }
                Divider()
                Toggle(isOn: $speak) { Label("Speak turns", systemImage: "speaker.wave.2") }
                if planner.follower.trusted()?.arrived == true || trip.live(at: .now, here: planner.follower.here).current >= trip.steps.count - 2 {
                    Button {
                        Task { await today.endTrip(session.api, arrived: true); planner.stopFollowing(); dismiss() }
                    } label: { Label("I’m there", systemImage: "checkmark") }
                }
            } label: {
                // The trip's options (a missed tram, somewhere else, speak turns, I'm there): "Re-plan"
                // read as a new plan on offer (the owner, 13 Nov). iOS's "more" sign, the size of its neighbours.
                Image(systemName: "ellipsis").font(.system(size: 17, weight: .bold))
                .frame(width: 42, height: 42)
                // The glass is the label's own, not the menu's: on the menu, iOS morphed it into the menu
                // and back, and it stood a blank white circle for a second after (the owner, 13 Nov).
                .glassEffect(.clear, in: .circle)
                .contentShape(.circle)
            }
            .accessibilityLabel("Trip options")
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
            // Walking: the next turn, from where the phone is; none when it doesn't know.
            if index == current(now), step.kind == .walk, let here = planner.follower.trusted(at: now), let turn = here.turn, !here.offRoute {
                TurnRow(turn: turn, metres: here.metresToTurn)
                    .padding(10).background(Palette.surface, in: .rect(cornerRadius: 16)).foregroundStyle(Palette.text)
                    .transition(.opacity)
            }
            // Trip sync: the tram they're really on, followed (Undo), or which one, asked in place.
            if index == current(now), let note = today.cardNote {
                CardSay(note: note).padding(10).background(Palette.surface, in: .rect(cornerRadius: 16))
                    .transition(.opacity.combined(with: .move(edge: .top)))
            }
            if index == current(now), let choice = today.rideChoice {
                RideChooser(choice: choice).transition(.opacity.combined(with: .move(edge: .top)))
            }
            // Gone wrong: off the route for a while, or the ride left without them. A new route in one tap.
            if let trouble {
                TripTrouble(text: trouble) { planner.newRoutes = true }
                    .padding(12).background(Palette.surface, in: .rect(cornerRadius: 16)).foregroundStyle(Palette.text)
                    .transition(.opacity)
            }
        }
        .accessibilityElement(children: .combine)
    }

    /// The step they're on now (the phone when walking, else the clock).
    private func current(_ now: Date) -> Int { trip.live(at: now, here: planner.follower.here).current }

    /// Off the route for 15 s, or the ride gone, as the follower sees it.
    private var trouble: String? {
        guard let here = planner.follower.trusted() else { return nil }
        if let missed = here.missed { return "\(missed.name) has left. A new route from here?" }
        if here.offRoute, let since = planner.follower.offSince, Date.now.timeIntervalSince(since) > 15 {
            return "You’re off the route, \(TurnRow.distance(Int(here.offBy))) from it."
        }
        return nil
    }

    /// What the minutes count down to: leaving, the next tram, getting off, or being there.
    private func countdownLabel(_ step: TodayPage.Step, index: Int, now: Date) -> String {
        if step.at > timetable(now) { return index == 0 ? "Leave in" : "Starts in" }
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
    private func way(steps: [TodayPage.Step], current: Int, now: Date) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Text("All steps").font(.geist(16, .bold))
                Text("· \(steps.count)").font(.geist(15)).foregroundStyle(Palette.textSoft)
                Spacer(minLength: 0)
                Button { switchMap(true) } label: {
                    Label("Map", systemImage: "map").font(.geist(14, .semibold))
                        .padding(.horizontal, 12).frame(height: 38)
                }
                // Where the button sits on the trip's screen, read with the screen's size in one go: nothing on
                // the screen is laid out from it, so the read can't feed back into itself.
                .onGeometryChange(for: UnitPoint.self) { proxy in
                    let frame = proxy.frame(in: .named(Self.screenSpace))
                    let size = proxy.bounds(of: .named(Self.screenSpace))?.size ?? .zero
                    return UnitPoint(x: frame.midX / max(1, size.width), y: frame.midY / max(1, size.height))
                } action: { mapAnchor = $0 }
                .buttonStyle(PressStyle())
                .background(Palette.quiet, in: .rect(cornerRadius: 12))
                .accessibilityHint("Shows the way on a full-screen map")
            }
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    // One list: each ride's stops fold into its step (Show stops), not a second list under it.
                    StepList(steps: steps, current: current, legs: trip.legs, stopsOpen: true, live: liveStop(current: current, now: now))
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
    private func mapLayer(current: Int, now: Date) -> some View {
        CityMap(lines: WayDrawing.lines(trip.legs), pins: WayDrawing.pins(trip.legs, to: trip.to), fit: WayDrawing.all(trip.legs),
                padding: UIEdgeInsets(top: stepsOpen ? 225 : 250, left: 40, bottom: stepsOpen ? 16 : 130, right: 40), follow: followMe, showsUser: true,
                fitID: fitTick * 10 + (stepsOpen ? 1 : 0), creditInset: stepsOpen ? 8 : 70,
                // The list's top fades out, so the map only keeps clear of its solid lower part.
                viewInset: UIEdgeInsets(top: 0, left: 0, bottom: stepsOpen ? Self.feedHeight * 0.7 + 64 : 0, right: 0))
            .ignoresSafeArea()
            .overlay(alignment: .bottomLeading) { mapControls(current: current, now: now) }
            .sensoryFeedback(.selection, trigger: followMe)
            .sensoryFeedback(.selection, trigger: stepsOpen)
    }

    private static let feedHeight: CGFloat = 330

    /// The steps card on the map, at the step they're on.
    private func scrollToCurrent(_ reader: ScrollViewProxy, _ current: Int) {
        reader.scrollTo("steps", anchor: UnitPoint(x: 0, y: Double(current) / Double(max(trip.steps.count - 1, 1))))
    }

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
    private func mapControls(current: Int, now: Date) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            if stepsOpen {
                // The same steps as the step view (each ride's stops open, the next one live), in a card
                // over the map. Its grab bar folds it away: drag it down, or tap it (the list itself scrolls).
                VStack(spacing: 0) {
                    Capsule().fill(Palette.border).frame(width: 40, height: 5)
                        .frame(maxWidth: .infinity).frame(height: 24)
                        .contentShape(.rect)
                        .onTapGesture { withAnimation(.smooth(duration: 0.35)) { stepsOpen = false } }
                        .gesture(
                            DragGesture(minimumDistance: 8).onEnded { value in
                                if value.translation.height > 40 { withAnimation(.smooth(duration: 0.35)) { stepsOpen = false } }
                            }
                        )
                        .accessibilityLabel("Hide the steps")
                        .accessibilityAddTraits(.isButton)
                    ScrollViewReader { reader in
                        ScrollView {
                            StepList(steps: trip.steps, current: current, legs: trip.legs, stopsOpen: true, live: liveStop(current: current, now: now))
                                .padding(.horizontal, 16).padding(.bottom, 16).padding(.top, 2)
                                .id("steps")
                        }
                        .scrollIndicators(.hidden)
                        .onAppear { scrollToCurrent(reader, current) }
                        .onChange(of: current) { _, now in withAnimation(.smooth(duration: 0.6)) { scrollToCurrent(reader, now) } }
                    }
                }
                .frame(height: Self.feedHeight)
                .background(Palette.surface, in: .rect(cornerRadius: 22))
                .clipShape(.rect(cornerRadius: 22))
                .shadow(color: .black.opacity(0.15), radius: 12, y: 4)
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
                // Swipe up on it to open the steps, down to fold them.
                .simultaneousGesture(
                    DragGesture(minimumDistance: 20).onEnded { value in
                        let dy = value.translation.height
                        guard abs(dy) > 40, abs(dy) > abs(value.translation.width) else { return }
                        withAnimation(.smooth(duration: 0.35)) { stepsOpen = dy < 0 }
                    }
                )
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

    /// Swipes on the step you're on: left/right look at the next/previous step, down minimises. Vertical
    /// swipes on the map's band are left to the map; only a clear horizontal or downward swipe counts.
    private func stepSwipe(count: Int, current: Int, shown: Int) -> some Gesture {
        DragGesture(minimumDistance: 24)
            .onEnded { value in
                let dx = value.translation.width, dy = value.translation.height
                if abs(dx) > abs(dy) * 1.4, abs(dx) > 50 {
                    let to = min(max(shown + (dx < 0 ? 1 : -1), 0), count - 1)
                    guard to != shown else { return }
                    withAnimation(.smooth) { looking = to == current ? nil : to }
                    swiped += 1
                } else if dy > 90, dy > abs(dx) * 1.4 {
                    dismiss()
                }
            }
    }

    /// The stop the ride comes to next, as late as it runs (the phone on board, else the board's delay).
    private func liveStop(current: Int, now: Date) -> (step: Int, next: String?)? {
        let steps = trip.steps
        guard steps.indices.contains(current), steps[current].kind == .ride, let leg = steps[current].leg,
              let left = Guide(trip: trip).rideLeft(leg: leg, at: now, late: trip.late(here: planner.follower.here, at: now)) else { return nil }
        return (current, left.next)
    }

    /// The minutes the countdown shows: until the step starts, or until the next one once on it.
    private func minutesLeft(_ step: TodayPage.Step, index: Int, now: Date) -> Int {
        let next = trip.steps.indices.contains(index + 1) ? trip.steps[index + 1].at : nil
        // On the timetable as late as the ride runs: a late tram's minutes, not the plan's.
        let now = timetable(now)
        let until = step.at > now ? step.at : (next ?? step.at)
        return max(0, Int((until.timeIntervalSince(now) / 60).rounded(.up)))
    }

    /// Now on the trip's timetable: as far back as the ride runs late.
    private func timetable(_ now: Date) -> Date { now.addingTimeInterval(-trip.late(here: planner.follower.here, at: now)) }

    /// The routes from where they are to the trip's place, to pick one from (never one taken for them).
    private func newRoutesFromHere() async {
        guard let end = trip.legs.last?.end else { return }
        let point = planner.follower.point ?? today.place.map { TodayPage.Point(latitude: $0.latitude, longitude: $0.longitude) }
        await planner.routes(to: TripPoint(name: trip.to, lat: end.latitude, lng: end.longitude), startsAt: nil,
                             from: point.map { TripPoint(name: "Your location", lat: $0.latitude, lng: $0.longitude) }, session.api)
    }

    private func closeNewRoutes() {
        planner.closeRoutes()
        planner.newRoutes = false
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

