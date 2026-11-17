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
    /// The step held: pulled down (the whole screen follows, to minimise) or dragged sideways (the step
    /// follows, to the next or the one before); which, decided once per touch.
    @State private var pull: CGFloat = 0
    @State private var sideways: CGFloat = 0
    @State private var axis: Axis?
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
                            Group {
                                if mapOn {
                                    bandMoment(step, index: shown, count: steps.count, now: now)
                                        .transition(stepSwap)
                                } else {
                                    moment(step, index: shown, count: steps.count, now: now, look: look)
                                        .transition(stepSwap)
                                }
                            }
                            // Dragged sideways: the step goes with the finger, fading as it goes.
                            .offset(x: sideways)
                            .opacity(1 - min(0.6, abs(sideways) / 300))
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
            // Pulled down by the step: the whole trip follows the finger, what's below showing.
            .pulledDown(pull)
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
                // Clear, so the trip shows below while they're swiped back (the map draws their own).
                .presentationBackground(.clear)
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
            let line = liveLine(step, index: index, now: now)
            if !line.isEmpty {
                HStack(spacing: 8) {
                    if step.kind == .ride, let line = trip.badge(step) {
                        LineBadge(line: line, color: step.color, height: 24, mode: trip.legs[step.leg ?? 0].mode, icon: true, onColour: true)
                    }
                    Text(line).font(.geist(15, .medium))
                        .contentTransition(.numericText()).animation(.smooth, value: line)
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

    private func steps(_ index: Int) -> TodayPage.Step? { trip.steps.indices.contains(index) ? trip.steps[index] : nil }

    /// The line under the step now, live (the owner, 14 Nov: "instead of static text… valuable
    /// information… updated"): what they need next, as it changes. On a ride, the stop it comes to
    /// next and how many to go (as late as it runs), or that theirs is next; waiting for it, how late it
    /// is and where it goes; on foot, the ride they walk to as late as it runs; getting off, what
    /// comes after. A step looked at ahead, or one without anything live, keeps its own words.
    private func liveLine(_ step: TodayPage.Step, index: Int, now: Date) -> String {
        guard index == current(now) else { return step.sub }
        let late = trip.late(here: planner.follower.here, at: now)
        let lateWords = { (seconds: TimeInterval) -> String in
            let minutes = Int((seconds / 60).rounded())
            return minutes >= 2 ? "\(minutes) min late" : minutes <= -2 ? "\(-minutes) min early" : "On time"
        }
        let steps = trip.steps
        let next = steps.indices.contains(index + 1) ? steps[index + 1] : nil
        switch step.kind {
        case .ride:
            guard let leg = step.leg, trip.legs.indices.contains(leg) else { return step.sub }
            let ride = trip.legs[leg]
            let onBoard = planner.follower.here?.ride?.onBoard == true
            // Not left yet: how it runs, and the way it goes.
            if !onBoard, now < ride.departAt.addingTimeInterval(late) {
                return [lateWords(late), ride.headsign.map { "towards \($0)" }].compactMap { $0 }.joined(separator: " · ")
            }
            guard let left = Guide(trip: trip).rideLeft(leg: leg, at: now, late: late) else { return step.sub }
            if left.stops <= 1 { return "Get off at the next stop · \(ride.to)" }
            let lateness = abs(late) >= 120 ? lateWords(late) + " · " : ""
            return lateness + "Next \(left.next ?? ride.to) · \(left.stops) stops to go"
        case .walk:
            // Walking to a ride: that ride, as late as the board says.
            if let next, next.kind == .ride, let leg = next.leg, trip.legs.indices.contains(leg) {
                let ride = trip.legs[leg]
                let delay = TimeInterval(max(0, ride.delayMin ?? 0) * 60)
                return ride.cancelled ? "\(ride.name) at \(ride.departTime) is cancelled"
                    : "\(ride.name) at \(ride.departTime) · " + lateWords(delay)
            }
            return step.sub
        case .getOff:
            return next.map { "Then " + $0.title.prefix(1).lowercased() + $0.title.dropFirst() } ?? step.sub
        case .there:
            return step.sub
        }
    }

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
                // The way (and its flag) kept clear of the whole feed: a ride's stops fill it to the top.
                viewInset: UIEdgeInsets(top: 0, left: 0, bottom: stepsOpen ? Self.feedHeight + 70 : 0, right: 0),
                // A drag of the map while following goes free, and the button says so (as Apple Maps).
                followEnded: { withAnimation(.smooth) { followMe = false } },
                standIn: standIn)
            .ignoresSafeArea()
            .overlay(alignment: .bottomLeading) { mapControls(current: current, now: now) }
            .sensoryFeedback(.selection, trigger: followMe)
            .sensoryFeedback(.selection, trigger: stepsOpen)
    }

    private static let feedHeight: CGFloat = 300

    /// The arrow's stand-in when the phone has no good fix (underground, a weak signal; the owner, 14
    /// Nov: the arrow stuck where the last fix was): on a ride, where the ride is on its line by its
    /// timetable, as late as it runs, heading the way it goes. On foot there's nothing to tell by.
    private var standIn: (Date) -> CLLocation? {
        let trip = trip
        let follower = planner.follower
        let guide = Guide(trip: trip)
        return { date in
            let live = trip.live(at: date, here: follower.here)
            guard live.step.kind == .ride, let leg = live.step.leg,
                  let at = guide.estimate(leg: leg, at: date, late: trip.late(here: follower.here, at: date)) else { return nil }
            // A halo as wide as the guess may be off.
            return CLLocation(coordinate: CLLocationCoordinate2D(latitude: at.point.latitude, longitude: at.point.longitude), altitude: 0,
                              horizontalAccuracy: 60, verticalAccuracy: -1, course: at.course, speed: 8, timestamp: date)
        }
    }

    /// The step you're on, compact, in the band over the map; its title is the same one as the big
    /// step's, gliding between the two.
    private func bandMoment(_ step: TodayPage.Step, index: Int, count: Int, now: Date) -> some View {
        HStack(alignment: .center, spacing: 12) {
            VStack(alignment: .leading, spacing: 2) {
                Text(step.title).font(.posterGrotesque(24)).lineLimit(2).minimumScaleFactor(0.8)
                let line = liveLine(step, index: index, now: now)
                if !line.isEmpty {
                    Text(line).font(.geist(13.5, .medium)).opacity(0.9).lineLimit(1).minimumScaleFactor(0.85)
                        .contentTransition(.numericText()).animation(.smooth, value: line)
                }
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
                // The steps over the map as live comments: the one you're on at the bottom, the next ones
                // above it fading out; each ride's stops in it, open (its arrow folds them), the next one live.
                StepsFeed(trip: trip, current: current, ride: rideTrack(current: current, now: now),
                          live: steps(current).map { liveLine($0, index: current, now: now) })
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
        DragGesture(minimumDistance: 14, coordinateSpace: .global)
            .onChanged { value in
                let dx = value.translation.width, dy = value.translation.height
                if axis == nil { axis = abs(dx) > abs(dy) ? .horizontal : dy > 0 ? .vertical : nil }
                switch axis {
                case .vertical: pull = PullDown.follow(dy)
                case .horizontal:
                    // No step that way: it gives a little, and comes back.
                    let edge = (dx < 0 && shown >= count - 1) || (dx > 0 && shown <= 0)
                    sideways = edge ? dx * 0.2 : dx * 0.8
                case nil: break
                }
            }
            .onEnded { value in
                defer { axis = nil }
                let dx = value.translation.width
                switch axis {
                case .vertical:
                    PullDown.release($pull, dy: value.translation.height, predicted: value.predictedEndTranslation.height,
                                     height: PullDown.page, reduceMotion: reduceMotion) { dismiss() }
                case .horizontal:
                    let to = min(max(shown + (dx < 0 ? 1 : -1), 0), count - 1)
                    let far = abs(dx) > 60 || abs(value.predictedEndTranslation.width) > 160
                    withAnimation(.spring(duration: 0.35, bounce: 0.15)) {
                        sideways = 0
                        if far, to != shown { looking = to == current ? nil : to }
                    }
                    if far, to != shown { swiped += 1 }
                case nil: break
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

    /// On a ride that has left: where the tram is along its stops, as a fraction (2.4 = four tenths of
    /// the way from its third stop to its fourth; the boarding stop is 0), on the timetable as late as it
    /// runs. Before it leaves, nothing: no stop is "next" while they wait for it.
    private func rideTrack(current: Int, now: Date) -> RideTrack? {
        let steps = trip.steps
        guard steps.indices.contains(current), steps[current].kind == .ride, let index = steps[current].leg,
              trip.legs.indices.contains(index) else { return nil }
        let leg = trip.legs[index]
        let at = now.addingTimeInterval(-trip.late(here: planner.follower.here, at: now))
        var times = [leg.departAt]
        for stop in leg.stopsOnTheWay {
            guard let last = times.last, var time = TodayPage.today(stop.time, on: last) else { return nil }
            if time < last.addingTimeInterval(-60) { time = time.addingTimeInterval(86_400) }
            times.append(max(time, last))
        }
        times.append(max(leg.arriveAt, times.last ?? leg.arriveAt))
        guard let first = times.first, let last = times.last, at > first else { return nil }
        let progress: Double
        if at >= last { progress = Double(times.count - 1) } else {
            let i = times.lastIndex { $0 <= at } ?? 0
            let span = times.indices.contains(i + 1) ? times[i + 1].timeIntervalSince(times[i]) : 0
            progress = Double(i) + (span > 0 ? at.timeIntervalSince(times[i]) / span : 0)
        }
        return RideTrack(step: current, progress: progress)
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


/// A ride on the map's feed, once it has left: which step, and how far the tram is along its stops
/// (0 is where it was boarded).
struct RideTrack: Equatable {
    let step: Int
    let progress: Double
}

/// The steps over the map's lower left, read as the step list reads (the owner, 14 Nov: "in normal
/// steps it is easy to follow, on the map it is not"): one line down the left joining every card, in
/// the line's colour for a ride and dotted for walking, the same rings on it; the time of each in one
/// column on the right; solid cards, so the map never shows through the words. The step now is kept
/// at the top, later ones go on below and fade out. Every card is the same card.
///
/// A ride's stops are a stack, as iOS stacks a group of notifications. On the ride you're on, the
/// stop it comes to next is the card on top, the rest behind it (their edges peek out below); a ride
/// still to come keeps them all behind it. A tap fans them out downwards, each its own card; "Show
/// less" over the ride stacks them again. A stop passed leaves the feed.
private struct StepsFeed: View {
    let trip: TodayPage.Trip
    let current: Int
    let ride: RideTrack?
    /// The step now, live (the band's line): "Next Bayenthal · 20 stops to go", "Tram 16 at 17:24 ·
    /// 2 min late". Its card says it in place of the step's fixed words, and moves on with the trip.
    var live: String?
    /// Rides fanned out by hand.
    @State private var opened: Set<Int> = []
    /// Each fan-out or stack, and the ride it was: the feed keeps that ride where the finger is.
    @State private var turned = 0
    @State private var turnedRide: Int?
    private var steps: [TodayPage.Step] { trip.steps }

    /// How far down the top edge fades in.
    static let fade: CGFloat = 22
    /// Between two cards; the line crosses it.
    static let gap: CGFloat = 6
    /// The marks' column, and where the line runs in it.
    static let markColumn: CGFloat = 22

    /// A card in the feed: a step, a stop a ride passes (by its place among the ride's stops on the
    /// way), or the header over a fanned-out ride (Show less).
    enum Card: Hashable { case step(Int), stop(Int, Int), head(Int) }

    /// The line on its way through a card: a ride's colour, dotted walking, or none.
    enum Rail: Equatable { case none, walk, ride(Color) }

    var body: some View {
        let cards = self.cards
        let rails = Self.rails(cards, steps: steps)
        ScrollViewReader { reader in
            ScrollView {
                VStack(alignment: .leading, spacing: Self.gap) {
                    ForEach(Array(cards.enumerated()), id: \.element) { n, card in
                        Group {
                            switch card {
                            case let .step(index): row(steps[index], index: index, rail: rails[n], last: n == cards.count - 1)
                            case let .stop(index, stop): stopCard(steps[index], index: index, stop: stop, rail: rails[n])
                            case let .head(index): head(steps[index], index: index, rail: rails[n])
                            }
                        }
                        // The ride over its stops, so they come out from under it.
                        .zIndex(Self.layer(card))
                        .id(card)
                    }
                }
                .padding(.bottom, 160)
            }
            .scrollIndicators(.hidden)
            // Room over the first card, clear of the fade: the card a scroll lands on is whole.
            .contentMargins(.top, Self.fade, for: .scrollContent)
            .defaultScrollAnchor(.top)
            // Done ones fade out over the top, later ones at the bottom; nothing is cut off.
            .mask {
                LinearGradient(stops: [.init(color: .clear, location: 0), .init(color: .black, location: Self.fade / 300),
                                       .init(color: .black, location: 0.6), .init(color: .black.opacity(0.35), location: 0.85),
                                       .init(color: .clear, location: 1)],
                               startPoint: .top, endPoint: .bottom)
            }
            .sensoryFeedback(.impact(weight: .light), trigger: turned)
            .onAppear { reader.scrollTo(focus, anchor: .top) }
            // After the cards that came or went are laid out: as the trip moves on, the step now at
            // the top; a ride fanned out or stacked by hand stays where it was tapped.
            .onChange(of: focus) { settle(reader, to: focus) }
            .onChange(of: turned) {
                guard let ride = turnedRide else { return }
                settle(reader, to: isOpen(ride) ? .head(ride) : .step(ride))
            }
        }
        .frame(maxWidth: 300, alignment: .leading)
    }

    private func settle(_ reader: ScrollViewProxy, to card: Card) {
        Task { @MainActor in
            try? await Task.sleep(for: .milliseconds(80))
            withAnimation(.smooth(duration: 0.5)) { reader.scrollTo(card, anchor: .top) }
        }
    }

    private static func layer(_ card: Card) -> Double {
        switch card {
        case .step: 2
        case .head: 1
        case .stop: 0
        }
    }

    /// The feed in time order: each step; under a ride its stops fanned out (Show less over it), or on
    /// the ride you're on the next one on top of the stack.
    private var cards: [Card] {
        steps.indices.flatMap { index -> [Card] in
            if isOpen(index) { return [.head(index), .step(index)] + shown(index).map { .stop(index, $0) } }
            if let next = nextStop(index), shown(index).contains(next) { return [.step(index), .stop(index, next)] }
            return [.step(index)]
        }
    }

    /// The line through each card: what it comes in on is what the card before went out on.
    static func rails(_ cards: [Card], steps: [TodayPage.Step]) -> [(in: Rail, out: Rail)] {
        var into = Rail.none
        return cards.map { card in
            let out: Rail
            switch card {
            case let .step(index):
                let step = steps[index]
                switch step.kind {
                case .ride: out = .ride(StepColour.of(step))
                case .there: out = .none
                default: out = index == steps.count - 1 ? .none : .walk
                }
            case let .stop(index, _): out = .ride(StepColour.of(steps[index]))
            case .head: out = into
            }
            defer { into = out }
            return (into, out)
        }
    }

    /// What the feed keeps at its top: the step now (with its Show less when its stops are out).
    private var focus: Card { isOpen(current) ? .head(current) : .step(current) }

    /// The stops a ride passes between getting on and getting off (getting off is the step after it).
    private func passes(_ step: TodayPage.Step) -> [TodayPage.Trip.Leg.Stop] {
        guard step.kind == .ride, let leg = step.leg, trip.legs.indices.contains(leg) else { return [] }
        let ride = trip.legs[leg]
        return ride.stopsOnTheWay.filter { $0.name != ride.from && $0.name != ride.to }
    }

    /// The stops in a ride's stack: on the ride you're on, those still to come; on another, all.
    private func shown(_ index: Int) -> [Int] {
        let all = Array(passes(steps[index]).indices)
        return index == current ? all.filter { !passed(index, $0) } : all
    }

    /// Fanned out: by hand, and only while there's more than the one on top.
    private func isOpen(_ index: Int) -> Bool {
        guard steps.indices.contains(index), !shown(index).isEmpty else { return false }
        return opened.contains(index)
    }

    /// Stops still stacked behind what shows.
    private func behind(_ index: Int) -> Int {
        guard !isOpen(index) else { return 0 }
        let count = shown(index).count
        return nextStop(index) != nil ? max(0, count - 1) : count
    }

    /// On the ride now, once it has left: the stop on the way it comes to next (nil: next is getting off).
    private func nextStop(_ index: Int) -> Int? {
        guard ride?.step == index, let progress = ride?.progress else { return nil }
        let next = Int(progress.rounded(.down))
        return passes(steps[index]).indices.contains(next) ? next : nil
    }

    /// The stops on the way this ride has passed (by the clock, as late as it runs); all, once it's done.
    private func passed(_ index: Int, _ stop: Int) -> Bool {
        if index < current { return true }
        guard ride?.step == index, let progress = ride?.progress else { return false }
        return Double(stop + 1) <= progress.rounded(.down)
    }

    /// Fanned out or stacked, with a spring as iOS's notification stacks do.
    private func toggle(_ index: Int) {
        withAnimation(.spring(duration: 0.45, bounce: 0.15)) {
            if opened.contains(index) { opened.remove(index) } else { opened.insert(index) }
        }
        turnedRide = index
        turned += 1
    }

    // MARK: The card

    /// One card, the same for every step and stop: its mark on the line at the left, the words, the
    /// time (and Now or Next) in a column on the right; solid, on the page's own cream.
    private func card(rail: (in: Rail, out: Rail), done: Bool, now: Bool = false, stacked: Int = 0,
                      @ViewBuilder mark: () -> some View, @ViewBuilder words: () -> some View, time: String, tag: (String, Color, Color)? = nil) -> some View {
        HStack(alignment: .top, spacing: 10) {
            mark().frame(width: Self.markColumn, height: 20)
            words().frame(maxWidth: .infinity, alignment: .leading)
            VStack(alignment: .trailing, spacing: 3) {
                Text(time).font(.geist(13, now ? .bold : .medium)).monospacedDigit()
                    .foregroundStyle(now ? Palette.text : Palette.textSoft)
                if let (text, fill, ink) = tag { Self.tag(text, fill: fill, ink: ink) }
            }
        }
        .opacity(done ? 0.5 : 1)
        .padding(.horizontal, 10).padding(.vertical, 9)
        // The line, behind the mark: in from the card above, out to the card below, through the gaps.
        .background(alignment: .topLeading) { line(rail).padding(.leading, 10) }
        .background(Palette.surface, in: .rect(cornerRadius: 14))
        .overlay {
            RoundedRectangle(cornerRadius: 14).strokeBorder(now ? Palette.action.opacity(0.85) : Palette.border.opacity(0.7), lineWidth: now ? 1.5 : 0.75)
        }
        // Stops stacked behind it: the edges of two more cards peek out below (where they go).
        .background(alignment: .bottom) { if stacked > 0 { stack } }
        .padding(.bottom, stacked > 0 ? 12 : 0)
        .shadow(color: .black.opacity(0.1), radius: 6, y: 2)
        .foregroundStyle(Palette.text)
    }

    /// The line through a card at the marks' centre: the half above the mark as it came in, the half
    /// below as it goes out, each reaching across the gap to the next card.
    private func line(_ rail: (in: Rail, out: Rail)) -> some View {
        GeometryReader { proxy in
            let x = Self.markColumn / 2
            let mid: CGFloat = 9 + 10
            ZStack(alignment: .topLeading) {
                segment(rail.in, from: CGPoint(x: x, y: -Self.gap / 2 - 1), to: CGPoint(x: x, y: mid))
                segment(rail.out, from: CGPoint(x: x, y: mid), to: CGPoint(x: x, y: proxy.size.height + Self.gap / 2 + 1))
            }
        }
        .frame(width: Self.markColumn)
        .allowsHitTesting(false)
    }

    @ViewBuilder
    private func segment(_ rail: Rail, from a: CGPoint, to b: CGPoint) -> some View {
        let path = Path { $0.move(to: a); $0.addLine(to: b) }
        switch rail {
        case .none: EmptyView()
        case .walk: path.stroke(Palette.textSoft, style: StrokeStyle(lineWidth: 3, lineCap: .round, dash: [0.1, 6]))
        case let .ride(colour): path.stroke(colour, style: StrokeStyle(lineWidth: 3, lineCap: .butt))
        }
    }

    // MARK: Steps, stops, Show less

    private func row(_ step: TodayPage.Step, index: Int, rail: (in: Rail, out: Rail), last: Bool) -> some View {
        let now = index == current
        let colour = StepColour.of(step)
        // With the next stop on top of the stack (riding), the stack is under that card, not the ride's.
        let behind = nextStop(index) == nil ? behind(index) : 0
        let walking = step.kind == .walk
        return card(rail: rail, done: index < current, now: now, stacked: behind,
                    mark: {
                        // The step list's ring; the step now is "you are here": solid, a white dot in it.
                        if step.kind == .there {
                            Image(systemName: "flag.fill").font(.system(size: 13, weight: .bold)).foregroundStyle(Palette.action)
                        } else {
                            Circle().strokeBorder(walking ? Palette.textSoft : colour, lineWidth: 3)
                                .background(Circle().fill(now ? (walking ? Palette.text : colour) : Palette.surface))
                                .overlay { if now { Circle().fill(.white).frame(width: 6, height: 6) } }
                                .frame(width: 17, height: 17)
                        }
                    },
                    words: {
                        VStack(alignment: .leading, spacing: 1) {
                            Text(step.title).font(.geist(now ? 15 : 14, now ? .bold : .semibold))
                                .foregroundStyle(now && !walking ? colour : Palette.text)
                                .lineLimit(2).fixedSize(horizontal: false, vertical: true)
                            if now, let live, !live.isEmpty {
                                Text(live).font(.geist(12.5, .medium)).foregroundStyle(Palette.text).lineLimit(2)
                                    .contentTransition(.numericText()).animation(.smooth, value: live)
                            } else if index >= current, !step.sub.isEmpty {
                                Text(step.sub).font(.geist(12)).foregroundStyle(Palette.textSoft).lineLimit(2)
                            }
                            if behind > 0 {
                                Text("\(behind) stop\(behind == 1 ? "" : "s") · tap to see them").font(.geist(12, .medium)).foregroundStyle(Palette.navTeal)
                            }
                        }
                    },
                    time: step.time,
                    tag: now ? ("Now", Palette.action, Palette.onAction) : nil)
        .contentShape(.rect)
        .onTapGesture { if !shown(index).isEmpty { toggle(index) } }
        .accessibilityElement(children: .combine)
        .accessibilityAddTraits(now ? .isSelected : [])
        .accessibilityAction(named: isOpen(index) ? "Show less" : "Show the stops") { if !shown(index).isEmpty { toggle(index) } }
    }

    /// A stop the ride passes, a card like every other: its ring on the ride's line, its name and how
    /// many stops from there to get off, its time; "Next" on the one coming.
    private func stopCard(_ step: TodayPage.Step, index: Int, stop: Int, rail: (in: Rail, out: Rail)) -> some View {
        let calls = passes(step)
        let call = calls[stop]
        let next = nextStop(index) == stop
        let colour = StepColour.of(step)
        let look = LineLook(hex: step.color ?? "#1A4CD4")
        let ride = step.leg.map { trip.legs[$0] }
        let left = calls.count - stop
        let behind = next ? behind(index) : 0
        return card(rail: rail, done: passed(index, stop), stacked: behind,
                    mark: { FeedStopMark(colour: colour, coming: next) },
                    words: {
                        VStack(alignment: .leading, spacing: 1) {
                            Text(call.name).font(.geist(14, next ? .bold : .semibold))
                                .foregroundStyle(next ? colour : Palette.text)
                                .lineLimit(2).fixedSize(horizontal: false, vertical: true)
                            Text("\(left) stop\(left == 1 ? "" : "s") to \(ride?.to ?? "getting off")")
                                .font(.geist(12)).foregroundStyle(Palette.textSoft).lineLimit(1)
                                .contentTransition(.numericText()).animation(.smooth, value: left)
                            if behind > 0 {
                                Text("\(behind) more stop\(behind == 1 ? "" : "s") · tap to see them").font(.geist(12, .medium)).foregroundStyle(Palette.navTeal)
                            }
                        }
                    },
                    time: call.time,
                    tag: next ? ("Next", look.base, look.ink) : nil)
        .contentShape(.rect)
        .onTapGesture { if behind > 0 || isOpen(index) { toggle(index) } }
        // Out from under the ride, downwards, as a notification group fans out.
        .transition(.asymmetric(insertion: .offset(y: -40).combined(with: .scale(scale: 0.94, anchor: .top)).combined(with: .opacity),
                                removal: .offset(y: -40).combined(with: .scale(scale: 0.94, anchor: .top)).combined(with: .opacity)))
        .accessibilityElement(children: .combine)
        .accessibilityLabel("\(call.time), \(call.name)\(next ? ", next stop" : ""), \(left) to get off")
    }

    /// The edges of the stacked cards under a card: narrower and fainter the further back, only their
    /// bottoms showing (the card in front hides the rest, as on the Lock Screen).
    private var stack: some View {
        VStack(spacing: 0) {
            edge(radius: 13).padding(.horizontal, 10).opacity(0.95)
            edge(radius: 11).padding(.horizontal, 20).opacity(0.8)
        }
        .offset(y: 12)
        .allowsHitTesting(false)
        .transition(.opacity)
    }

    private func edge(radius: CGFloat) -> some View {
        UnevenRoundedRectangle(bottomLeadingRadius: radius, bottomTrailingRadius: radius)
            .fill(Palette.surface)
            .overlay { UnevenRoundedRectangle(bottomLeadingRadius: radius, bottomTrailingRadius: radius).strokeBorder(Palette.border, lineWidth: 0.75) }
            .frame(height: 6)
            .shadow(color: .black.opacity(0.1), radius: 2, y: 1)
    }

    /// Over a ride's fanned-out stops, as over an open notification group: Show less, with the ride's
    /// line on it; the line goes on behind it, on the left.
    private func head(_ step: TodayPage.Step, index: Int, rail: (in: Rail, out: Rail)) -> some View {
        HStack {
            line(rail).frame(height: 32).padding(.leading, 10)
            Spacer(minLength: 0)
            Button { toggle(index) } label: {
                HStack(spacing: 7) {
                    if let line = trip.badge(step) { LineBadge(line: line, color: step.color, height: 18) }
                    Text("Show less").font(.geist(13, .semibold)).foregroundStyle(Palette.text)
                    Image(systemName: "chevron.up").font(.system(size: 10, weight: .bold)).foregroundStyle(Palette.textSoft)
                }
                .padding(.horizontal, 10).frame(height: 32)
                .background(Palette.surface, in: .rect(cornerRadius: 10))
                .overlay { RoundedRectangle(cornerRadius: 10).strokeBorder(Palette.border.opacity(0.7), lineWidth: 0.75) }
                .shadow(color: .black.opacity(0.1), radius: 6, y: 2)
                .contentShape(.rect)
            }
            .buttonStyle(PressStyle())
            .accessibilityLabel("Show less: stack the \(shown(index).count) stops on \(step.name)")
        }
        .frame(height: 32)
        .transition(.opacity)
    }

    /// The small word on a card ("Now", "Next"): a rounded rectangle, never a pill.
    static func tag(_ text: String, fill: some ShapeStyle, ink: Color) -> some View {
        Text(text).font(.geist(10.5, .bold)).foregroundStyle(ink)
            .padding(.horizontal, 5).frame(height: 16).background(fill, in: .rect(cornerRadius: 4))
    }
}

/// A stop's mark in the map's feed: a small ring on the ride's line, the size of the step list's.
/// The one coming next is a ring that calls (a halo going out from it), never solid: solid is the
/// step you're on, so the two never look alike.
private struct FeedStopMark: View {
    let colour: Color
    let coming: Bool
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var pulse = false

    var body: some View {
        ZStack {
            if coming {
                Circle().stroke(colour.opacity(0.5), lineWidth: 2).frame(width: 22, height: 22)
                    .scaleEffect(pulse ? 1.2 : 0.6).opacity(pulse ? 0 : 1)
            }
            Circle().fill(Palette.surface)
                .overlay { Circle().strokeBorder(colour, lineWidth: coming ? 3 : 2) }
                .frame(width: coming ? 13 : 9, height: coming ? 13 : 9)
        }
        .onAppear {
            guard coming, !reduceMotion else { return }
            withAnimation(.easeOut(duration: 1.4).repeatForever(autoreverses: false)) { pulse = true }
        }
    }
}
