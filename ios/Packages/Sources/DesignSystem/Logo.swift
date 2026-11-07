import SwiftUI

/// The mock's mark, drawn from its SVG (viewBox 13 22 74 60). `arms` squeeze each orange bar from
/// its left end and `dot` lifts the cyan dot, for the wave.
public struct ExpaduMark: View {
    var arms: [Double] = [1, 1, 1]
    var dot: Double = 0

    public init() {}

    init(arms: [Double], dot: Double) {
        self.arms = arms
        self.dot = dot
    }

    public var body: some View {
        let orange = Palette.brandOrange
        let cyan = Palette.brandCyan
        let arms = arms
        let dot = dot
        return Canvas { context, size in
            let scale = min(size.width / 74, size.height / 60)
            context.translateBy(x: (size.width - 74 * scale) / 2, y: (size.height - 60 * scale) / 2)
            context.scaleBy(x: scale, y: scale)
            context.translateBy(x: -13 + 2.45, y: -22 + 2.05)

            func bar(_ x: Double, _ y: Double, _ width: Double, degrees: Double, pivotY: Double, squeeze: Double) {
                var layer = context
                layer.translateBy(x: x, y: pivotY)
                layer.rotate(by: .degrees(degrees))
                layer.translateBy(x: -x, y: -pivotY)
                layer.fill(Path(roundedRect: CGRect(x: x, y: y, width: max(13, width * squeeze), height: 13), cornerRadius: 6), with: .color(orange))
            }

            context.fill(Path(ellipseIn: CGRect(x: 14.5, y: 44 - dot, width: 12, height: 12)), with: .color(cyan))
            context.fill(Path(roundedRect: CGRect(x: 30, y: 24, width: 13, height: 52), cornerRadius: 6), with: .foreground)
            bar(46, 24, 34, degrees: -7, pivotY: 30, squeeze: arms[0])
            bar(46, 43.5, 25, degrees: -4, pivotY: 50, squeeze: arms[1])
            bar(46, 63, 34, degrees: -2, pivotY: 69, squeeze: arms[2])
        }
        .accessibilityHidden(true)
    }
}

/// The canvas's logo wave (today.css lgarm/lgdt): the three arms squeeze to 45% one after another,
/// 0.14 s apart, while the dot hops 9 units, over 1.1 s a cycle. Still when `since` is nil; `cycles`
/// nil waves until stopped (Checking your day…).
public struct WavingMark: View {
    let since: Date?
    let cycles: Double?
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    public init(since: Date?, cycles: Double? = 2) {
        self.since = since
        self.cycles = cycles
    }

    public var body: some View {
        TimelineView(.animation(paused: since == nil || reduceMotion)) { timeline in
            let t = since.map { timeline.date.timeIntervalSince($0) } ?? -1
            ExpaduMark(arms: [0, 0.14, 0.28].map { 1 - 0.55 * Self.bump(t - $0, cycles: cycles) }, dot: 9 * Self.bump(t, cycles: cycles))
        }
    }

    /// 0 → 1 → 0 over each 1.1 s cycle, eased; 0 before it starts and after the last cycle.
    static func bump(_ t: Double, cycles: Double?) -> Double {
        let u = t / 1.1
        guard u > 0, u < (cycles ?? .infinity) else { return 0 }
        return (1 - cos(2 * .pi * u.truncatingRemainder(dividingBy: 1))) / 2
    }
}
