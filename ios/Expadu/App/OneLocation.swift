import CoreLocation
import Foundation

/// Where the person is, asked once; nil when the answer is no or doesn't come.
///
/// Among tall buildings or underground the phone's first fix can be hundreds of metres off, and a
/// board built from it shows a stop the person isn't at. So it listens for a few seconds and keeps
/// the most precise fix; one that stays rough comes back with its accuracy, for the caller to weigh.
@MainActor
final class OneLocation: NSObject, CLLocationManagerDelegate {
    /// Travel's board when the phone won't say where it is. Today never guesses: a leave time from a guessed place would be wrong.
    nonisolated static let neumarkt = CLLocationCoordinate2D(latitude: 50.9356, longitude: 6.9480)
    /// A fix this precise is taken at once.
    static let good: CLLocationAccuracy = 35
    /// Coarser than this, a fix isn't trusted to pick a stop.
    static let rough: CLLocationAccuracy = 120

    private let manager = CLLocationManager()
    private var waiting: CheckedContinuation<CLLocation?, Never>?
    private var best: CLLocation?

    /// The accuracy of the last fix handed out, in metres (nil before any).
    private(set) var lastAccuracy: CLLocationAccuracy?

    override init() {
        super.init()
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyBest
        manager.distanceFilter = kCLDistanceFilterNone
    }

    func coordinate() async -> CLLocationCoordinate2D? {
        await fix()?.coordinate
    }

    /// The most precise fix within a few seconds, with its accuracy.
    func fix() async -> CLLocation? {
        switch manager.authorizationStatus {
        case .denied, .restricted: return nil
        case .notDetermined: manager.requestWhenInUseAuthorization()
        default: break
        }
        if let recent = manager.location, recent.timestamp.timeIntervalSinceNow > -30, recent.horizontalAccuracy >= 0, recent.horizontalAccuracy <= Self.good {
            lastAccuracy = recent.horizontalAccuracy
            return recent
        }
        let location = await withCheckedContinuation { continuation in
            waiting?.resume(returning: best)
            waiting = continuation
            best = nil
            manager.startUpdatingLocation()
            Task { [weak self] in
                try? await Task.sleep(for: .seconds(5))
                self?.answer()
            }
        }
        lastAccuracy = location?.horizontalAccuracy
        return location
    }

    private func answer() {
        manager.stopUpdatingLocation()
        waiting?.resume(returning: best)
        waiting = nil
        best = nil
    }

    private func take(_ location: CLLocation) {
        guard location.horizontalAccuracy >= 0, location.timestamp.timeIntervalSinceNow > -30 else { return }
        if best == nil || location.horizontalAccuracy < best!.horizontalAccuracy { best = location }
        if location.horizontalAccuracy <= Self.good { answer() }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        Task { @MainActor in
            for location in locations { self.take(location) }
        }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didFailWithError error: any Error) {
        Task { @MainActor in self.answer() }
    }

    nonisolated func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        switch manager.authorizationStatus {
        case .denied, .restricted: Task { @MainActor in self.answer() }
        default: break
        }
    }
}
