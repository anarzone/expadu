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
    @Environment(\.dismiss) private var dismiss
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @AppStorage("speakTurns") private var speak = false
    @State private var following = true
    @State private var pull: CGFloat = 0
    /// The step the map is flown to; nil shows the whole way.
    @State private var focus: Int?
    @State private var whole = 0
    @State private var cardHeight: CGFloat = 260

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
                    fit: fit, padding: UIEdgeInsets(top: 130, left: 48, bottom: cardHeight + 40, right: 48),
                    follow: guiding != nil && following, showsUser: true, fitID: whole)
                .ignoresSafeArea()
            VStack(spacing: 10) {
                HStack(alignment: .top) {
                    // Pull down from here: the map shrinks back to where it came from.
                    Capsule().fill(Palette.textSoft.opacity(0.55)).frame(width: 40, height: 5)
                        .frame(maxWidth: .infinity).frame(height: 44)
                        .contentShape(.rect)
                        .gesture(
                            DragGesture(minimumDistance: 8)
                                .onChanged { value in pull = max(0, value.translation.height) }
                                .onEnded { value in
                                    if value.translation.height > 90 || value.predictedEndTranslation.height > 220 { dismiss() }
                                    withAnimation(.easeOut(duration: 0.3)) { pull = 0 }
                                }
                        )
                        .accessibilityAction(named: "Close the map") { dismiss() }
                        .padding(.leading, 56)
                    Button { dismiss() } label: {
                        Image(systemName: "xmark").font(.system(size: 16, weight: .semibold)).foregroundStyle(Palette.text)
                    }
                    .buttonStyle(RaisedStyle(fill: Palette.surface, size: 44))
                    .accessibilityLabel("Close the map")
                }
                if let here, let turn = here.turn, !here.offRoute {
                    TurnRow(turn: turn, metres: here.metresToTurn)
                        .padding(14)
                        .glassEffect(.regular, in: .rect(cornerRadius: 22))
                        .transition(.opacity.combined(with: .offset(y: -8)))
                } else if let here, here.offRoute {
                    Label("You’re off the way. Go back to the line, or plan a new way on the card.", systemImage: "exclamationmark.triangle.fill")
                        .font(.geist(14, .semibold)).foregroundStyle(Palette.warnText)
                        .padding(14).frame(maxWidth: .infinity, alignment: .leading)
                        .background(Palette.warnBackground, in: .rect(cornerRadius: 18))
                }
            }
            .padding(.horizontal, 14).padding(.top, 10)
        }
        .overlay(alignment: .bottom) {
            VStack(alignment: .trailing, spacing: 12) {
                controls
                card
                    .onGeometryChange(for: CGFloat.self) { $0.size.height } action: { cardHeight = $0 }
            }
            .padding(.horizontal, 12).padding(.bottom, 8)
        }
        // Following the finger while pulled, as a sheet does.
        .scaleEffect(1 - min(pull, 300) / 1500, anchor: .top)
        .offset(y: pull * 0.6)
        .animation(Motion.ios(0.3), value: here?.turn)
        .animation(reduceMotion ? nil : Motion.ios(0.35), value: focus)
        .presentationDetents([.large])
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
            if guiding == nil || !following {
                Button {
                    focus = nil
                    whole += 1
                } label: {
                    Image(systemName: "arrow.up.left.and.arrow.down.right").font(.system(size: 16, weight: .semibold)).foregroundStyle(Palette.text)
                }
                .buttonStyle(RaisedStyle(fill: Palette.surface, size: 48))
                .accessibilityLabel("Show the whole way")
            }
        }
        .padding(.trailing, 4)
    }

    // MARK: The card

    private var card: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack(spacing: 12) {
                plates
                VStack(alignment: .leading, spacing: 2) {
                    Text("To \(to)").font(.geist(17, .semibold)).foregroundStyle(Palette.text).lineLimit(1)
                    Text(summary).font(.geist(13.5)).foregroundStyle(Palette.textSoft).lineLimit(1).monospacedDigit()
                        .contentTransition(.numericText())
                }
                Spacer(minLength: 0)
            }
            if let now = nowWords {
                HStack(spacing: 8) {
                    Text("Now").font(.geist(12, .bold)).foregroundStyle(.white)
                        .padding(.horizontal, 8).frame(height: 22).background(Motion.green, in: .capsule)
                    Text(now).font(.geist(15, .semibold)).foregroundStyle(Palette.text).lineLimit(1)
                }
            }
            steps
            if guiding == nil, ways.count > 1, picked != nil { otherWays }
            if let start {
                Button(action: start) {
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
            if let travel {
                Button(action: travel) {
                    Label("Plan a different trip in Travel", systemImage: "chevron.right").labelStyle(TrailingIcon())
                        .font(.geist(14.5, .semibold)).foregroundStyle(TodayLink.ink)
                        .frame(maxWidth: .infinity).frame(minHeight: 36)
                }
                .buttonStyle(PressStyle())
            }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .glassEffect(.regular, in: .rect(cornerRadius: 28))
        .shadow(color: .black.opacity(0.12), radius: 16, y: 6)
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

    /// Each leg as a stop on a line: tap it and the map flies there, tap again for the whole way.
    private var steps: some View {
        ScrollView(.horizontal) {
            HStack(spacing: 6) {
                ForEach(Array(legs.enumerated()), id: \.offset) { index, leg in
                    if index > 0 { Image(systemName: "chevron.right").font(.system(size: 10, weight: .bold)).foregroundStyle(Palette.textSoft) }
                    let on = focus == index
                    let current = here?.leg == index
                    Button {
                        following = false
                        focus = on ? nil : index
                        if on { whole += 1 }
                    } label: {
                        HStack(spacing: 6) {
                            Image(systemName: Self.symbol(leg)).font(.system(size: 13, weight: .semibold))
                            Text(Self.chip(leg)).font(.geist(14, .semibold)).lineLimit(1)
                        }
                        .foregroundStyle(on ? .white : Palette.text)
                        .padding(.horizontal, 12).frame(height: 36)
                        .background(on ? Self.colour(leg) : (current ? Motion.green.opacity(0.16) : Palette.surface), in: .capsule)
                        .raised(Capsule())
                    }
                    .buttonStyle(PressStyle())
                    .accessibilityLabel(Self.words(leg))
                    .accessibilityHint(on ? "Shows the whole way" : "Shows this step on the map")
                    .accessibilityAddTraits(on ? .isSelected : [])
                }
            }
            .padding(.vertical, 4).padding(.horizontal, 2)
        }
        .scrollIndicators(.hidden)
    }

    /// The ways to choose between, on the map: pick one and it's drawn instead.
    private var otherWays: some View {
        ScrollView(.horizontal) {
            HStack(spacing: 8) {
                ForEach(Array(ways.enumerated()), id: \.offset) { index, other in
                    let on = picked?.wrappedValue == index
                    Button {
                        focus = nil
                        picked?.wrappedValue = index
                    } label: {
                        HStack(spacing: 6) {
                            if let line = other.lines.first {
                                LineBadge(line: line.line, color: line.color, height: 22)
                            } else {
                                Image(systemName: other.mode == "bike" ? "bicycle" : "figure.walk").font(.system(size: 13, weight: .semibold))
                            }
                            Text("\(other.leaveTime) · \(other.minutes) min").font(.geist(14, .semibold)).monospacedDigit()
                        }
                        .foregroundStyle(Palette.text)
                        .padding(.leading, 6).padding(.trailing, 12).frame(height: 36)
                        .background(on ? Palette.quiet : Palette.surface, in: .capsule)
                        .overlay { if on { Capsule().strokeBorder(Palette.text, lineWidth: 1.5) } }
                        .raised(Capsule())
                    }
                    .buttonStyle(PressStyle())
                    .accessibilityLabel("Leave \(other.leaveTime), \(other.minutes) minutes. \(other.note)")
                    .accessibilityAddTraits(on ? .isSelected : [])
                }
            }
            .padding(.vertical, 4).padding(.horizontal, 2)
        }
        .scrollIndicators(.hidden)
        .animation(reduceMotion ? nil : .easeInOut(duration: 0.3), value: picked?.wrappedValue)
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

    static func chip(_ leg: TodayPage.Trip.Leg) -> String {
        if leg.rides { return "\(leg.line.map { Board.badge($0) } ?? leg.mode.capitalized)\(leg.stops.map { " · \($0) \($0 == 1 ? "stop" : "stops")" } ?? "")" }
        return "\(leg.minutes) min"
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
