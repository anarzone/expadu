import API
import Core
import CoreLocation
import Maps
import DesignSystem
import SafariServices
import SwiftUI

// MARK: - Pieces

struct SectionHead<Trailing: View>: View {
    let title: String
    @ViewBuilder let trailing: Trailing

    var body: some View {
        HStack(alignment: .firstTextBaseline) {
            Text(title).font(.posterGrotesque(23)).tracking(-0.3).foregroundStyle(Palette.text)
                .accessibilityAddTraits(.isHeader)
            Spacer(minLength: 8)
            trailing
        }
        .padding(.horizontal, 4)
    }
}

/// A row with a round icon, a title, a line under it, and its action in teal.
struct ListRow: View {
    let symbol: String
    let title: String
    let note: String
    let action: String?

    var body: some View {
        HStack(spacing: 12) {
            if symbol == "composer" {
                // Composer's own mark: the spark on ink, ringed in its colours.
                Image(.composerSpark).resizable().frame(width: 16, height: 16).foregroundStyle(.white)
                    .frame(width: 36, height: 36)
                    .background(ComposerLook.ink, in: .circle)
                    .overlay { Circle().strokeBorder(ComposerLook.ring(), lineWidth: 2) }
            } else {
                Image(systemName: symbol).font(.system(size: 15, weight: .semibold))
                    .frame(width: 36, height: 36).background(Palette.quiet, in: .circle)
            }
            VStack(alignment: .leading, spacing: 2) {
                Text(title).font(.geist(15.5, .semibold)).lineLimit(2).multilineTextAlignment(.leading)
                if !note.isEmpty { Text(note).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(2).multilineTextAlignment(.leading) }
            }
            Spacer(minLength: 0)
            if let action { Text(action).font(.geist(14, .semibold)).foregroundStyle(TodayLink.ink) }
        }
        .foregroundStyle(Palette.text)
        .padding(.horizontal, 14).padding(.vertical, 10)
        .contentShape(.rect)
    }
}

enum TodayLink {
    static let ink = Color(light: 0x005B6B, dark: 0x5CCBE0)
}

struct Tight: LabelStyle {
    func makeBody(configuration: Configuration) -> some View {
        HStack(spacing: 7) { configuration.icon; configuration.title }
    }
}

struct TrailingIcon: LabelStyle {
    func makeBody(configuration: Configuration) -> some View {
        HStack(spacing: 4) { configuration.title; configuration.icon.font(.system(size: 12, weight: .bold)) }
    }
}

extension View {
    /// The canvas's .t-card: the surface, rounded, with its hairline.
    func card(radius: CGFloat) -> some View {
        background(Palette.surface, in: .rect(cornerRadius: radius))
            .overlay { RoundedRectangle(cornerRadius: radius).strokeBorder(Palette.border) }
    }
}

/// The server's weather icons as SF Symbols, in the canvas's colours.
enum WeatherSymbol {
    static func name(_ icon: String) -> String {
        switch icon {
        case "clear-day": "sun.max.fill"
        case "clear-night": "moon.stars.fill"
        case "partly-cloudy-day": "cloud.sun.fill"
        case "partly-cloudy-night": "cloud.moon.fill"
        case "cloudy": "cloud.fill"
        case "fog": "cloud.fog.fill"
        case "wind": "wind"
        case "rain": "cloud.rain.fill"
        case "sleet": "cloud.sleet.fill"
        case "snow": "cloud.snow.fill"
        case "hail": "cloud.hail.fill"
        case "thunderstorm": "cloud.bolt.rain.fill"
        default: "cloud.sun.fill"
        }
    }

    /// The canvas's four: sun amber, moon sand, rain blue, cloud grey.
    static func tint(_ icon: String) -> Color {
        switch icon {
        case "clear-day", "partly-cloudy-day": Color(hex: 0xF2A516)
        case "clear-night", "partly-cloudy-night": Color(hex: 0xC9B98A)
        case "rain", "sleet", "hail", "thunderstorm", "snow": Color(hex: 0x3F86D6)
        default: Color(hex: 0x8F97A6)
        }
    }
}

/// Around here's kinds as SF Symbols.
enum PlaceSymbol {
    static func name(_ kind: String) -> String {
        switch kind {
        case "pharmacy": "cross.case.fill"
        case "cafe": "cup.and.saucer.fill"
        case "bakery": "birthday.cake.fill"
        case "grocery": "cart.fill"
        case "playground", "park", "nature": "leaf.fill"
        case "library": "books.vertical.fill"
        case "restaurant", "fastfood": "fork.knife"
        case "culture": "building.columns.fill"
        case "sports": "figure.run"
        default: "mappin"
        }
    }
}

/// A note's colour by how much it matters.
enum NeedTone {
    static func color(_ severity: String) -> Color {
        switch severity {
        case "conflict", "critical": Color(hex: 0xD64528)
        case "attention", "warning": Color(hex: 0xC98208)
        default: Color(hex: 0x2F78C8)
        }
    }
}

/// A sheet opens tall, as the canvas draws them, and stops at half on the way down so Today shows
/// above it; a further pull closes it.
struct FlexibleSheet: ViewModifier {
    @State private var detent: PresentationDetent = .large

    func body(content: Content) -> some View {
        content
            .presentationDetents([.medium, .large], selection: $detent)
            .presentationDragIndicator(.visible)
    }
}

extension View {
    func flexibleSheet() -> some View { modifier(FlexibleSheet()) }
}
