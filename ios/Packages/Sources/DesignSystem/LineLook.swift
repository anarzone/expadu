import SwiftUI
import UIKit

/// A transit line's look: KVB's official colour as the base (owner, 7 Nov 2026), drawn as a
/// gradient from a lighter tint into a deeper, richer shade. Light pastels (3, 4, 17) deepen
/// further so white type still reads on them; the deep shade is for rings, route lines and type
/// on cream.
public struct LineLook: Sendable {
    public let base: Color
    public let light: Color
    public let deep: Color
    /// Type on the gradient: white, or the ink on the few that stay light.
    public let ink: Color

    public init(hex: String) {
        let value = UInt32(hex.trimmingCharacters(in: CharacterSet(charactersIn: "#")), radix: 16) ?? 0x1A4CD4
        let colour = UIColor(red: CGFloat((value >> 16) & 0xFF) / 255, green: CGFloat((value >> 8) & 0xFF) / 255, blue: CGFloat(value & 0xFF) / 255, alpha: 1)
        var hue: CGFloat = 0, saturation: CGFloat = 0, brightness: CGFloat = 0, alpha: CGFloat = 0
        colour.getHue(&hue, saturation: &saturation, brightness: &brightness, alpha: &alpha)
        let lightness = Self.luminance(colour)
        // Pastels go deeper and richer; strong colours only a little.
        let pastel = lightness > 0.55
        let deepen: CGFloat = pastel ? 0.6 : lightness > 0.4 ? 0.74 : 0.8
        let enrich: CGFloat = pastel ? 1.55 : 1.15
        // A pastel starts at its own colour, a little richer, so white type reads from the first letter.
        let lightUI = pastel
            ? UIColor(hue: Self.turn(hue, by: -0.008), saturation: min(1, saturation * 1.15), brightness: brightness * 0.94, alpha: 1)
            : UIColor(hue: Self.turn(hue, by: -0.012), saturation: saturation * 0.82, brightness: min(1, brightness * 1.08 + 0.02), alpha: 1)
        let deepUI = UIColor(hue: Self.turn(hue, by: 0.018), saturation: min(1, saturation * enrich), brightness: brightness * deepen, alpha: 1)
        base = Color(uiColor: colour)
        light = Color(uiColor: lightUI)
        deep = Color(uiColor: deepUI)
        // Judged on the gradient's middle, where most of the type sits.
        let middle = (Self.luminance(lightUI) + Self.luminance(deepUI)) / 2
        ink = middle > 0.6 ? Color(red: 0x1D / 255, green: 0x1A / 255, blue: 0x14 / 255) : .white
    }

    /// Lighter at the top leading corner, deeper at the bottom trailing one.
    public var fill: LinearGradient {
        LinearGradient(colors: [light, base, deep], startPoint: .topLeading, endPoint: .bottomTrailing)
    }

    /// A change of lines: the first line's tint running into the second's shade.
    public static func fill(from first: LineLook, to second: LineLook) -> LinearGradient {
        LinearGradient(stops: [.init(color: first.light, location: 0), .init(color: first.base, location: 0.3), .init(color: second.deep, location: 1)],
                       startPoint: .leading, endPoint: .trailing)
    }

    private static func turn(_ hue: CGFloat, by amount: CGFloat) -> CGFloat {
        let turned = hue + amount
        return turned < 0 ? turned + 1 : turned > 1 ? turned - 1 : turned
    }

    private static func luminance(_ colour: UIColor) -> CGFloat {
        var red: CGFloat = 0, green: CGFloat = 0, blue: CGFloat = 0, alpha: CGFloat = 0
        colour.getRed(&red, green: &green, blue: &blue, alpha: &alpha)
        return 0.2126 * red + 0.7152 * green + 0.0722 * blue
    }
}
