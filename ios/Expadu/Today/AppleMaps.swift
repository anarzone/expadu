import Core
import DesignSystem
import MapKit
import SwiftUI

/// Apple Maps for the parts of a way it does better: turn-by-turn on foot, or the whole trip for
/// someone who'd rather follow it there. Our own trip stays the one on Today.
enum AppleMaps {
    enum Mode { case walking, transit }

    static func open(_ point: TodayPage.Point, name: String, mode: Mode) {
        let item = MKMapItem(location: CLLocation(latitude: point.latitude, longitude: point.longitude), address: nil)
        item.name = name
        item.openInMaps(launchOptions: [MKLaunchOptionsDirectionsModeKey: mode == .walking ? MKLaunchOptionsDirectionsModeWalking : MKLaunchOptionsDirectionsModeTransit])
    }
}

/// "Walk with Apple Maps": a quiet capsule under a walking step.
struct WalkWithAppleMaps: View {
    let point: TodayPage.Point
    let name: String

    var body: some View {
        Button { AppleMaps.open(point, name: name, mode: .walking) } label: {
            HStack(spacing: 6) {
                Image(systemName: "figure.walk").font(.system(size: 13, weight: .semibold))
                Text("Walk with Apple Maps")
                Image(systemName: "arrow.up.right").font(.system(size: 11, weight: .bold))
            }
            .font(.geist(14, .semibold)).foregroundStyle(TodayLink.ink)
            .padding(.horizontal, 14).frame(height: 36)
            .background(Color(light: 0xDEF5F8, dark: 0x1D3F47), in: .capsule)
        }
        .buttonStyle(PressStyle())
        .accessibilityLabel("Walk to \(name) with Apple Maps")
        .accessibilityHint("Opens Apple Maps")
    }
}

/// A walk, turn by turn, in place: Apple's walking route from the way's start to the place.
@Observable
final class WalkingRoute {
    private(set) var route: MKRoute?
    private var key = ""

    func load(from start: TodayPage.Point, to end: TodayPage.Point) async {
        let key = "\(start.latitude),\(start.longitude)>\(end.latitude),\(end.longitude)"
        guard key != self.key else { return }
        self.key = key
        let request = MKDirections.Request()
        request.source = MKMapItem(location: CLLocation(latitude: start.latitude, longitude: start.longitude), address: nil)
        request.destination = MKMapItem(location: CLLocation(latitude: end.latitude, longitude: end.longitude), address: nil)
        request.transportType = .walking
        let answer = try? await MKDirections(request: request).calculate()
        route = answer?.routes.first
    }
}

/// The turns of a walking route (.way steps), each with how far it goes.
struct WalkTurns: View {
    let route: MKRoute
    let to: String
    let point: TodayPage.Point

    var body: some View {
        let turns = route.steps.filter { !$0.instructions.isEmpty }
        VStack(alignment: .leading, spacing: 0) {
            ForEach(Array(turns.enumerated()), id: \.offset) { index, turn in
                HStack(alignment: .top, spacing: 12) {
                    VStack(spacing: 0) {
                        Circle().strokeBorder(Color(hex: 0x6A6253), lineWidth: 3).frame(width: 18, height: 18)
                        if index < turns.count - 1 {
                            Rectangle().fill(Color(hex: 0x6A6253).opacity(0.5)).frame(width: 2).frame(maxHeight: .infinity)
                        }
                    }
                    VStack(alignment: .leading, spacing: 2) {
                        Text(turn.instructions).font(.geist(15, .semibold)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                        if turn.distance >= 5 {
                            Text(Self.metres(turn.distance)).font(.geist(13)).foregroundStyle(Palette.textSoft)
                        }
                    }
                    .padding(.bottom, 12)
                    Spacer(minLength: 0)
                }
            }
            WalkWithAppleMaps(point: point, name: to).padding(.top, 2)
        }
        .accessibilityElement(children: .contain)
    }

    private static func metres(_ distance: CLLocationDistance) -> String {
        distance < 1000 ? "\(Int((distance / 10).rounded()) * 10) m" : String(format: "%.1f km", distance / 1000)
    }
}
