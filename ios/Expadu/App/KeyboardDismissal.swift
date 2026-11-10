import SwiftUI
import UIKit

/// A tap anywhere outside a text field puts the keyboard away, on every screen and sheet: one
/// recogniser on the window. It never takes the tap from what was tapped (buttons, rows and
/// sheets work as before), and a tap on another field moves the typing there instead.
struct KeyboardDismissal: UIViewRepresentable {
    func makeUIView(context: Context) -> Anchor { Anchor() }
    func updateUIView(_ view: Anchor, context: Context) {}

    final class Anchor: UIView, UIGestureRecognizerDelegate {
        private var installed: UITapGestureRecognizer?

        override init(frame: CGRect) {
            super.init(frame: frame)
            isUserInteractionEnabled = false
        }

        @available(*, unavailable)
        required init?(coder: NSCoder) { fatalError("init(coder:) is not used") }

        override func didMoveToWindow() {
            super.didMoveToWindow()
            guard let window, installed == nil else { return }
            let tap = UITapGestureRecognizer(target: self, action: #selector(tapped))
            tap.cancelsTouchesInView = false
            tap.requiresExclusiveTouchType = false
            tap.delegate = self
            window.addGestureRecognizer(tap)
            installed = tap
        }

        @objc private func tapped(_ tap: UITapGestureRecognizer) {
            tap.view?.endEditing(true)
        }

        /// Not for a tap on a text field (it takes the typing) or on the keyboard's own bar.
        func gestureRecognizer(_ gesture: UIGestureRecognizer, shouldReceive touch: UITouch) -> Bool {
            var view = touch.view
            while let current = view {
                if current is UITextField || current is UITextView { return false }
                view = current.superview
            }
            return true
        }

        /// Alongside every other gesture: scrolling, swipes and buttons are unaffected.
        func gestureRecognizer(_ gesture: UIGestureRecognizer, shouldRecognizeSimultaneouslyWith other: UIGestureRecognizer) -> Bool {
            true
        }
    }
}
