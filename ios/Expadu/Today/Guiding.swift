import API
import AVFoundation
import OSLog
import Core
import CoreLocation
import DesignSystem
import Maps
import SwiftUI

/// Our navigation while a trip is live: the phone's own GPS on our router's lines (Guide), the
/// turn coming up, and the turn said aloud when asked. It keeps going with the screen off (a
/// background activity session, the blue pill in the status bar) and stops when the trip ends.
@Observable
final class PhoneFollow: NSObject, CLLocationManagerDelegate {
    private(set) var point: TodayPage.Point?
    private(set) var at: Date?
    /// Where they are on the trip, worked out on every fix.
    private(set) var here: Guide.Position?
    /// When they left the way; nil while on it.
    private(set) var offSince: Date?
    /// The trip being guided; set by the live card.
    var trip: TodayPage.Trip? {
        // The rides already checked are kept: a trip trip sync changed still has the ride they're on.
        didSet { if trip?.legs != oldValue?.legs { reached = 0; offSince = nil; here = nil } }
    }
    /// Trip sync: a ride pulled away from its stop at another time than planned (which tram is it?).
    @ObservationIgnored var departed: ((TodayPage.Trip.Leg, Int, Date) -> Void)?
    /// Trip sync: a late ride gets them to the next ride's stop after it leaves (when they can be there).
    @ObservationIgnored var changeBroken: ((TodayPage.Trip.Leg, Int, Date) -> Void)?
    /// Rides checked once on board, and changes found broken: by the ride (its stop and planned time),
    /// never by its place in the trip, which a switch moves.
    @ObservationIgnored private var pulledAway: Set<String> = []
    @ObservationIgnored private var broken: Set<String> = []
    /// Trip sync's own trail (Console, category tripsync): what the phone saw and asked.
    static let log = Logger(subsystem: "com.expadu.app", category: "tripsync")
    @ObservationIgnored private var reached = 0
    @ObservationIgnored private let manager = CLLocationManager()
    @ObservationIgnored private var users = 0
    @ObservationIgnored private var session: CLBackgroundActivitySession?
    /// Moves the Live Activity on even when they stand still (waiting at a stop, riding).
    @ObservationIgnored private var tick: Task<Void, Never>?

    override init() {
        super.init()
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyBestForNavigation
        // Every fix, even standing still: a tram stuck between stops is late, not on its way.
        manager.distanceFilter = kCLDistanceFilterNone
        manager.activityType = .otherNavigation
        manager.pausesLocationUpdatesAutomatically = false
    }

    func start() {
        users += 1
        guard users == 1 else { return }
        if manager.authorizationStatus == .notDetermined { manager.requestWhenInUseAuthorization() }
        // On the way with the phone in a pocket: fixes keep coming, under "while using the app".
        session = CLBackgroundActivitySession()
        manager.allowsBackgroundLocationUpdates = true
        // The trip's Live Activity already says Expadu is guiding; a second indicator would split the island.
        manager.showsBackgroundLocationIndicator = false
        manager.startUpdatingLocation()
        tick = Task { [weak self] in
            while !Task.isCancelled {
                self?.island()
                try? await Task.sleep(for: .seconds(30))
            }
        }
    }

    func stop() {
        users = max(0, users - 1)
        guard users == 0 else { return }
        manager.stopUpdatingLocation()
        manager.allowsBackgroundLocationUpdates = false
        session?.invalidate()
        session = nil
        tick?.cancel()
        tick = nil
    }

    /// The Lock Screen and the Dynamic Island, from where they are now.
    private func island(at date: Date = .now) {
        changeCheck(at: date)
        guard let trip else { return }
        TripActivity.shared.sync(trip, here: here, offFor: offSince.map { date.timeIntervalSince($0) } ?? 0, at: date)
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        // Rough fixes too (a station's walls): with their accuracy, they say "at the stop" but never a turn.
        guard let last = locations.last, last.horizontalAccuracy >= 0, last.horizontalAccuracy < 100 else { return }
        let point = TodayPage.Point(latitude: last.coordinate.latitude, longitude: last.coordinate.longitude)
        let at = last.timestamp
        let accuracy = last.horizontalAccuracy
        Task { @MainActor in self.moved(to: point, at: at, accuracy: accuracy) }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {}

    private func moved(to point: TodayPage.Point, at date: Date, accuracy: Double) {
        self.point = point
        at = date
        guard let trip, let here = Guide(trip: trip).position(of: point, at: date, after: reached, accuracy: accuracy) else { return }
        self.here = here
        // A rough fix never moves them on to a later leg (nor back): only a good one does.
        if accuracy <= Guide.trustMetres { reached = max(reached, here.leg) }
        offSince = here.offRoute ? (offSince ?? date) : nil
        rideCheck(trip, here, accuracy: accuracy, at: date)
        island(at: date)
        // Said aloud, each once: the turn as it comes close, being off the way, arriving.
        // Nothing said aloud from a fix too rough to go by.
        guard UserDefaults.standard.bool(forKey: "speakTurns"), accuracy <= Guide.trustMetres else { return }
        let key = trip.arriveTime ?? trip.to
        if let turn = here.turn, let metres = here.metresToTurn, metres <= 30 {
            TurnVoice.shared.say(turn.words, once: "\(key):\(here.leg):\(turn.words)")
        }
        if let since = offSince, date.timeIntervalSince(since) > 15 {
            TurnVoice.shared.say("You’re off the route.", once: "\(key):off:\(Int(since.timeIntervalSinceReferenceDate))")
        }
        if let missed = here.missed {
            TurnVoice.shared.say("\(missed.name) has left. Open Expadu for a new way.", once: "\(key):missed:\(missed.departTime)")
        }
        if here.arrived { TurnVoice.shared.say("You’re there.", once: "\(key):there") }
    }

    /// Trip sync: on the first good fix on a ride (the phone moving with it), when it left their stop,
    /// by how late it runs where the phone is. Two minutes or more off the plan, and not the delay the
    /// board already has for it: another run, or the planned one later still, for the server to tell
    /// (it has the live times). Once a ride.
    private func rideCheck(_ trip: TodayPage.Trip, _ here: Guide.Position, accuracy: Double, at date: Date) {
        guard accuracy <= Guide.trustMetres, !here.offRoute, let ride = here.ride, ride.onBoard,
              trip.legs.indices.contains(here.leg), pulledAway.insert(Self.key(trip.legs[here.leg])).inserted else { return }
        let leg = trip.legs[here.leg]
        let left = leg.departAt.addingTimeInterval(ride.late)
        let known = TimeInterval(max(0, leg.delayMin ?? 0) * 60)
        Self.log.info("on leg \(here.leg, privacy: .public): left \(left.formatted(date: .omitted, time: .standard), privacy: .public), planned \(leg.departTime, privacy: .public), \(Int(ride.late), privacy: .public) s off, board \(Int(known), privacy: .public) s")
        if abs(ride.late) >= 120, abs(ride.late - known) >= 90 { departed?(leg, here.leg, left) }
    }

    /// Trip sync: on a ride, as late as it runs (measured on board), can they still make the next one?
    /// Getting in plus the change, after the next one leaves (as late as the board says it runs): the
    /// next tram there, once.
    private func changeCheck(at date: Date) {
        guard let trip, let here, let broken = trip.brokenChange(here: here, at: date),
              self.broken.insert(Self.key(trip.legs[broken.leg])).inserted else { return }
        Self.log.info("change to leg \(broken.leg, privacy: .public) broken: ready \(broken.ready.formatted(date: .omitted, time: .standard), privacy: .public)")
        changeBroken?(trip.legs[broken.leg], broken.leg, broken.ready)
    }

    /// A ride as trip sync knows it: where it boards and when it was planned to leave.
    private static func key(_ leg: TodayPage.Trip.Leg) -> String { "\(leg.from)|\(leg.departTime)" }

    /// Where they are, while the phone knows it: a fix in the last minute, good enough to go by.
    func trusted(at now: Date = .now) -> Guide.Position? {
        here.flatMap { $0.trusted(at: now) ? $0 : nil }
    }

    /// A new way started: guide it from its start.
    func restart() {
        pulledAway = []
        broken = []
        reached = 0
        offSince = nil
        here = nil
    }
}

/// Turns spoken with the phone's own voice, each once, when they come close.
@MainActor
final class TurnVoice {
    static let shared = TurnVoice()
    private let voice = AVSpeechSynthesizer()
    private var said: Set<String> = []

    func say(_ text: String, once key: String) {
        guard said.insert(key).inserted else { return }
        // Over music or a podcast, briefly quieter, and with the screen off.
        try? AVAudioSession.sharedInstance().setCategory(.playback, mode: .voicePrompt, options: [.duckOthers, .interruptSpokenAudioAndMixWithOthers])
        try? AVAudioSession.sharedInstance().setActive(true)
        let words = AVSpeechUtterance(string: text)
        words.voice = AVSpeechSynthesisVoice(language: "en-GB")
        voice.speak(words)
    }
}

/// A way as lines and pins on our map: rides in their line's colour, walking dotted, the start and the end.
enum WayDrawing {
    static let walkColour = UIColor(red: 0x6A / 255, green: 0x62 / 255, blue: 0x53 / 255, alpha: 1)
    /// TRIP_LOOK.bike.
    static let bikeColour = UIColor(red: 0x00 / 255, green: 0x76 / 255, blue: 0x8B / 255, alpha: 1)

    static func points(_ leg: TodayPage.Trip.Leg) -> [CLLocationCoordinate2D] {
        (leg.polyline.map { Polyline.decode($0) } ?? []).map { CLLocationCoordinate2D(latitude: $0.latitude, longitude: $0.longitude) }
    }

    static func lines(_ legs: [TodayPage.Trip.Leg], prefix: String = "w", doneBefore: Int? = nil) -> [CityMap.Line] {
        legs.enumerated().map { index, leg in
            CityMap.Line(id: "\(prefix)\(index)", points: points(leg),
                         color: leg.rides ? UIColor(LineLook(hex: leg.color ?? "#1A4CD4").deep) : leg.mode == "bike" ? bikeColour : walkColour,
                         width: leg.rides ? 6 : 4.5, dashed: leg.mode == "walk", faint: doneBefore.map { index < $0 } ?? false)
        }
    }

    static func pins(_ legs: [TodayPage.Trip.Leg], to: String, start: Bool = true) -> [CityMap.Pin] {
        var pins: [CityMap.Pin] = []
        if start, let first = legs.first.flatMap({ points($0).first }) {
            pins.append(.init(id: "start", coordinate: first, title: "", kind: .start))
        }
        // Every stop a ride passes, as a small ring on its line (under the badges and flag).
        for (index, leg) in legs.enumerated() where leg.rides {
            let ring = UIColor(LineLook(hex: leg.color ?? "#1A4CD4").deep)
            for (n, stop) in leg.stopsOnTheWay.enumerated() {
                guard let lat = stop.latitude, let lng = stop.longitude else { continue }
                pins.append(.init(id: "stop\(index)-\(n)", coordinate: CLLocationCoordinate2D(latitude: lat, longitude: lng), title: stop.name,
                                  kind: .stop(ring: ring, symbol: LineBadge.symbol(leg.mode))))
            }
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

/// When the trip has gone wrong: off the way, or the ride left. One tap shows the routes from here.
struct TripTrouble: View {
    let text: String
    let replan: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Label(text, systemImage: "exclamationmark.triangle.fill")
                .font(.geist(14.5, .semibold)).foregroundStyle(Palette.warnText)
                .fixedSize(horizontal: false, vertical: true)
            Button(action: replan) {
                HStack(spacing: 8) {
                    Image(systemName: "arrow.triangle.turn.up.right.diamond.fill")
                    Text("New route from here")
                }
                .font(.geist(15, .semibold)).foregroundStyle(.white)
                .frame(maxWidth: .infinity).frame(height: 44)
                .buttonFace(Palette.action)
            }
            .buttonStyle(PressStyle())
        }
        .padding(12)
        .background(Palette.warnBackground, in: .rect(cornerRadius: 14))
    }
}

/// Trip sync, unsure which tram they're on (the 16 and the 18 a minute apart at Ebertplatz): asked in
/// place, where the trip is, one tap each; the trip then follows that one. "Neither" leaves it be.
struct RideChooser: View {
    let choice: TodayModel.RideChoice
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Label(choice.bus ? "Which bus are you on?" : "Which tram are you on?", systemImage: choice.bus ? "bus.fill" : "tram.fill")
                .font(.geist(14.5, .semibold))
            HStack(spacing: 8) {
                ForEach(choice.rides, id: \.self) { ride in
                    Button {
                        Task { await today.takeRide(session.api, ride, index: choice.index, from: choice.from, at: choice.at) }
                    } label: {
                        VStack(spacing: 4) {
                            LineBadge(line: ride.line, color: ride.color, height: 24)
                            Text(ride.departTime).font(.geist(13, .semibold)).monospacedDigit()
                        }
                        .frame(maxWidth: .infinity).frame(height: 62)
                        .background(Palette.quiet, in: .rect(cornerRadius: 12))
                        .contentShape(.rect(cornerRadius: 12))
                    }
                    .buttonStyle(PressStyle())
                    .accessibilityLabel("The \(ride.line) at \(ride.departTime)")
                }
                Button { today.dismissRideChoice() } label: {
                    Text("Neither").font(.geist(13.5, .semibold))
                        .frame(maxWidth: .infinity).frame(height: 62)
                        .background(Palette.quiet, in: .rect(cornerRadius: 12))
                        .contentShape(.rect(cornerRadius: 12))
                }
                .buttonStyle(PressStyle())
            }
        }
        .padding(12)
        .foregroundStyle(Palette.text)
        .background(Palette.surface, in: .rect(cornerRadius: 16))
    }
}
