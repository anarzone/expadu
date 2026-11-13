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

/// A ticket's reasons (.tk-why): changes, walking, and how close it cuts it.
