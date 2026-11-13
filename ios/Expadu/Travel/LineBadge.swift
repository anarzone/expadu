import Core
import DesignSystem
import SwiftUI

/// A line as the app shows it everywhere (the board's sheets, routes, steps, the trip card and screen,
/// Today, Composer): its number as the stop signs write it ("RE 1", never "(RRX)"), in its own colour,
/// one shape at every size. `icon` puts the mode's symbol before the number (the route strip);
/// `onColour` draws it white with the line's colour as ink, for a badge on its own line's colour.
struct LineBadge: View {
    let line: String
    let color: String?
    var height: CGFloat = 28
    var mode: String? = nil
    var icon = false
    var onColour = false

    var body: some View {
        let look = LineLook(hex: color ?? "#1A4CD4")
        HStack(spacing: height * 0.12) {
            if icon {
                Image(systemName: Self.symbol(mode)).font(.system(size: height * 0.46, weight: .bold))
            }
            Text(Board.badge(line)).font(.geist(height * 0.56, .bold)).monospacedDigit()
                .lineLimit(1).minimumScaleFactor(0.6)
        }
        .foregroundStyle(onColour ? look.deep : look.ink)
        .padding(.horizontal, height * 0.26).frame(minWidth: height * 1.15).frame(height: height)
        .background(onColour ? AnyShapeStyle(Color.white) : AnyShapeStyle(look.base), in: .rect(cornerRadius: height * 0.25))
        .fixedSize()
        .accessibilityLabel("Line \(Board.badge(line))")
    }

    static func symbol(_ mode: String?) -> String {
        switch mode {
        case "bus": "bus.fill"
        case "rail", "regional_rail", "suburban", "train": "train.side.front.car"
        default: "tram.fill"
        }
    }
}
