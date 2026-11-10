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
    @State private var mapOn = false
    @State private var asking = false
    @State private var problem: String?
    @State private var working = false
    @State private var sheetOpen = true
    @State private var sheetDrag: CGFloat = 0
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
                look.fill.ignoresSafeArea()
                if let line = trip.legs.first(where: \.rides)?.line {
                    Text(line).font(.posterGrotesque(360)).tracking(-16)
                        .foregroundStyle(look.ink.opacity(0.1)).lineLimit(1).fixedSize()
                        .offset(x: -20, y: 40).frame(maxWidth: .infinity, alignment: .leading)
                        .allowsHitTesting(false)
                }
                VStack(alignment: .leading, spacing: 14) {
                    head(look)
                    bars(steps.count, current: current, shown: shown, ink: look.ink)
                    if let step { moment(step, index: shown, count: steps.count, now: now, look: look) }
                    Spacer(minLength: 0)
                }
                .padding(.horizontal, 16).padding(.top, 8)
                .foregroundStyle(look.ink)
                VStack {
                    Spacer()
                    sheet(steps: steps, current: current, look: look)
                }
                .ignoresSafeArea(edges: .bottom)
            }
            .animation(reduceMotion ? nil : .smooth(duration: 0.4), value: shown)
        }
        .task(id: looking) {
            // A look ahead comes back to the step they're on after a while.
            guard looking != nil else { return }
            try? await Task.sleep(for: .seconds(6))
            withAnimation(.smooth) { looking = nil }
        }
        .alert("Stop this trip?", isPresented: $asking) {
            Button("Keep", role: .cancel) {}
            Button("Stop", role: .destructive) {
                Task {
                    await today.endTrip(session.api, arrived: false)
                    planner.stopFollowing()
                    dismiss()
                }
            }
        } message: { Text("We’ll stop following it. It stays on your day.") }
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
                Button(role: .destructive) { asking = true } label: { Label("Stop the trip", systemImage: "xmark") }
            } label: {
                Text(working ? "Finding…" : "Not right?").font(.geist(14, .semibold)).padding(.horizontal, 14).frame(height: 42)
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
        let next = trip.steps.indices.contains(index + 1) ? trip.steps[index + 1].at : nil
        let until = step.at > now ? step.at : (next ?? step.at)
        let minutes = max(0, Int((until.timeIntervalSince(now) / 60).rounded(.up)))
        return VStack(alignment: .leading, spacing: 10) {
            Text(index == count - 1 ? "Arrived" : "\(index + 1) of \(count)").font(.geist(13, .semibold)).opacity(0.9)
            Text(step.title).font(.posterGrotesque(36)).tracking(-0.4).fixedSize(horizontal: false, vertical: true)
                .contentTransition(.opacity)
            if index < count - 1 {
                HStack(alignment: .lastTextBaseline, spacing: 8) {
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

    private func sheet(steps: [TodayPage.Step], current: Int, look: LineLook) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            // The handle: tap or drag to fold the sheet away and bring it back.
            VStack(spacing: 10) {
                Capsule().fill(Palette.border).frame(width: 36, height: 5)
                if !sheetOpen {
                    HStack {
                        Text("Your way").font(.geist(15, .semibold))
                        Text("· \(steps.count) steps").font(.geist(15)).foregroundStyle(Palette.textSoft)
                        Spacer()
                        Image(systemName: "chevron.up").font(.system(size: 13, weight: .semibold)).foregroundStyle(Palette.textSoft)
                    }
                }
            }
            .frame(maxWidth: .infinity).frame(minHeight: 28)
            .contentShape(.rect)
            .onTapGesture { withAnimation(.smooth(duration: 0.35)) { sheetOpen.toggle() } }
            .accessibilityElement(children: .ignore)
            .accessibilityLabel(sheetOpen ? "Fold away your way" : "Show your way")
            .accessibilityAddTraits(.isButton)
            if sheetOpen {
                HStack(spacing: 8) {
                    Button { withAnimation(.smooth) { mapOn.toggle() } } label: {
                        Label("On the map", systemImage: "map").font(.geist(15, .semibold)).padding(.horizontal, 14).frame(height: 46)
                    }
                    .buttonStyle(PressStyle())
                    .background(mapOn ? Palette.selectBackground : Palette.quiet, in: .rect(cornerRadius: 14))
                    Spacer(minLength: 0)
                }
                if mapOn {
                    CityMap(lines: WayDrawing.lines(trip.legs), pins: WayDrawing.pins(trip.legs, to: trip.to), fit: WayDrawing.all(trip.legs),
                            padding: UIEdgeInsets(top: 24, left: 24, bottom: 24, right: 24), showsUser: true)
                        .frame(height: 180)
                        .clipShape(.rect(cornerRadius: 18))
                        .transition(.opacity.combined(with: .scale(scale: 0.96, anchor: .top)))
                }
                Text("Your way").font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft)
                ScrollView {
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
                        .font(.geist(12.5)).foregroundStyle(Palette.textSoft).padding(.top, 8)
                }
                .frame(maxHeight: mapOn ? 200 : 360)
                .scrollIndicators(.hidden)
                .transition(.opacity)
            }
        }
        .foregroundStyle(Palette.text)
        .padding(16).padding(.bottom, 24)
        .background(Palette.surface, in: .rect(topLeadingRadius: 30, topTrailingRadius: 30))
        .shadow(color: .black.opacity(0.25), radius: 20, y: -6)
        .offset(y: max(0, sheetDrag))
        .gesture(
            DragGesture(minimumDistance: 12)
                .onChanged { sheetDrag = sheetOpen ? $0.translation.height : min(0, $0.translation.height) + 0 }
                .onEnded { value in
                    withAnimation(.smooth(duration: 0.35)) {
                        if value.translation.height > 60 || value.predictedEndTranslation.height > 160 { sheetOpen = false }
                        else if value.translation.height < -40 { sheetOpen = true }
                        sheetDrag = 0
                    }
                }
        )
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

/// A ride's stops, folded to one row ("Tram 12 · 15 stops"); opened, each stop with its time on the line.
private struct RideStops: View {
    let leg: TodayPage.Trip.Leg
    let open: Bool
    let toggle: () -> Void

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
                    Text("\(leg.stopsOnTheWay.count) stop\(leg.stopsOnTheWay.count == 1 ? "" : "s")").font(.geist(13)).foregroundStyle(Palette.textSoft)
                    Image(systemName: "chevron.down").font(.system(size: 12, weight: .semibold)).foregroundStyle(Palette.textSoft)
                        .rotationEffect(.degrees(open ? 180 : 0))
                }
                .frame(minHeight: 40).contentShape(.rect)
            }
            .buttonStyle(PressStyle())
            .accessibilityLabel("Stops on line \(leg.line ?? ""), \(leg.stopsOnTheWay.count)")
            if open {
                VStack(alignment: .leading, spacing: 0) {
                    ForEach(Array(leg.stopsOnTheWay.enumerated()), id: \.offset) { _, stop in
                        HStack(spacing: 12) {
                            Circle().fill(Palette.surface).overlay { Circle().strokeBorder(colour, lineWidth: 2.5) }.frame(width: 11, height: 11)
                                .frame(width: 17)
                                .background { Rectangle().fill(colour).frame(width: 3).padding(.vertical, -10) }
                            Text(stop.name).font(.geist(13.5))
                            Spacer(minLength: 4)
                            Text(stop.time).font(.geist(13)).monospacedDigit().foregroundStyle(Palette.textSoft)
                        }
                        .frame(minHeight: 30)
                    }
                }
                .clipped()
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
    }
}
