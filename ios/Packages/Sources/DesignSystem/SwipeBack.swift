import SwiftUI

/// Back by swiping from the left edge, as iOS's own screens go back: the page follows the finger with
/// a soft shadow on its edge, and let go past a third of the way (or flicked) it goes; short of that it
/// springs back. For pages shown full screen, where the system's own back swipe isn't there. `progress`
/// (0 to 1, how far the page has gone) lets the page below show through as it does on iOS. A light
/// tick when it goes.
struct SwipeBack: ViewModifier {
    let enabled: Bool
    var progress: Binding<CGFloat>?
    let action: () -> Void
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var offset: CGFloat = 0
    @State private var width: CGFloat = 400
    @State private var went = 0

    /// Where a back swipe starts: this close to the left edge.
    private static let edge: CGFloat = 22

    func body(content: Content) -> some View {
        content
            // The edge owns the swipe: a strip along it, over a map or a list that would take the drag.
            .overlay(alignment: .leading) {
                if enabled {
                    Color.clear
                        .frame(width: Self.edge)
                        .contentShape(.rect)
                        .gesture(drag)
                        .accessibilityHidden(true)
                }
            }
            // The page's edge, lifted off what's below while it moves.
            .background(alignment: .leading) {
                LinearGradient(colors: [.clear, .black.opacity(0.12)], startPoint: .leading, endPoint: .trailing)
                    .frame(width: 16)
                    .offset(x: -16)
                    .ignoresSafeArea()
                    .opacity(offset > 0 ? 1 : 0)
                    .allowsHitTesting(false)
            }
            .offset(x: offset)
            .onGeometryChange(for: CGFloat.self) { $0.size.width } action: { width = $0 }
            .sensoryFeedback(.impact(weight: .light), trigger: went)
    }

    private var drag: some Gesture {
        DragGesture(minimumDistance: 8, coordinateSpace: .global)
            .onChanged { value in
                offset = reduceMotion ? 0 : max(0, value.translation.width)
                progress?.wrappedValue = min(1, max(0, value.translation.width) / max(width, 1))
            }
            .onEnded { value in
                let far = value.translation.width > width / 3 || value.predictedEndTranslation.width > width * 0.6
                if far, value.translation.width > abs(value.translation.height) {
                    went += 1
                    withAnimation(.smooth(duration: 0.25)) {
                        offset = reduceMotion ? 0 : width
                        progress?.wrappedValue = 1
                    }
                    action()
                    // Ready for the next time the page shows.
                    Task { @MainActor in
                        try? await Task.sleep(for: .milliseconds(400))
                        offset = 0
                        progress?.wrappedValue = 0
                    }
                } else {
                    // The page below stays (only just) until this one covers it again.
                    withAnimation(.spring(duration: 0.3, bounce: 0.2)) {
                        offset = 0
                        progress?.wrappedValue = 0.001
                    } completion: {
                        progress?.wrappedValue = 0
                    }
                }
            }
    }
}

/// A swipe down that closes: on a page's header, as a sheet closes. A light tick when it goes.
struct SwipeDownClose: ViewModifier {
    let action: () -> Void
    @State private var went = 0

    func body(content: Content) -> some View {
        content
            .contentShape(.rect)
            .simultaneousGesture(
                DragGesture(minimumDistance: 16)
                    .onEnded { value in
                        guard value.translation.height > 80, value.translation.height > abs(value.translation.width) * 1.5 else { return }
                        went += 1
                        action()
                    }
            )
            .sensoryFeedback(.impact(weight: .light), trigger: went)
    }
}

public extension View {
    /// Back with a swipe from the left edge (the page follows the finger), when `enabled`. `progress`
    /// follows how far the page has gone (0 to 1), for drawing the page below.
    func swipeBack(enabled: Bool = true, progress: Binding<CGFloat>? = nil, _ action: @escaping () -> Void) -> some View {
        modifier(SwipeBack(enabled: enabled, progress: progress, action: action))
    }

    /// Closed with a swipe down on this (a page's header).
    func swipeDownToClose(_ action: @escaping () -> Void) -> some View {
        modifier(SwipeDownClose(action: action))
    }
}
