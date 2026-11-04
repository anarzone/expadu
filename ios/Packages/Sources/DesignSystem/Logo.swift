import SwiftUI

/// The mock's mark, drawn from its SVG (viewBox 13 22 74 60).
public struct ExpaduMark: View {
    public init() {}

    public var body: some View {
        let orange = Palette.brandOrange
        let cyan = Palette.brandCyan
        return Canvas { context, size in
            let scale = min(size.width / 74, size.height / 60)
            context.translateBy(x: (size.width - 74 * scale) / 2, y: (size.height - 60 * scale) / 2)
            context.scaleBy(x: scale, y: scale)
            context.translateBy(x: -13 + 2.45, y: -22 + 2.05)

            func bar(_ x: Double, _ y: Double, _ width: Double, degrees: Double, pivotY: Double) {
                var layer = context
                layer.translateBy(x: x, y: pivotY)
                layer.rotate(by: .degrees(degrees))
                layer.translateBy(x: -x, y: -pivotY)
                layer.fill(Path(roundedRect: CGRect(x: x, y: y, width: width, height: 13), cornerRadius: 6), with: .color(orange))
            }

            context.fill(Path(ellipseIn: CGRect(x: 14.5, y: 44, width: 12, height: 12)), with: .color(cyan))
            context.fill(Path(roundedRect: CGRect(x: 30, y: 24, width: 13, height: 52), cornerRadius: 6), with: .foreground)
            bar(46, 24, 34, degrees: -7, pivotY: 30)
            bar(46, 43.5, 25, degrees: -4, pivotY: 50)
            bar(46, 63, 34, degrees: -2, pivotY: 69)
        }
        .accessibilityHidden(true)
    }
}
