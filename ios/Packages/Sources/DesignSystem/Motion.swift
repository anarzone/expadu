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
    /// The trip screen switching between its steps and its map.
    case tripMapSwitch = "trip.mapSwitch"

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
        case .tripMapSwitch:
            [
                MotionVariant("Morph", .smooth(duration: 0.55)),
                MotionVariant("Grow", .spring(duration: 0.55, bounce: 0.12)),
                MotionVariant("Slide up", .smooth(duration: 0.45)),
                MotionVariant("Fade", .easeInOut(duration: 0.3)),
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

/// Every button's press: it sinks a touch and settles back, smoothly, no bounce. The face's glass
/// (`buttonFace`) lights up under the finger on its own.
public struct PressStyle: ButtonStyle {
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Environment(\.isEnabled) private var enabled

    public init() {}

    public func makeBody(configuration: Configuration) -> some View {
        let down = configuration.isPressed && enabled
        configuration.label
            .scaleEffect(down && !reduceMotion ? 0.97 : 1)
            .animation(MotionStore.shared.animation(.press, reduceMotion: reduceMotion), value: down)
    }
}

/// An icon button as iOS 26 draws one: a rounded square of Liquid Glass whose corners follow the
/// card around it (concentric), tinted with its face colour. Pressed, the glass lights and flexes
/// and it sinks a touch, with a light tick.
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
            // Depth from Liquid Glass, tinted with the face's colour: lit at its edges, and it
            // flexes and glows under the finger. No drawn gradient, no drop shadow.
            .glassEffect(glass ? .regular.interactive() : .regular.tint(fill).interactive(), in: shape)
            .overlay { shape.fill(tint).allowsHitTesting(false) }
            .contentShape(shape)
            .scaleEffect(down && !reduceMotion ? 0.94 : 1)
            .animation(.easeOut(duration: down ? 0.12 : 0.22), value: down)
            .sensoryFeedback(.impact(weight: .light, intensity: 0.5), trigger: down) { _, now in now }
    }
}

extension View {
    /// A text button's face, iOS 26: Liquid Glass tinted with the button's colour in a rounded
    /// rectangle that follows its card. It catches light at its edges and flexes and glows under
    /// the finger, which is the button's depth; no drawn gradient, no drop shadow.
    public func buttonFace(_ color: Color, radius: CGFloat = 12) -> some View {
        glassEffect(.regular.tint(color).interactive(), in: .rect(cornerRadius: radius, style: .continuous))
    }
}
