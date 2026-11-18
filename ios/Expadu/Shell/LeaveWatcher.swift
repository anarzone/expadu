import API
import CoreLocation
import Core
import UIKit
import UserNotifications

/// Noticing when they leave home or work (the canvas "When you leave", owner 18 Nov 2026). The phone
/// watches two circles only, their saved home and work, with iOS's own monitor (CLMonitor), which
/// wakes the app when one is crossed, even when it's closed. Leaving one on a routine's day and time
/// asks Expadu for the next way and shows it at once; going from one to the other is told to Expadu
/// as a trip (which place, and when they left), never a coordinate. Its own switch on What Expadu
/// knows, under "Learn from my trips".
@MainActor @Observable
final class LeaveWatcher: NSObject, CLLocationManagerDelegate {
    static let shared = LeaveWatcher()

    /// One crossing, kept on this phone for What Expadu knows (the last few).
    struct Crossing: Codable, Equatable, Identifiable {
        let place: String
        let left: Bool
        let at: Date
        var id: String { "\(place).\(left).\(at.timeIntervalSince1970)" }
    }

    /// Turned on here (and on Expadu's side).
    private(set) var on: Bool
    /// iOS lets Expadu use location "Always".
    private(set) var always: Bool
    private(set) var crossings: [Crossing]

    private let defaults = UserDefaults.standard
    private let manager = CLLocationManager()
    private var session: CLServiceSession?
    private var listening: Task<Void, Never>?
    private let api = AppAPI(baseURL: AppConfig.api, device: UIDevice.current.name) { TokenKeychain.load()?.token.value }

    /// The monitor's name: letters and digits only, or CLMonitor stops the app ("Monitor name is not valid").
    private static let monitorName = "ExpaduLeave"
    /// Each circle's size: big enough to be noticed reliably, small enough to mean home or work.
    private static let radius: CLLocationDistance = 150
    /// A trip from one to the other is one when it takes less than this.
    private static let longestTrip: TimeInterval = 3 * 3600

    private override init() {
        on = defaults.bool(forKey: "leave.on")
        crossings = (defaults.data(forKey: "leave.crossings")).flatMap { try? JSONDecoder().decode([Crossing].self, from: $0) } ?? []
        always = false
        super.init()
        manager.delegate = self
        always = manager.authorizationStatus == .authorizedAlways
    }

    /// At launch, including when iOS wakes the app for a crossing: watching again, as Apple asks.
    func resume() {
        guard on, always else { return }
        session = CLServiceSession(authorization: .always)
        listen()
    }

    /// After the one-button Continue: Expadu's side first, then iOS's "Always", then the circles.
    func turnOn() async throws(APIFailure) {
        try await api.setLeaveNotice(true)
        if manager.authorizationStatus != .authorizedAlways {
            manager.requestAlwaysAuthorization()
            await Self.answered()
        }
        always = manager.authorizationStatus == .authorizedAlways
        on = true
        defaults.set(true, forKey: "leave.on")
        resume()
    }

    /// Off: Expadu forgets the trips it noticed, and the phone stops watching and forgets its list.
    func turnOff() async throws(APIFailure) {
        try await api.setLeaveNotice(false)
        on = false
        defaults.set(false, forKey: "leave.on")
        crossings = []
        defaults.removeObject(forKey: "leave.crossings")
        defaults.removeObject(forKey: "leave.lastLeft")
        listening?.cancel()
        listening = nil
        session = nil
        Task { let monitor = await CLMonitor(Self.monitorName); for place in ["home", "work"] { await monitor.remove(place) } }
    }

    /// iOS's prompt answered: it puts the app aside while it shows, and back when it's answered. "Keep
    /// Only While Using" changes nothing, so there's no other word from iOS; no prompt at all (asked
    /// before) leaves the app as it is.
    private static func answered() async {
        // Shown: the app goes inactive within a moment of asking.
        var shown = false
        for _ in 0..<6 {
            try? await Task.sleep(for: .milliseconds(250))
            if UIApplication.shared.applicationState != .active { shown = true; break }
        }
        guard shown else { return }
        // Answered: the app is active again.
        while UIApplication.shared.applicationState != .active {
            try? await Task.sleep(for: .milliseconds(250))
        }
    }

    /// Their saved home and work, read again (they may have moved).
    private func places() async -> [(String, CLLocationCoordinate2D)] {
        if let saved = try? await api.travelPlaces() {
            var points: [(String, CLLocationCoordinate2D)] = []
            for place in [saved.home, saved.work].compactMap({ $0 }) {
                guard let lat = place.lat, let lng = place.lng else { continue }
                points.append((place.category, CLLocationCoordinate2D(latitude: lat, longitude: lng)))
            }
            let rows: [[String]] = points.map { [$0.0, String($0.1.latitude), String($0.1.longitude)] }
            defaults.set(rows, forKey: "leave.places")
            return points
        }
        // Offline (or woken in the background): the ones from last time.
        return (defaults.array(forKey: "leave.places") as? [[String]] ?? []).compactMap { row in
            guard row.count == 3, let lat = Double(row[1]), let lng = Double(row[2]) else { return nil }
            return (row[0], CLLocationCoordinate2D(latitude: lat, longitude: lng))
        }
    }

    private func listen() {
        guard listening == nil else { return }
        listening = Task { [weak self] in
            guard let self else { return }
            let monitor = await CLMonitor(Self.monitorName)
            for (place, center) in await self.places() {
                await monitor.add(CLMonitor.CircularGeographicCondition(center: center, radius: Self.radius), identifier: place)
            }
            do {
                for try await event in await monitor.events {
                    await self.handle(place: event.identifier, inside: event.state == .satisfied, known: event.state != .unknown, at: event.date)
                }
            } catch {}
        }
    }

    /// A crossing: only a change from inside to outside (or back) counts, never the first reading.
    private func handle(place: String, inside: Bool, known: Bool, at: Date) async {
        guard on, known, ["home", "work"].contains(place) else { return }
        let key = "leave.inside.\(place)"
        let was = defaults.object(forKey: key) as? Bool
        defaults.set(inside, forKey: key)
        guard let was, was != inside else { return }
        // A moment's grace from iOS to finish what a crossing starts.
        let task = UIApplication.shared.beginBackgroundTask(withName: "leave", expirationHandler: nil)
        defer { UIApplication.shared.endBackgroundTask(task) }
        crossings = Array(([Crossing(place: place, left: !inside, at: at)] + crossings).prefix(5))
        defaults.set(try? JSONEncoder().encode(crossings), forKey: "leave.crossings")
        if inside {
            // Arrived: from the other one, not long ago, it was a trip.
            if let last = defaults.array(forKey: "leave.lastLeft") as? [Any], last.count == 2, let from = last[0] as? String,
               let leftAt = last[1] as? Double, from != place, at.timeIntervalSince1970 - leftAt < Self.longestTrip {
                try? await api.noticedTrip(from: from, to: place, leftAt: Date(timeIntervalSince1970: leftAt))
            }
            defaults.removeObject(forKey: "leave.lastLeft")
        } else {
            defaults.set([place, at.timeIntervalSince1970], forKey: "leave.lastLeft")
            await tell(leaving: place)
        }
    }

    /// Leaving on a routine's day and time: the next way, shown now (when notifications are allowed).
    private func tell(leaving place: String) async {
        let center = UNUserNotificationCenter.current()
        guard [.authorized, .provisional, .ephemeral].contains(await center.notificationSettings().authorizationStatus),
              let note = try? await api.leaving(from: place) else { return }
        let content = UNMutableNotificationContent()
        content.title = note.title
        content.body = note.body
        content.sound = .default
        content.interruptionLevel = .timeSensitive
        content.userInfo = ["link": note.link]
        try? await center.add(UNNotificationRequest(identifier: "leave.\(place).\(Int(Date.now.timeIntervalSince1970))", content: content, trigger: nil))
    }

    // MARK: CLLocationManagerDelegate

    nonisolated func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        let status = manager.authorizationStatus
        Task { @MainActor in self.always = status == .authorizedAlways }
    }
}
