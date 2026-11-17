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

/// A page pulled down to close, as a sheet is (for pages shown full screen, where the system's own
/// pull isn't there): the page follows the finger down, its top corners rounding as it lifts off what's
/// below; let go past a quarter of the way (or flicked) it goes on down and closes; short of that it
/// springs back. A light tick when it goes. The gesture lives where the page is held (a header, the
/// step); the page itself carries `pulledDown(_:)`.
public enum PullDown {
    /// A phone's page, tall enough to go off the bottom of any (a quarter of it, ~230 pt, closes).
    public static let page: CGFloat = 920

    /// While held: the page follows the finger down, never up past where it sits.
    public static func follow(_ dy: CGFloat) -> CGFloat { max(0, dy) }

    /// Let go: on down and closed, or back in place. True when it closes.
    @discardableResult
    public static func release(_ pull: Binding<CGFloat>, dy: CGFloat, predicted: CGFloat, height: CGFloat,
                               reduceMotion: Bool = false, close: @escaping () -> Void) -> Bool {
        let far = dy > height / 4 || (predicted > height / 2 && dy > 40)
        guard far else {
            withAnimation(.spring(duration: 0.35, bounce: 0.2)) { pull.wrappedValue = 0 }
            return false
        }
        withAnimation(reduceMotion ? nil : .smooth(duration: 0.25)) { pull.wrappedValue = height }
        // Off the bottom by now: closed without a second slide of its own.
        Task { @MainActor in
            try? await Task.sleep(for: .milliseconds(reduceMotion ? 0 : 240))
            var quiet = Transaction()
            quiet.disablesAnimations = true
            withTransaction(quiet) { close() }
            try? await Task.sleep(for: .milliseconds(400))
            pull.wrappedValue = 0
        }
        return true
    }
}

/// The page while it's pulled down: moved with the finger, top corners rounded, a shadow over what's below.
struct PulledDown: ViewModifier {
    let pull: CGFloat

    func body(content: Content) -> some View {
        content
            .mask {
                UnevenRoundedRectangle(topLeadingRadius: min(36, pull / 2), topTrailingRadius: min(36, pull / 2), style: .continuous)
                    .ignoresSafeArea()
            }
            .shadow(color: .black.opacity(pull > 0 ? 0.25 : 0), radius: 20, y: -4)
            .offset(y: pull)
    }
}

/// A swipe down on a page's header that pulls the whole page down to close (the page carries
/// `pulledDown(_:)` with the same binding). The header's taps still go to its buttons.
struct PullDownHandle: ViewModifier {
    @Binding var pull: CGFloat
    let close: () -> Void
    /// The page's height: a quarter of it closes, and it goes off by all of it.
    var height: CGFloat = PullDown.page
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var went = 0
    @State private var down: Bool?

    func body(content: Content) -> some View {
        content
            .contentShape(.rect)
            .simultaneousGesture(
                DragGesture(minimumDistance: 10, coordinateSpace: .global)
                    .onChanged { value in
                        if down == nil { down = value.translation.height > abs(value.translation.width) }
                        guard down == true else { return }
                        pull = PullDown.follow(value.translation.height)
                    }
                    .onEnded { value in
                        defer { down = nil }
                        guard down == true else { return }
                        if PullDown.release($pull, dy: value.translation.height, predicted: value.predictedEndTranslation.height,
                                            height: height, reduceMotion: reduceMotion, close: close) { went += 1 }
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

    /// A page's header: a swipe down on it pulls the page (which carries `pulledDown(_:)`) down to close.
    func pullDownHandle(_ pull: Binding<CGFloat>, close: @escaping () -> Void) -> some View {
        modifier(PullDownHandle(pull: pull, close: close))
    }

    /// The page, moved down as far as it's pulled.
    func pulledDown(_ pull: CGFloat) -> some View {
        modifier(PulledDown(pull: pull))
    }
}
