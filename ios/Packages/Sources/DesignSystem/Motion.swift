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
                MotionVariant("Sink", .easeOut(duration: 0.16)),
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

/// Every button's press: it sinks a touch and dims, then settles back, smoothly, no bounce.
public struct PressStyle: ButtonStyle {
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Environment(\.isEnabled) private var enabled

    public init() {}

    public func makeBody(configuration: Configuration) -> some View {
        let down = configuration.isPressed && enabled
        configuration.label
            .scaleEffect(down && !reduceMotion ? 0.96 : 1)
            .opacity(down ? 0.8 : 1)
            .animation(MotionStore.shared.animation(.press, reduceMotion: reduceMotion), value: down)
    }
}

/// An icon button as iOS 26 draws one in content: a flat rounded square whose corners follow the
/// card around it (concentric), no dome and no shadow. Pressed, it sinks a touch and dims, with a
/// light tick. Floating and toolbar buttons use the system's own Liquid Glass instead.
public struct IconTileStyle: ButtonStyle {
    private let fill: Color
    private let tint: Color
    private let size: CGFloat
    private let glass: Bool
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Environment(\.isEnabled) private var enabled

    /// `fill` is the face; `tint` washes over it (an added plan's green).
    public init(fill: Color, tint: Color = .clear, size: CGFloat = 44) {
        self.fill = fill
        self.tint = tint
        self.size = size
        glass = false
    }

    /// Floating over a map or a picture: the system's Liquid Glass in the same shape.
    public init(glassSize size: CGFloat) {
        fill = .clear
        tint = .clear
        self.size = size
        glass = true
    }

    public func makeBody(configuration: Configuration) -> some View {
        let down = configuration.isPressed && enabled
        let shape = RoundedRectangle(cornerRadius: (size * 0.3).rounded(), style: .continuous)
        configuration.label
            .frame(width: size, height: size)
            .background {
                if !glass {
                    // A hairline keeps a light face apart from a light page, with no shadow.
                    shape.fill(fill).overlay { shape.fill(tint) }.overlay { shape.strokeBorder(.primary.opacity(0.08), lineWidth: 0.5) }
                }
            }
            .glassEffect(glass ? .regular.interactive() : .identity, in: shape)
            .contentShape(shape)
            .scaleEffect(down && !reduceMotion ? 0.94 : 1)
            .opacity(down ? 0.75 : 1)
            .animation(.easeOut(duration: down ? 0.12 : 0.22), value: down)
            .sensoryFeedback(.impact(weight: .light, intensity: 0.5), trigger: down) { _, now in now }
    }
}
