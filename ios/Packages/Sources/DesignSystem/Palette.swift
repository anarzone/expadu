import SwiftUI
#if canImport(UIKit)
import UIKit
#endif

/// The mock's tokens (`prototype/dev/design/connected/tokens.css`) with the app's dark values
/// (`resources/css/app.css`). Views use these names, never raw hex.
public enum Palette {
    public static let canvas = Color(light: 0xFAF6EE, dark: 0x18170F)
    public static let surface = Color(light: 0xFFFDF8, dark: 0x26251C)
    public static let quiet = Color(light: 0xF1EBDF, dark: 0x322F25)
    public static let header = Color(light: 0xFFFDF8, dark: 0x1B1A12)
    public static let border = Color(light: 0xE6DDCB, dark: 0x413F34)
    public static let borderStrong = Color(light: 0x8A8277, dark: 0x8A887C)
    public static let text = Color(light: 0x211D15, dark: 0xF6F5F1)
    public static let textSoft = Color(light: 0x6A6253, dark: 0xC9C7BD)
    public static let onAction = Color(hex: 0xFFFFFF)
    public static let action = Color(light: 0xC23B0E, dark: 0xCC4512)
    public static let actionPressed = Color(light: 0xA9320B, dark: 0xBC3E0E)
    public static let selectBackground = Color(light: 0xDEF5F8, dark: 0x14303A)
    public static let focus = Color(light: 0x00768B, dark: 0x5CCBE0)
    public static let conflictBackground = Color(light: 0xFFF0EB, dark: 0x2F1610)
    public static let conflictBorder = Color(light: 0xF2B8A8, dark: 0x6E2F1D)
    public static let conflictText = Color(light: 0xA2300E, dark: 0xFF9E80)
    /// The canvas's --warn pair: tight, not wrong.
    public static let warnBackground = Color(light: 0xFFF1D6, dark: 0x3A2A0C)
    public static let warnText = Color(light: 0x694207, dark: 0xF2C46B)
    /// Good news: plenty of time, a step done, a way kept (the canvas's --good pair).
    public static let goodBackground = Color(light: 0xE4F4E8, dark: 0x1F3A28)
    public static let goodText = Color(light: 0x17603A, dark: 0x86E2AA)
    /// Something now on your day (✓ In your plan), in the action's colour.
    public static let addedBackground = Color(light: 0xFBE2D4, dark: 0x3D211A)
    public static let addedText = Color(light: 0xA9320B, dark: 0xFF8D72)
    /// A notice pinned over a coloured board (Travel's KVB banner): cream by day, deep amber at night.
    public static let noticeBackground = Color(light: 0xFFF8E6, dark: 0x33281A)
    public static let noticeText = Color(light: 0x211D15, dark: 0xFFE6B8)
    /// The canvas's What to bring (--bring, --bring-k).
    public static let bringBackground = Color(light: 0xEFEAFC, dark: 0x2A2338)
    public static let bringText = Color(light: 0x4E3774, dark: 0xCDB3F4)

    public static let navTeal = Color(light: 0x00768B, dark: 0x5CCBE0)
    public static let navRust = Color(light: 0xB94B18, dark: 0xFF8A5B)
    public static let launcher = Color(light: 0x00768B, dark: 0x0A7F94)
    public static let avatarBackground = Color(light: 0xEAE4F7, dark: 0x2A2338)
    public static let avatarText = Color(light: 0x4E3774, dark: 0xCDB3F4)

    public static let brandOrange = Color(hex: 0xFF3902)
    public static let brandCyan = Color(hex: 0x05BADD)

    // The street and its poster are print, so they stay light in dark mode.
    public static let posterYellow = Color(hex: 0xFFE363)
    public static let posterPurple = Color(hex: 0x4B2BB8)
    public static let posterPaper = Color(hex: 0xFFF8E6)
    public static let street = Color(hex: 0xE7DBC2)
    public static let posterLead = Color(hex: 0x4A4335)
    public static let posterRule = Color(hex: 0xD8CBB0)
}

extension Color {
    public init(hex: UInt32, opacity: Double = 1) {
        self.init(
            .sRGB,
            red: Double((hex >> 16) & 0xFF) / 255,
            green: Double((hex >> 8) & 0xFF) / 255,
            blue: Double(hex & 0xFF) / 255,
            opacity: opacity
        )
    }

    public init(light: UInt32, dark: UInt32) {
        #if canImport(UIKit)
        self.init(uiColor: Self.dynamic(light: light, dark: dark))
        #else
        self.init(hex: light)
        #endif
    }

    #if canImport(UIKit)
    /// UIKit asks for the colour off the main thread too (SwiftUI resolves colours while it
    /// animates), so the provider must not belong to the main actor.
    nonisolated private static func dynamic(light: UInt32, dark: UInt32) -> UIColor {
        UIColor { traits in rgb(traits.userInterfaceStyle == .dark ? dark : light) }
    }

    nonisolated private static func rgb(_ hex: UInt32) -> UIColor {
        UIColor(red: CGFloat((hex >> 16) & 0xFF) / 255, green: CGFloat((hex >> 8) & 0xFF) / 255, blue: CGFloat(hex & 0xFF) / 255, alpha: 1)
    }
    #endif
}

extension View {
    /// CSS's `box-shadow`, spread included, which SwiftUI's own shadow can't draw.
    public func cssShadow<S: InsettableShape>(_ shape: S, y: CGFloat, blur: CGFloat, spread: CGFloat = 0, color: Color) -> some View {
        background {
            shape
                .inset(by: -spread)
                .fill(color)
                .offset(y: y)
                .blur(radius: blur / 2)
        }
    }
}
