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
