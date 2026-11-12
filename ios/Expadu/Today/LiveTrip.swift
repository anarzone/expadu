import API
import Core
import CoreLocation
import Maps
import DesignSystem
import SafariServices
import SwiftUI

/// A step's colour: its line's, the ink for walking, the red-orange of arriving.
enum StepColour {
    /// LINE_LOOK.walk.
    static let walk = Color(hex: 0x6A6253)

    static func of(_ step: TodayPage.Step) -> Color {
        step.color.map { LineLook(hex: $0).deep } ?? walk
    }
}

/// Every step of a way (.way .w-s): a ring in its colour, the line to the next (dotted for walking),
/// the step and when; past ones faded, the one they're on in its colour.
struct StepList: View {
    let steps: [TodayPage.Step]
    var current: Int?
    /// The way's legs: a ride's stops on the way fold into its step, opened with Show stops.
    var legs: [TodayPage.Trip.Leg] = []
    @State private var open: Set<Int> = []

    /// The stops a ride step passes between boarding and getting off; empty for any other step.
    private func stops(_ step: TodayPage.Step) -> [TodayPage.Trip.Leg.Stop] {
        guard step.kind == .ride, let leg = step.leg, legs.indices.contains(leg) else { return [] }
        return legs[leg].stopsOnTheWay
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            ForEach(Array(steps.enumerated()), id: \.offset) { index, step in
                let colour = StepColour.of(step)
                let isLast = index == steps.count - 1
                let passes = stops(step)
                let shown = open.contains(index)
                VStack(alignment: .leading, spacing: 0) {
                    HStack(alignment: .top, spacing: 12) {
                        Circle().strokeBorder(step.kind == .walk ? Palette.textSoft : colour, lineWidth: 3)
                            .background(Circle().fill(index == current ? (step.kind == .walk ? Palette.text : colour) : Palette.surface))
                            .frame(width: 17, height: 17)
                            .padding(.top, 1)
                        VStack(alignment: .leading, spacing: 1) {
                            // The step you're on stands out: bolder, in its line's colour (a walk's grey would fade it).
                            Text(step.title).font(.geist(index == current ? 15 : 14, index == current ? .bold : .semibold))
                                .foregroundStyle(index == current && step.kind != .walk ? colour : Palette.text)
                            if !step.sub.isEmpty { Text(step.sub).font(.geist(12.5)).foregroundStyle(Palette.textSoft) }
                            if !passes.isEmpty {
                                Button {
                                    withAnimation(.smooth(duration: 0.3)) {
                                        if shown { open.remove(index) } else { open.insert(index) }
                                    }
                                } label: {
                                    HStack(spacing: 4) {
                                        Text(shown ? "Hide stops" : "Show stops")
                                        Image(systemName: "chevron.down").font(.system(size: 10, weight: .bold)).rotationEffect(.degrees(shown ? 180 : 0))
                                    }
                                    .font(.geist(12.5, .semibold)).foregroundStyle(Palette.navTeal)
                                    .frame(minHeight: 30).contentShape(.rect)
                                }
                                .buttonStyle(PressStyle())
                                .accessibilityLabel(shown ? "Hide the stops on \(step.name)" : "Show the \(passes.count) stops on \(step.name)")
                            }
                        }
                        Spacer(minLength: 8)
                        Text(step.time).font(.geist(13)).monospacedDigit().foregroundStyle(Palette.textSoft)
                    }
                    // The ride's stops, opened: full width, so their times line up with the steps'.
                    if shown {
                        VStack(alignment: .leading, spacing: 0) {
                            ForEach(Array(passes.enumerated()), id: \.offset) { _, stop in
                                HStack(spacing: 8) {
                                    Text(stop.name).font(.geist(13))
                                    Spacer(minLength: 6)
                                    Text(stop.time).font(.geist(12.5)).monospacedDigit().foregroundStyle(Palette.textSoft)
                                }
                                .frame(minHeight: 26)
                                // The stop's mark sits on the ride's line, left of the text.
                                .overlay(alignment: .leading) {
                                    Circle().fill(Palette.surface).overlay { Circle().strokeBorder(colour, lineWidth: 2) }
                                        .frame(width: 9, height: 9).offset(x: -25)
                                }
                            }
                        }
                        .padding(.leading, 29)
                        .transition(.opacity.combined(with: .move(edge: .top)))
                    }
                }
                .padding(.bottom, isLast ? 0 : 14)
                // The line down to the next step, behind the marks, through the opened stops too.
                .background(alignment: .topLeading) {
                    if !isLast {
                        connector(step.kind == .walk, colour: colour).frame(width: 3).padding(.leading, 7).padding(.top, 10).offset(y: 6)
                    }
                }
                .opacity(current.map { index < $0 ? 0.5 : 1 } ?? 1)
            }
        }
    }

    @ViewBuilder
    private func connector(_ walking: Bool, colour: Color) -> some View {
        if walking {
            Rectangle().fill(.clear).overlay {
                GeometryReader { proxy in
                    Path { path in
                        path.move(to: CGPoint(x: 1.5, y: 0))
                        path.addLine(to: CGPoint(x: 1.5, y: proxy.size.height))
                    }
                    .stroke(Palette.textSoft, style: StrokeStyle(lineWidth: 3, lineCap: .round, dash: [0.1, 6]))
                }
            }
        } else {
            Capsule().fill(colour)
        }
    }
}

/// A ticket's half (.tk-top / .tk-bot): rounded outer corners and the two notches the perforation runs between.
nonisolated struct TicketHalf: Shape {
    let top: Bool

    func path(in rect: CGRect) -> Path {
        let radius: CGFloat = 26
        let body = Path(roundedRect: rect, cornerRadii: top
            ? RectangleCornerRadii(topLeading: radius, bottomLeading: 0, bottomTrailing: 0, topTrailing: radius)
            : RectangleCornerRadii(topLeading: 0, bottomLeading: radius, bottomTrailing: radius, topTrailing: 0))
        let y = top ? rect.maxY : rect.minY
        let notches = Path { path in
            path.addEllipse(in: CGRect(x: rect.minX - 11, y: y - 11, width: 22, height: 22))
            path.addEllipse(in: CGRect(x: rect.maxX - 11, y: y - 11, width: 22, height: 22))
        }
        return body.subtracting(notches)
    }
}

/// A Travel ticket's top half: the line plates, Leave → There, the minutes, the line's colour.
/// Other ways and Getting there show the same ticket.
struct TicketTop: View {
    let way: TodayPage.Way
    var mine = false

    var body: some View {
        // TRIP_LOOK: the line's colour; a change runs from the first line into the second; bike teal, walking soft brown.
        let look = LineLook(hex: way.lines.first?.color ?? (way.mode == "bike" ? "#00768B" : "#6A6253"))
        let fill = way.lines.count > 1 ? LineLook.fill(from: look, to: LineLook(hex: way.lines[1].color)) : look.fill
        let ink = look.ink
        VStack(alignment: .leading, spacing: 0) {
            if mine {
                Text("YOUR ROUTE").font(.posterMarker(15)).foregroundStyle(Motion.ink)
                    .padding(.horizontal, 10).padding(.top, 3).padding(.bottom, 2)
                    .background(Color(hex: 0xFFE363))
                    .rotationEffect(.degrees(-2))
                    .padding(.bottom, 10)
            }
            HStack(spacing: 8) {
                if way.lines.isEmpty {
                    plate(way.mode == "bike" ? "Bike" : "Walk", symbol: way.mode == "bike" ? "bicycle" : "figure.walk", colour: look.deep)
                }
                ForEach(Array(way.lines.enumerated()), id: \.offset) { index, line in
                    if index > 0 { Image(systemName: "chevron.right").font(.system(size: 12, weight: .bold)) }
                    plate(Board.badge(line.line), symbol: "tram.fill", colour: LineLook(hex: line.color).deep)
                }
                if let departs = way.departs, !way.lines.isEmpty {
                    Text("at \(departs)").font(.geist(16, .bold))
                }
            }
            HStack(alignment: .bottom, spacing: 10) {
                VStack(alignment: .leading, spacing: 4) {
                    Text("Leave").font(.geist(12.5, .semibold)).opacity(0.9)
                    Text(way.leaveTime).font(.posterGrotesque(42)).tracking(-1).monospacedDigit()
                }
                Image(systemName: "arrow.right").font(.system(size: 20, weight: .semibold)).padding(.bottom, 10)
                VStack(alignment: .leading, spacing: 4) {
                    Text("There").font(.geist(12.5, .semibold)).opacity(0.9)
                    Text(way.arriveTime).font(.posterGrotesque(42)).tracking(-1).monospacedDigit()
                }
            }
            .padding(.top, 12)
        }
        .foregroundStyle(ink)
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.top, 16).padding(.horizontal, 18).padding(.bottom, 18)
        .background(alignment: .topTrailing) {
            Text(way.lines.count == 1 ? Board.badge(way.lines[0].line) : "")
                .font(.posterGrotesque(170)).foregroundStyle(ink.opacity(0.13))
                .offset(x: 8, y: -18)
                .lineLimit(1).fixedSize()
        }
        .overlay(alignment: .topTrailing) {
            Text("\(way.minutes) min").font(.geist(13, .bold)).foregroundStyle(ink)
                .padding(.horizontal, 11).frame(height: 28)
                .background(.black.opacity(0.28), in: .rect(cornerRadius: 8, style: .continuous))
                .padding(16)
        }
        .background(fill)
        .clipShape(TicketHalf(top: true))
    }

    private func plate(_ text: String, symbol: String, colour: Color) -> some View {
        Label(text, systemImage: symbol).labelStyle(Tight())
            .font(.posterGrotesque(20)).foregroundStyle(colour)
            .padding(.leading, 8).padding(.trailing, 10).frame(height: 32)
            .background(Color(hex: 0xFFF8E6), in: .rect(cornerRadius: 8))
    }
}

/// A ticket's reasons (.tk-why): changes, walking, and how close it cuts it.
