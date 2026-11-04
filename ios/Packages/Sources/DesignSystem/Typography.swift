import CoreText
import SwiftUI

/// The mock's faces: Geist for everything, Poster Grotesque and Poster Marker on posters.
public enum Typeface {
    /// Once at launch, before any view draws.
    nonisolated public static func register() {
        guard let urls = Bundle.module.urls(forResourcesWithExtension: "ttf", subdirectory: "Fonts") else { return }
        CTFontManagerRegisterFontURLs(urls as CFArray, .process, true, nil)
    }
}

extension Font {
    public static func geist(_ size: CGFloat, _ weight: Font.Weight = .regular, relativeTo style: Font.TextStyle = .body) -> Font {
        let name = switch weight {
        case .medium: "Geist-Medium"
        case .semibold: "Geist-SemiBold"
        case .bold: "Geist-Bold"
        default: "Geist-Regular"
        }
        return .custom(name, size: size, relativeTo: style)
    }

    public static func posterGrotesque(_ size: CGFloat) -> Font {
        .custom("PosterGrotesque-ExtraBold", size: size, relativeTo: .largeTitle)
    }

    public static func posterMarker(_ size: CGFloat) -> Font {
        .custom("PermanentMarker-Regular", size: size, relativeTo: .headline)
    }
}
