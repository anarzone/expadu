import Observation
import SwiftUI

/// One named way something moves. "Mock" is what the mock does; the others are there to play with
/// in the lab. Picking one deletes the rest.
public struct MotionVariant: Identifiable, Sendable {
    public let id: String
    public let animation: Animation?

    public init(_ id: String, _ animation: Animation?) {
        self.id = id
        self.animation = animation
    }
}

/// Views name what is happening, never a duration or a curve.
public enum MotionToken: String, CaseIterable, Identifiable, Codable, Sendable {
    case posterPaste = "poster.paste"
    case navSelect = "nav.select"
    case press = "press"

    public var id: String { rawValue }

    public var variants: [MotionVariant] {
        switch self {
        case .posterPaste:
            [
                MotionVariant("Mock", .timingCurve(0.2, 0.8, 0.2, 1, duration: 0.28)),
                MotionVariant("No bounce", .spring(duration: 0.35, bounce: 0)),
                MotionVariant("Light bounce", .spring(duration: 0.45, bounce: 0.15)),
            ]
        case .navSelect:
            [
                MotionVariant("Mock", nil),
                MotionVariant("Slide", .snappy(duration: 0.25)),
                MotionVariant("Slide, bounce", .spring(duration: 0.35, bounce: 0.15)),
            ]
        case .press:
            [
                MotionVariant("Mock", .timingCurve(0.22, 1, 0.36, 1, duration: 0.16)),
                MotionVariant("Off", nil),
            ]
        }
    }
}

/// Which variant each token plays. The lab changes it; everything else only reads it.
@Observable
public final class MotionStore {
    public static let shared = MotionStore()

    public private(set) var chosen: [MotionToken: String] = [:]
    public var slow = false
    public private(set) var replays: [MotionToken: Int] = [:]

    public func variant(of token: MotionToken) -> MotionVariant {
        let id = chosen[token]
        return token.variants.first { $0.id == id } ?? token.variants[0]
    }

    public func choose(_ id: String, for token: MotionToken) {
        chosen[token] = id
    }

    public func replay(_ token: MotionToken) {
        replays[token, default: 0] += 1
    }

    public func animation(_ token: MotionToken, reduceMotion: Bool) -> Animation? {
        guard !reduceMotion, let animation = variant(of: token).animation else { return nil }
        return slow ? animation.speed(0.25) : animation
    }
}

/// The mock's press: 0.97, on the settle curve.
public struct PressStyle: ButtonStyle {
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    public init() {}

    public func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .scaleEffect(configuration.isPressed && !reduceMotion ? 0.97 : 1)
            .animation(MotionStore.shared.animation(.press, reduceMotion: reduceMotion), value: configuration.isPressed)
    }
}

/// A round icon button with a little volume: a domed face lit from above on a soft shadow. Pressed,
/// it sinks: a touch smaller, the shadow pulled in, the light dimmed, with a light tick. No bounce.
public struct RaisedStyle: ButtonStyle {
    private let fill: Color
    private let tint: Color
    private let size: CGFloat
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Environment(\.colorScheme) private var scheme
    @Environment(\.isEnabled) private var enabled

    /// `fill` is the face; `tint` washes over it (an added plan's green).
    public init(fill: Color, tint: Color = .clear, size: CGFloat = 44) {
        self.fill = fill
        self.tint = tint
        self.size = size
    }

    public func makeBody(configuration: Configuration) -> some View {
        let down = configuration.isPressed && enabled
        let dark = scheme == .dark
        configuration.label
            .frame(width: size, height: size)
            .background {
                Circle().fill(fill)
                    .overlay { Circle().fill(tint) }
                    // The dome: light from the top, a little shade at the foot.
                    .overlay {
                        Circle().fill(LinearGradient(colors: [.white.opacity(dark ? 0.10 : 0.55), .clear, .black.opacity(dark ? 0.18 : 0.05)],
                                                     startPoint: .top, endPoint: .bottom))
                            .opacity(down ? 0.35 : 1)
                    }
                    .overlay { Circle().strokeBorder(.black.opacity(dark ? 0.35 : 0.07), lineWidth: 0.5) }
                    .shadow(color: .black.opacity(dark ? 0.45 : (down ? 0.06 : 0.13)), radius: down ? 1 : 4, y: down ? 0.5 : 2.5)
            }
            .contentShape(.circle)
            .scaleEffect(down && !reduceMotion ? 0.92 : 1)
            .animation(.easeOut(duration: down ? 0.12 : 0.22), value: down)
            .sensoryFeedback(.impact(weight: .light, intensity: 0.5), trigger: down) { _, now in now }
    }
}
