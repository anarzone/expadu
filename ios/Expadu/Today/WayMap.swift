import Core
import CoreLocation
import DesignSystem
import Maps
import SwiftUI

/// The way on the whole screen (the canvas's .mapx): each leg in its line's colour, walking dotted,
/// and a card that says it all: the lines, when to leave and arrive, each step to fly the map to,
/// the other ways to compare on the map, and the one thing to do. While the trip is live: where
/// they are, the turn coming up, Speak turns and Follow me.
struct WayMap: View {
    let to: String
    let legs: [TodayPage.Trip.Leg]
    /// While the trip is live: our navigation on the map (the phone, the next turn, Speak turns).
    var guiding: (trip: TodayPage.Trip, follow: PhoneFollow)?
    /// Getting there: the ways to choose between and the one picked; the card's action starts it.
    var ways: [TodayPage.Way] = []
    var picked: Binding<Int>?
    var start: (() -> Void)?
    var starting = false
    /// A different trip, in Travel.
    var travel: (() -> Void)?
    /// What's good to know on the way (ticket, rain, lifts, notices), as Getting there lists it.
    var know: [KnowRow.Row] = []
    /// When the event starts: the last step says so.
    var starts: String?
    @Environment(\.dismiss) private var dismiss
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @AppStorage("speakTurns") private var speak = false
    @State private var following = true
    @State private var pull: CGFloat = 0
    /// The step the map is flown to; nil shows the whole way.
    @State private var focus: Int?
    @State private var whole = 0
    @State private var cardHeight: CGFloat = 260
    /// The card is iOS's own sheet (Apple Maps'): small, half or full, the map usable behind it.
    @State private var detent: PresentationDetent = Self.peek
    @State private var showCard = true
    /// What to do once the card has gone: iOS takes one dismissal at a time.
    @State private var then: (() -> Void)?
    static let peek = PresentationDetent.height(178)

    private var here: Guide.Position? { guiding?.follow.here }
    private var way: TodayPage.Way? { picked.flatMap { ways.indices.contains($0.wrappedValue) ? ways[$0.wrappedValue] : nil } }

    private var lines: [CityMap.Line] {
        let drawn = WayDrawing.lines(legs, doneBefore: here?.leg)
        guard let focus else { return drawn }
        return drawn.enumerated().map { index, line in
            CityMap.Line(id: line.id, points: line.points, color: line.color, width: line.width, dashed: line.dashed, faint: index != focus)
        }
    }

    private var fit: [CLLocationCoordinate2D] {
        if let focus, legs.indices.contains(focus) {
            let points = WayDrawing.points(legs[focus])
            if !points.isEmpty { return points }
        }
        return WayDrawing.all(legs)
    }

    var body: some View {
        ZStack(alignment: .top) {
            CityMap(lines: lines, pins: WayDrawing.pins(legs, to: to, start: guiding == nil),
                    fit: fit, padding: UIEdgeInsets(top: 120, left: 48, bottom: max(cardHeight, 178) + 40, right: 48),
                    follow: guiding != nil && following, showsUser: true, fitID: whole)
                .ignoresSafeArea()
            if let here, let turn = here.turn, !here.offRoute {
                TurnRow(turn: turn, metres: here.metresToTurn)
                    .padding(14)
                    .glassEffect(.regular, in: .rect(cornerRadius: 22))
                    .padding(.horizontal, 14).padding(.top, 10)
                    .transition(.opacity.combined(with: .offset(y: -8)))
            } else if let here, here.offRoute {
                Label("You’re off the way. Go back to the line, or plan a new way on the card.", systemImage: "exclamationmark.triangle.fill")
                    .font(.geist(14, .semibold)).foregroundStyle(Palette.warnText)
                    .padding(14).frame(maxWidth: .infinity, alignment: .leading)
                    .background(Palette.warnBackground, in: .rect(cornerRadius: 18))
                    .padding(.horizontal, 14).padding(.top, 10)
            }
        }
        // The map's own buttons ride just above the card, whatever its height.
        .overlay(alignment: .bottomTrailing) {
            controls.padding(.trailing, 12).padding(.bottom, max(cardHeight, 178) - 20)
                .animation(reduceMotion ? nil : .easeOut(duration: 0.2), value: cardHeight)
        }
        .animation(Motion.ios(0.3), value: here?.turn)
        .animation(reduceMotion ? nil : Motion.ios(0.35), value: focus)
        .sheet(isPresented: $showCard, onDismiss: { then?(); then = nil }) {
            card
                .onGeometryChange(for: CGFloat.self) { $0.size.height } action: { cardHeight = $0 }
                .presentationDetents([Self.peek, .medium, .large], selection: $detent)
                .presentationBackgroundInteraction(.enabled(upThrough: .medium))
                .presentationDragIndicator(.visible)
                .presentationCornerRadius(28)
                .interactiveDismissDisabled()
        }
        .presentationDetents([.large])
    }

    /// The card goes first, then the map (or Travel opens).
    private func close(then next: (() -> Void)? = nil) {
        then = next ?? { dismiss() }
        showCard = false
    }

    // MARK: Controls

    /// Show the whole way again; while guiding, Speak turns and Follow me.
    private var controls: some View {
        VStack(spacing: 10) {
            if guiding != nil {
                Button { speak.toggle() } label: {
                    Image(systemName: speak ? "speaker.wave.2.fill" : "speaker.slash.fill").font(.system(size: 17, weight: .semibold))
                        .foregroundStyle(speak ? Palette.action : Palette.text)
                        .contentTransition(.symbolEffect(.replace))
                }
                .buttonStyle(RaisedStyle(fill: Palette.surface, size: 48))
                .accessibilityLabel(speak ? "Stop speaking turns" : "Speak turns")
                Button { following.toggle(); if !following { focus = nil; whole += 1 } } label: {
                    Image(systemName: following ? "location.north.line.fill" : "location").font(.system(size: 17, weight: .semibold))
                        .foregroundStyle(following ? Color(hex: 0x2F6FD0) : Palette.text)
                }
                .buttonStyle(RaisedStyle(fill: Palette.surface, size: 48))
                .accessibilityLabel(following ? "Show the whole way" : "Follow me")
            }
        }
        .padding(.trailing, 4)
    }

    // MARK: The card

    /// The card: small shows where to and the one thing to do; half adds the way as a bar, share
    /// and Travel, and the other ways; full adds every step and what's good to know.
    private var card: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                HStack(spacing: 12) {
                    plates
                    VStack(alignment: .leading, spacing: 2) {
                        Text("To \(to)").font(.geist(17, .semibold)).foregroundStyle(Palette.text).lineLimit(1)
                        Text(summary).font(.geist(13.5)).foregroundStyle(Palette.textSoft).lineLimit(1).monospacedDigit()
                            .contentTransition(.numericText())
                    }
                    Spacer(minLength: 0)
                    Button { close() } label: {
                        Image(systemName: "xmark").font(.system(size: 14, weight: .bold)).foregroundStyle(Palette.textSoft)
                    }
                    .buttonStyle(RaisedStyle(fill: Palette.surface, size: 36))
                    .accessibilityLabel("Close the map")
                }
                if let now = nowWords {
                    HStack(spacing: 8) {
                        Text("Now").font(.geist(12, .bold)).foregroundStyle(.white)
                            .padding(.horizontal, 8).frame(height: 22).background(Motion.green, in: .capsule)
                        Text(now).font(.geist(15, .semibold)).foregroundStyle(Palette.text).lineLimit(1)
                    }
                }
                if let start {
                    Button { close(then: start) } label: {
                        HStack(spacing: 8) {
                            if starting { ProgressView().tint(.white) } else { Image(systemName: "figure.walk") }
                            Text("I’m on my way").lineLimit(1)
                        }
                        .font(.geist(17, .semibold)).foregroundStyle(.white)
                        .frame(maxWidth: .infinity).frame(height: 50)
                        .background(Palette.action, in: .capsule).raised(Capsule())
                    }
                    .buttonStyle(PressStyle())
                    .disabled(starting)
                }
                RouteBar(legs: legs, focus: $focus, current: here?.leg) { on in
                    following = false
                    if !on { whole += 1 }
                }
                actions
                if guiding == nil, ways.count > 1, picked != nil {
                    section("Other ways") { otherWays }
                }
                section("Step by step") {
                    StepList(steps: TodayPage.Step.of(legs, to: to, start: starts), current: here.map { at in
                        TodayPage.Step.of(legs, to: to, start: starts).firstIndex { $0.leg == at.leg }
                    } ?? nil)
                }
                if !know.isEmpty {
                    section("Good to know") {
                        VStack(spacing: 0) {
                            ForEach(Array(know.enumerated()), id: \.offset) { index, row in
                                if index > 0 { Rectangle().fill(Palette.border).frame(height: 0.5).padding(.leading, 59) }
                                KnowRow(row: row)
                            }
                        }
                        .background(Palette.surface, in: .rect(cornerRadius: 18))
                    }
                }
            }
            .padding(.horizontal, 18).padding(.top, 20).padding(.bottom, 30)
        }
        .scrollIndicators(.hidden)
        .scrollDisabled(detent == Self.peek)
    }

    /// Share the way, and a different trip in Travel: two quiet tiles, as Apple Maps' Share ETA.
    private var actions: some View {
        HStack(spacing: 10) {
            ShareLink(item: shareText) {
                tile("square.and.arrow.up", "Share")
            }
            .buttonStyle(PressStyle())
            if let travel {
                Button { close(then: travel) } label: { tile("tram.fill", "Travel") }
                    .buttonStyle(PressStyle())
                    .accessibilityLabel("Plan a different trip in Travel")
            }
            Button {
                focus = nil
                following = false
                whole += 1
            } label: { tile("arrow.up.left.and.arrow.down.right", "Whole way") }
            .buttonStyle(PressStyle())
        }
    }

    private func tile(_ symbol: String, _ title: String) -> some View {
        VStack(spacing: 5) {
            Image(systemName: symbol).font(.system(size: 17, weight: .semibold)).foregroundStyle(TodayLink.ink)
                .frame(height: 24)
            Text(title).font(.geist(13, .semibold)).foregroundStyle(Palette.text).lineLimit(1)
        }
        .frame(maxWidth: .infinity).frame(height: 62)
        .background(Palette.surface, in: .rect(cornerRadius: 16))
        .raised(RoundedRectangle(cornerRadius: 16))
    }

    private func section(_ title: String, @ViewBuilder content: () -> some View) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(title).font(.posterGrotesque(18)).foregroundStyle(Palette.text).accessibilityAddTraits(.isHeader)
            content()
        }
        .padding(.top, 4)
    }

    /// "Tram 18 at 18:47 from Neumarkt, there 18:53 · Kreuzblume".
    private var shareText: String {
        let ride = legs.first(where: \.rides)
        let how = ride.map { "\($0.line.map { "Line \(Board.badge($0))" } ?? $0.mode.capitalized) at \($0.departTime) from \($0.from)" }
            ?? (legs.first?.mode == "bike" ? "By bike" : "On foot")
        return "On my way to \(to): \(how), there at \(legs.last?.arriveTime ?? "")."
    }

    /// The lines ridden, as plates; on foot or by bike, its sign.
    @ViewBuilder
    private var plates: some View {
        let rides = legs.filter(\.rides)
        if rides.isEmpty {
            Image(systemName: legs.first?.mode == "bike" ? "bicycle" : "figure.walk").font(.system(size: 17, weight: .semibold))
                .foregroundStyle(.white).frame(width: 40, height: 40)
                .background(legs.first?.mode == "bike" ? Color(uiColor: WayDrawing.bikeColour) : Color(uiColor: WayDrawing.walkColour), in: .rect(cornerRadius: 11))
        } else {
            HStack(spacing: 4) {
                ForEach(Array(rides.prefix(3).enumerated()), id: \.offset) { _, leg in
                    LineBadge(line: leg.line ?? "", color: leg.color, height: 32)
                }
            }
        }
    }

    /// "Leave 16:45 · there 16:55 · 5 min spare"; while guiding, when they'll be there.
    private var summary: String {
        if let trip = guiding?.trip { return "There at \(trip.legs.last?.arriveTime ?? trip.arriveTime ?? "")" }
        if let way { return "Leave \(way.leaveTime) · there \(way.arriveTime) · \(way.spareWords)" }
        guard let first = legs.first, let last = legs.last else { return "" }
        return "Leave \(first.departTime) · there \(last.arriveTime)"
    }

    /// The step they're on, while guiding.
    private var nowWords: String? {
        guard guiding != nil, let here, legs.indices.contains(here.leg) else { return nil }
        return Self.words(legs[here.leg])
    }

    /// The ways to choose between, as a quiet list (Apple Maps' routes): pick one and it's drawn.
    private var otherWays: some View {
        VStack(spacing: 0) {
            ForEach(Array(ways.enumerated()), id: \.offset) { index, other in
                let on = picked?.wrappedValue == index
                if index > 0 { Rectangle().fill(Palette.border).frame(height: 0.5).padding(.leading, 44) }
                Button {
                    focus = nil
                    picked?.wrappedValue = index
                } label: {
                    HStack(spacing: 10) {
                        Group {
                            if let line = other.lines.first {
                                LineBadge(line: line.line, color: line.color, height: 24)
                            } else {
                                Image(systemName: other.mode == "bike" ? "bicycle" : "figure.walk").font(.system(size: 15, weight: .semibold))
                                    .foregroundStyle(Palette.textSoft)
                            }
                        }
                        .frame(width: 34)
                        VStack(alignment: .leading, spacing: 1) {
                            Text("Leave \(other.leaveTime)").font(.geist(15, .semibold)).foregroundStyle(Palette.text).monospacedDigit()
                            Text(other.spareWords).font(.geist(12.5)).foregroundStyle(other.spareMinutes < 0 ? Palette.conflictText : Palette.textSoft)
                        }
                        Spacer(minLength: 0)
                        Text("\(other.minutes) min").font(.geist(15, .semibold)).foregroundStyle(Palette.text).monospacedDigit()
                        Image(systemName: "checkmark").font(.system(size: 13, weight: .bold)).foregroundStyle(Palette.action)
                            .opacity(on ? 1 : 0).frame(width: 16)
                    }
                    .frame(minHeight: 48)
                    .contentShape(.rect)
                }
                .buttonStyle(PressStyle())
                .accessibilityLabel("Leave \(other.leaveTime), \(other.minutes) minutes. \(other.note)")
                .accessibilityAddTraits(on ? .isSelected : [])
            }
        }
        .animation(reduceMotion ? nil : .easeInOut(duration: 0.25), value: picked?.wrappedValue)
    }

    // MARK: Words

    static func symbol(_ leg: TodayPage.Trip.Leg) -> String {
        switch leg.mode {
        case "walk": "figure.walk"
        case "bike": "bicycle"
        case "bus": "bus.fill"
        case "rail", "train", "suburban", "regional": "train.side.front.car"
        default: "tram.fill"
        }
    }

    static func words(_ leg: TodayPage.Trip.Leg) -> String {
        if leg.rides { return "\(leg.line.map { "Line \(Board.badge($0))" } ?? leg.mode.capitalized) to \(leg.to)" }
        return "\(leg.mode == "bike" ? "Ride" : "Walk") to \(leg.to), \(leg.minutes) min"
    }

    static func colour(_ leg: TodayPage.Trip.Leg) -> Color {
        if leg.rides { return LineLook(hex: leg.color ?? "#1A4CD4").deep }
        return Color(uiColor: leg.mode == "bike" ? WayDrawing.bikeColour : WayDrawing.walkColour)
    }
}

/// The way as one bar, each part as long as it takes (Citymapper's): rides in their line's colour
/// with the line on them, walking dotted. Tap a part and the map flies to it; tap again for all.
struct RouteBar: View {
    let legs: [TodayPage.Trip.Leg]
    @Binding var focus: Int?
    var current: Int?
    let tapped: (Bool) -> Void
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        let total = max(1, legs.reduce(0) { $0 + max($1.minutes, 1) })
        VStack(alignment: .leading, spacing: 6) {
            GeometryReader { box in
                let gaps = CGFloat(max(legs.count - 1, 0)) * 3
                HStack(spacing: 3) {
                    ForEach(Array(legs.enumerated()), id: \.offset) { index, leg in
                        let share = CGFloat(max(leg.minutes, 1)) / CGFloat(total)
                        let width = max(leg.rides ? 44 : 26, (box.size.width - gaps) * share)
                        Button {
                            let on = focus == index
                            focus = on ? nil : index
                            tapped(!on)
                        } label: { part(leg, index: index).frame(width: width) }
                        .buttonStyle(PressStyle())
                        .accessibilityLabel(WayMap.words(leg))
                        .accessibilityHint(focus == index ? "Shows the whole way" : "Shows this part on the map")
                        .accessibilityAddTraits(focus == index ? .isSelected : [])
                    }
                }
            }
            .frame(height: 30)
            // When each part starts, under it.
            HStack(spacing: 0) {
                Text(legs.first?.departTime ?? "")
                Spacer()
                Text(legs.last?.arriveTime ?? "")
            }
            .font(.geist(12, .medium)).foregroundStyle(Palette.textSoft).monospacedDigit()
        }
        .animation(reduceMotion ? nil : .easeInOut(duration: 0.25), value: focus)
    }

    @ViewBuilder
    private func part(_ leg: TodayPage.Trip.Leg, index: Int) -> some View {
        let dim = focus != nil && focus != index
        let colour = WayMap.colour(leg)
        ZStack {
            if leg.rides {
                Capsule().fill(colour)
                HStack(spacing: 4) {
                    Image(systemName: WayMap.symbol(leg)).font(.system(size: 11, weight: .bold))
                    Text(leg.line.map { Board.badge($0) } ?? "").font(.geist(13, .bold)).lineLimit(1)
                }
                .foregroundStyle(.white).padding(.horizontal, 6)
            } else {
                Capsule().strokeBorder(colour.opacity(0.55), style: StrokeStyle(lineWidth: 2, dash: [3, 3]))
                HStack(spacing: 2) {
                    Image(systemName: WayMap.symbol(leg)).font(.system(size: 11, weight: .bold))
                    Text("\(leg.minutes)").font(.geist(12, .semibold)).monospacedDigit()
                }
                .foregroundStyle(colour).lineLimit(1).minimumScaleFactor(0.7)
            }
        }
        .frame(height: 30)
        .overlay { if current == index { Capsule().strokeBorder(Motion.green, lineWidth: 2.5).padding(-3) } }
        .opacity(dim ? 0.35 : 1)
    }
}
