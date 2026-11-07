import ActivityKit
import Core
import DesignSystem
import SwiftUI
import WidgetKit

@main
struct ExpaduWidgets: WidgetBundle {
    init() {
        Typeface.register()
    }

    var body: some Widget {
        TripLiveActivity()
    }
}

/// The trip on the Lock Screen and in the Dynamic Island, phase by phase (TripPhase): before
/// leaving, walking, waiting, riding, getting off, gone wrong, there.
struct TripLiveActivity: Widget {
    var body: some WidgetConfiguration {
        ActivityConfiguration(for: TripActivityAttributes.self) { context in
            LockCard(to: context.attributes.to, phase: context.state)
                .activityBackgroundTint(Color(light: 0xFFFDF8, dark: 0x26251C))
                .activitySystemActionForegroundColor(Palette.text)
                .widgetURL(URL(string: "expadu://today"))
        } dynamicIsland: { context in
            let phase = context.state
            return DynamicIsland {
                DynamicIslandExpandedRegion(.leading) {
                    Mark(phase: phase, size: .large).padding(.leading, 4)
                }
                DynamicIslandExpandedRegion(.trailing) {
                    Clock(phase: phase, size: 30).padding(.trailing, 4)
                }
                DynamicIslandExpandedRegion(.center) {
                    VStack(spacing: 2) {
                        Text(phase.title).font(.geist(16, .semibold)).lineLimit(2).multilineTextAlignment(.center)
                        Text(phase.detail).font(.geist(13)).foregroundStyle(.secondary).lineLimit(1)
                    }
                }
                DynamicIslandExpandedRegion(.bottom) {
                    VStack(spacing: 10) {
                        if phase.steps > 0 { Bars(phase: phase, dark: true) }
                        Action(phase: phase)
                    }
                    .padding(.top, 4)
                }
            } compactLeading: {
                Mark(phase: phase, size: .compact)
            } compactTrailing: {
                Clock(phase: phase, size: 15).frame(maxWidth: 52)
            } minimal: {
                Mark(phase: phase, size: .minimal)
            }
            .keylineTint(phase.color.map { Color(hex: Self.hex($0)) } ?? Palette.action)
            .widgetURL(URL(string: "expadu://today"))
        }
    }

    static func hex(_ text: String) -> UInt32 {
        UInt32(text.trimmingCharacters(in: CharacterSet(charactersIn: "#")), radix: 16) ?? 0x1A4CD4
    }
}

/// The line's badge on its colour, or the step's symbol (a turn, walking, a tick).
private struct Mark: View {
    enum Size { case compact, minimal, large }
    let phase: TripPhase
    let size: Size

    var body: some View {
        if let badge = phase.badge, let color = phase.color {
            let look = LineLook(hex: color)
            Text(badge).font(.geist(size == .large ? 20 : 13, .bold)).foregroundStyle(look.ink)
                .minimumScaleFactor(0.6).lineLimit(1)
                .padding(.horizontal, size == .large ? 9 : 5)
                .frame(minWidth: size == .large ? 44 : 24, minHeight: size == .large ? 36 : 22)
                .background(look.fill, in: .rect(cornerRadius: size == .large ? 9 : 6))
        } else {
            Image(systemName: phase.symbol).font(.system(size: size == .large ? 26 : 15, weight: .bold))
                .foregroundStyle(tint)
                .frame(minWidth: size == .large ? 44 : 22)
        }
    }

    private var tint: Color {
        switch phase.kind {
        case .trouble: Color(hex: 0xF2A516)
        case .arrived: Color(hex: 0x34C759)
        case .leave: Color(hex: 0xFF5A36)
        default: .white
        }
    }
}

/// The countdown (it runs by itself, no update needed) or the short word: "80 m", "3 stops", "There".
private struct Clock: View {
    let phase: TripPhase
    let size: CGFloat

    var body: some View {
        Group {
            if let until = phase.until, until > .now, phase.kind == .leave || phase.kind == .wait {
                Text(timerInterval: Date.now...until, countsDown: true, showsHours: false)
            } else {
                Text(phase.short)
            }
        }
        .font(.posterGrotesque(size)).monospacedDigit()
        .foregroundStyle(phase.kind == .trouble ? Color(hex: 0xF2A516) : phase.kind == .leave ? Color(hex: 0xFF5A36) : .white)
        .multilineTextAlignment(.trailing)
        .lineLimit(1).minimumScaleFactor(0.6)
    }
}

/// The trip's steps as bars: done ones full, the one now half.
private struct Bars: View {
    let phase: TripPhase
    let dark: Bool

    var body: some View {
        HStack(spacing: 4) {
            ForEach(0..<phase.steps, id: \.self) { index in
                Capsule()
                    .fill(index < phase.current ? (dark ? Color.white : Palette.text) : index == phase.current ? Palette.action : (dark ? Color.white.opacity(0.2) : Palette.quiet))
                    .frame(height: 5)
            }
        }
    }
}

/// What can be done from here: start the trip before leaving, a new way when it's gone wrong.
private struct Action: View {
    let phase: TripPhase

    var body: some View {
        switch phase.kind {
        case .leave:
            Link(destination: URL(string: "expadu://trip/start")!) {
                Label("I’m on my way", systemImage: "figure.walk").font(.geist(15, .semibold)).foregroundStyle(.white)
                    .frame(maxWidth: .infinity).frame(height: 40)
                    .background(Palette.action, in: .capsule)
            }
        case .trouble:
            Link(destination: URL(string: "expadu://trip/new-way")!) {
                Label("New way from here", systemImage: "arrow.triangle.turn.up.right.diamond.fill").font(.geist(15, .semibold)).foregroundStyle(.black)
                    .frame(maxWidth: .infinity).frame(height: 40)
                    .background(Color(hex: 0xF2A516), in: .capsule)
            }
        default:
            EmptyView()
        }
    }
}

/// The Lock Screen: the live card, small.
private struct LockCard: View {
    let to: String
    let phase: TripPhase

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 6) {
                Circle().fill(phase.kind == .leave ? Palette.action : Color(hex: 0x2F9E5B)).frame(width: 7, height: 7)
                Text(phase.kind == .leave ? "Time to go · \(to)" : "On your way · \(to)").font(.geist(13, .bold))
                    .foregroundStyle(phase.kind == .leave ? Palette.action : Color(hex: 0x2F9E5B)).lineLimit(1)
                Spacer(minLength: 4)
                if !phase.arrive.isEmpty {
                    Text("There \(phase.arrive)").font(.geist(13, .semibold)).monospacedDigit().foregroundStyle(Palette.textSoft)
                }
            }
            if phase.steps > 0 { Bars(phase: phase, dark: false) }
            HStack(alignment: .center, spacing: 12) {
                LockMark(phase: phase)
                VStack(alignment: .leading, spacing: 2) {
                    Text(phase.title).font(.geist(17, .bold)).foregroundStyle(Palette.text).lineLimit(2)
                    Text(phase.detail).font(.geist(13.5)).foregroundStyle(Palette.textSoft).lineLimit(1)
                }
                Spacer(minLength: 4)
                Group {
                    if let until = phase.until, until > .now, phase.kind == .leave || phase.kind == .wait {
                        Text(timerInterval: Date.now...until, countsDown: true, showsHours: false)
                    } else {
                        Text(phase.short)
                    }
                }
                .font(.posterGrotesque(28)).monospacedDigit()
                .foregroundStyle(phase.kind == .trouble ? Palette.warnText : phase.kind == .leave ? Palette.action : Palette.text)
                .frame(maxWidth: 90, alignment: .trailing).lineLimit(1).minimumScaleFactor(0.6)
            }
        }
        .padding(16)
    }
}

private struct LockMark: View {
    let phase: TripPhase

    var body: some View {
        if let badge = phase.badge, let color = phase.color {
            let look = LineLook(hex: color)
            Text(badge).font(.geist(17, .bold)).foregroundStyle(look.ink).lineLimit(1).minimumScaleFactor(0.6)
                .frame(width: 40, height: 40).background(look.fill, in: .rect(cornerRadius: 10))
        } else {
            Image(systemName: phase.symbol).font(.system(size: 18, weight: .bold)).foregroundStyle(.white)
                .frame(width: 40, height: 40)
                .background(phase.kind == .trouble ? Color(hex: 0xC98208) : phase.kind == .arrived ? Color(hex: 0x2F9E5B) : phase.kind == .leave ? Palette.action : Color(hex: 0x6A6253),
                            in: .rect(cornerRadius: 10))
        }
    }
}
