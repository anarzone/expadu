import DesignSystem
import SwiftUI
import UIKit

/*
 * Things that move forever (the Composer ring, its glow, a ring round "Ask Composer", the pulse
 * on Your day) are Core Animation layers: the system's render server turns them, so the app
 * draws nothing per frame. Driven from SwiftUI (a TimelineView, a repeating animation) they
 * re-ran the view every frame on every screen and made scrolling lag.
 */

/// A picture that turns a full circle every `period` seconds; 0 holds it still.
struct TurningImage: UIViewRepresentable {
    let image: UIImage
    let period: Double

    func makeUIView(context: Context) -> UIImageView {
        let view = UIImageView(image: image)
        view.contentMode = .scaleToFill
        view.isUserInteractionEnabled = false
        view.setContentHuggingPriority(.defaultLow, for: .horizontal)
        view.setContentHuggingPriority(.defaultLow, for: .vertical)
        view.setContentCompressionResistancePriority(.defaultLow, for: .horizontal)
        view.setContentCompressionResistancePriority(.defaultLow, for: .vertical)
        Turn.apply(period, to: view.layer)
        return view
    }

    func updateUIView(_ view: UIImageView, context: Context) {
        if view.image !== image { view.image = image }
        Turn.apply(period, to: view.layer)
    }
}

/// A capsule's border in Composer's colours, turning every `period` seconds (0: still).
struct TurningCapsuleBorder: UIViewRepresentable {
    var lineWidth: CGFloat = 2.5
    let period: Double

    func makeUIView(context: Context) -> BorderView { BorderView(lineWidth: lineWidth) }

    func updateUIView(_ view: BorderView, context: Context) {
        view.period = period
        view.setNeedsLayout()
    }

    final class BorderView: UIView {
        private let gradient = CAGradientLayer()
        private let stroke = CAShapeLayer()
        private let lineWidth: CGFloat
        var period: Double = 12

        init(lineWidth: CGFloat) {
            self.lineWidth = lineWidth
            super.init(frame: .zero)
            isUserInteractionEnabled = false
            backgroundColor = .clear
            gradient.type = .conic
            gradient.startPoint = CGPoint(x: 0.5, y: 0.5)
            gradient.endPoint = CGPoint(x: 1, y: 0.5)
            gradient.colors = ComposerLook.stops.map { UIColor($0.color).cgColor }
            gradient.locations = ComposerLook.stops.map { NSNumber(value: Double($0.location)) }
            layer.addSublayer(gradient)
            stroke.fillColor = nil
            stroke.strokeColor = UIColor.black.cgColor
            stroke.lineWidth = lineWidth
            layer.mask = stroke
        }

        @available(*, unavailable)
        required init?(coder: NSCoder) { fatalError("init(coder:) is not used") }

        override func layoutSubviews() {
            super.layoutSubviews()
            CATransaction.begin()
            CATransaction.setDisableActions(true)
            // A square gradient as wide as the capsule's diagonal turns inside it; the stroke shows its edge.
            let side = hypot(bounds.width, bounds.height)
            gradient.bounds = CGRect(x: 0, y: 0, width: side, height: side)
            gradient.position = CGPoint(x: bounds.midX, y: bounds.midY)
            stroke.frame = bounds
            let inset = bounds.insetBy(dx: lineWidth / 2, dy: lineWidth / 2)
            stroke.path = UIBezierPath(roundedRect: inset, cornerRadius: inset.height / 2).cgPath
            CATransaction.commit()
            Turn.apply(period, to: gradient)
        }
    }
}

/// The dot on Your day's line where now is, with its ring growing and fading every 1.8 s.
struct PulseRing: UIViewRepresentable {
    let colour: Color
    let animated: Bool

    func makeUIView(context: Context) -> RingView { RingView() }

    func updateUIView(_ view: RingView, context: Context) {
        view.colour = UIColor(colour)
        view.animated = animated
        view.setNeedsLayout()
    }

    final class RingView: UIView {
        private let ring = CALayer()
        var colour: UIColor = .systemRed
        var animated = true

        override init(frame: CGRect) {
            super.init(frame: frame)
            isUserInteractionEnabled = false
            layer.addSublayer(ring)
        }

        @available(*, unavailable)
        required init?(coder: NSCoder) { fatalError("init(coder:) is not used") }

        override func layoutSubviews() {
            super.layoutSubviews()
            CATransaction.begin()
            CATransaction.setDisableActions(true)
            ring.frame = bounds
            ring.cornerRadius = bounds.width / 2
            ring.backgroundColor = colour.withAlphaComponent(0.35).cgColor
            CATransaction.commit()
            guard animated else { ring.removeAllAnimations(); return }
            guard ring.animation(forKey: "pulse") == nil else { return }
            let grow = CABasicAnimation(keyPath: "transform.scale")
            grow.fromValue = 1
            grow.toValue = 2.2
            let fade = CABasicAnimation(keyPath: "opacity")
            fade.fromValue = 1
            fade.toValue = 0
            let pulse = CAAnimationGroup()
            pulse.animations = [grow, fade]
            pulse.duration = 1.8
            pulse.timingFunction = CAMediaTimingFunction(name: .easeOut)
            pulse.repeatCount = .infinity
            pulse.isRemovedOnCompletion = false
            ring.add(pulse, forKey: "pulse")
        }
    }
}

private enum Turn {
    static let key = "turn"

    /// Starts, changes speed or stops the turn, carrying on from where it is now.
    static func apply(_ period: Double, to layer: CALayer) {
        let running = layer.animation(forKey: key)
        guard period > 0 else {
            if running != nil { layer.removeAnimation(forKey: key) }
            return
        }
        if let running, abs(running.duration - period) < 0.01 { return }
        let now = (layer.presentation()?.value(forKeyPath: "transform.rotation.z") as? Double) ?? 0
        let turn = CABasicAnimation(keyPath: "transform.rotation.z")
        turn.fromValue = now
        turn.toValue = now + 2 * .pi
        turn.duration = period
        turn.repeatCount = .infinity
        // Kept when the app goes to the background, so it turns again on return.
        turn.isRemovedOnCompletion = false
        layer.add(turn, forKey: key)
    }
}
