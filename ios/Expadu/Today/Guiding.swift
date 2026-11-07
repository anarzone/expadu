import AVFoundation
import Core
import CoreLocation
import DesignSystem
import Maps
import SwiftUI

/// The phone's position while a trip is live: the device's own GPS, nothing else. Starts when the
/// live card shows, stops when it goes.
@Observable
final class PhoneFollow: NSObject, CLLocationManagerDelegate {
    private(set) var point: TodayPage.Point?
    private(set) var at: Date?
    @ObservationIgnored private let manager = CLLocationManager()
    @ObservationIgnored private var users = 0

    override init() {
        super.init()
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyBestForNavigation
        manager.distanceFilter = 5
        manager.activityType = .otherNavigation
    }

    func start() {
        users += 1
        guard users == 1 else { return }
        if manager.authorizationStatus == .notDetermined { manager.requestWhenInUseAuthorization() }
        manager.startUpdatingLocation()
    }

    func stop() {
        users = max(0, users - 1)
        if users == 0 { manager.stopUpdatingLocation() }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        guard let last = locations.last, last.horizontalAccuracy >= 0, last.horizontalAccuracy < 80 else { return }
        let point = TodayPage.Point(latitude: last.coordinate.latitude, longitude: last.coordinate.longitude)
        let at = last.timestamp
        Task { @MainActor in
            self.point = point
            self.at = at
        }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {}
}

/// Turns spoken with the phone's own voice, each once, when they come close.
@MainActor
final class TurnVoice {
    static let shared = TurnVoice()
    private let voice = AVSpeechSynthesizer()
    private var said: Set<String> = []

    func say(_ text: String, once key: String) {
        guard said.insert(key).inserted else { return }
        let words = AVSpeechUtterance(string: text)
        words.voice = AVSpeechSynthesisVoice(language: "en-GB")
        voice.speak(words)
    }
}

/// A way as lines and pins on our map: rides in their line's colour, walking dotted, the start and the end.
enum WayDrawing {
    static let walkColour = UIColor(red: 0x6A / 255, green: 0x62 / 255, blue: 0x53 / 255, alpha: 1)

    static func points(_ leg: TodayPage.Trip.Leg) -> [CLLocationCoordinate2D] {
        (leg.polyline.map { Polyline.decode($0) } ?? []).map { CLLocationCoordinate2D(latitude: $0.latitude, longitude: $0.longitude) }
    }

    static func lines(_ legs: [TodayPage.Trip.Leg], prefix: String = "w", doneBefore: Int? = nil) -> [CityMap.Line] {
        legs.enumerated().map { index, leg in
            CityMap.Line(id: "\(prefix)\(index)", points: points(leg),
                         color: leg.rides ? UIColor(LineLook(hex: leg.color ?? "#1A4CD4").deep) : walkColour,
                         width: leg.rides ? 6 : 4.5, dashed: !leg.rides, faint: doneBefore.map { index < $0 } ?? false)
        }
    }

    static func pins(_ legs: [TodayPage.Trip.Leg], to: String, start: Bool = true) -> [CityMap.Pin] {
        var pins: [CityMap.Pin] = []
        if start, let first = legs.first.flatMap({ points($0).first }) {
            pins.append(.init(id: "start", coordinate: first, title: "", kind: .start))
        }
        for (index, leg) in legs.enumerated() where leg.rides {
            if let board = points(leg).first {
                let look = LineLook(hex: leg.color ?? "#1A4CD4")
                pins.append(.init(id: "ride\(index)", coordinate: board, title: leg.from,
                                  kind: .badge(text: leg.line.map { Board.badge($0) } ?? "", fill: UIColor(look.deep), ink: .white)))
            }
        }
        if let end = legs.last.flatMap({ points($0).last }) {
            pins.append(.init(id: "end", coordinate: end, title: to, kind: .destination))
        }
        return pins
    }

    static func all(_ legs: [TodayPage.Trip.Leg]) -> [CLLocationCoordinate2D] {
        legs.flatMap(points)
    }
}

/// The next turn on foot (our router's), with how far it is: "↰ Turn left onto Breite Straße · 80 m".
struct TurnRow: View {
    let turn: TodayPage.Turn
    let metres: Int?

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: turn.symbol).font(.system(size: 20, weight: .bold)).foregroundStyle(.white)
                .frame(width: 40, height: 40)
                .background(StepColour.walk, in: .rect(cornerRadius: 11))
                .contentTransition(.symbolEffect(.replace))
            VStack(alignment: .leading, spacing: 1) {
                if let metres { Text(Self.distance(metres)).font(.posterGrotesque(19)).monospacedDigit().foregroundStyle(Palette.text).contentTransition(.numericText(countsDown: true)) }
                Text(turn.words).font(.geist(14.5, .semibold)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
            }
            Spacer(minLength: 0)
        }
        .accessibilityElement(children: .combine)
    }

    static func distance(_ metres: Int) -> String {
        metres < 1000 ? "\(max(10, (metres / 10) * 10)) m" : String(format: "%.1f km", Double(metres) / 1000)
    }
}

/// A walking way's turns, as our router gives them (Getting there's steps on foot).
struct TurnList: View {
    let turns: [TodayPage.Turn]
    let to: String

    var body: some View {
        let shown = turns.filter { $0.direction != "continue" || !$0.street.isEmpty }
        VStack(alignment: .leading, spacing: 0) {
            ForEach(Array(shown.enumerated()), id: \.offset) { index, turn in
                HStack(alignment: .top, spacing: 12) {
                    VStack(spacing: 0) {
                        Image(systemName: turn.symbol).font(.system(size: 11, weight: .bold)).foregroundStyle(StepColour.walk)
                            .frame(width: 22, height: 22)
                            .overlay { Circle().strokeBorder(StepColour.walk, lineWidth: 2.5) }
                        Rectangle().fill(StepColour.walk.opacity(0.5)).frame(width: 2).frame(maxHeight: .infinity)
                    }
                    VStack(alignment: .leading, spacing: 2) {
                        Text(turn.words).font(.geist(15, .semibold)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                        if turn.metres >= 10 { Text(TurnRow.distance(turn.metres)).font(.geist(13)).foregroundStyle(Palette.textSoft) }
                    }
                    .padding(.bottom, 12)
                    Spacer(minLength: 0)
                }
            }
            HStack(spacing: 12) {
                Image(systemName: "flag.fill").font(.system(size: 10, weight: .bold)).foregroundStyle(Color(hex: 0xE6421F))
                    .frame(width: 22, height: 22)
                    .overlay { Circle().strokeBorder(Color(hex: 0xE6421F), lineWidth: 2.5) }
                Text("You’re at \(to)").font(.geist(15, .semibold)).foregroundStyle(Palette.text)
            }
        }
    }
}

/// When the trip has gone wrong: off the way, or the ride left. One tap plans again from here.
struct TripTrouble: View {
    let text: String
    let busy: Bool
    let replan: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Label(text, systemImage: "exclamationmark.triangle.fill")
                .font(.geist(14.5, .semibold)).foregroundStyle(Palette.warnText)
                .fixedSize(horizontal: false, vertical: true)
            Button(action: replan) {
                HStack(spacing: 8) {
                    if busy { ProgressView().tint(.white) } else { Image(systemName: "arrow.triangle.turn.up.right.diamond.fill") }
                    Text("New way from here")
                }
                .font(.geist(15, .semibold)).foregroundStyle(.white)
                .frame(maxWidth: .infinity).frame(height: 44)
                .background(Palette.action, in: .capsule)
            }
            .buttonStyle(PressStyle())
            .disabled(busy)
        }
        .padding(12)
        .background(Palette.warnBackground, in: .rect(cornerRadius: 14))
    }
}
