import CoreLocation
import Foundation

/// Where the person is, asked once; nil when the answer is no or doesn't come.
@MainActor
final class OneLocation: NSObject, CLLocationManagerDelegate {
    /// Travel's board when the phone won't say where it is. Today never guesses: a leave time from a guessed place would be wrong.
    nonisolated static let neumarkt = CLLocationCoordinate2D(latitude: 50.9356, longitude: 6.9480)
    private let manager = CLLocationManager()
    private var waiting: CheckedContinuation<CLLocationCoordinate2D?, Never>?

    override init() {
        super.init()
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyHundredMeters
    }

    func coordinate() async -> CLLocationCoordinate2D? {
        switch manager.authorizationStatus {
        case .denied, .restricted: return nil
        case .notDetermined: manager.requestWhenInUseAuthorization()
        default: break
        }
        if let recent = manager.location, recent.timestamp.timeIntervalSinceNow > -120 { return recent.coordinate }
        return await withCheckedContinuation { continuation in
            waiting?.resume(returning: nil)
            waiting = continuation
            manager.requestLocation()
            Task { [weak self] in
                try? await Task.sleep(for: .seconds(6))
                self?.answer(nil)
            }
        }
    }

    private func answer(_ coordinate: CLLocationCoordinate2D?) {
        waiting?.resume(returning: coordinate)
        waiting = nil
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        let coordinate = locations.last?.coordinate
        Task { @MainActor in self.answer(coordinate) }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didFailWithError error: any Error) {
        Task { @MainActor in self.answer(nil) }
    }

    nonisolated func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        switch manager.authorizationStatus {
        case .denied, .restricted: Task { @MainActor in self.answer(nil) }
        // The first answer to the prompt: ask for the place now rather than waiting out the timer.
        case .authorizedWhenInUse, .authorizedAlways: manager.requestLocation()
        default: break
        }
    }
}
