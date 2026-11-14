#if canImport(MapLibre)
import CoreLocation
import MapLibre

/// The map's own arrow, from the phone, with a stand-in when the phone has no good fix (underground,
/// a weak signal): rather than standing where the last good fix was while the tram goes on, the arrow
/// moves on where the trip says it is (a ride on its line by its timetable), its halo as wide as the
/// guess is rough. Good fixes always win, the moment they come back.
final class StandInLocationManager: NSObject {
    weak var delegate: MLNLocationManagerDelegate?
    /// Where the trip says the phone is now, when it can tell (on a ride); nil: no stand-in.
    var standIn: ((Date) -> CLLocation?)?
    private let real = CLLocationManager()
    private var lastGood = Date.distantPast
    private var tick: Timer?

    /// No good fix this long: the stand-in takes over.
    static let staleAfter: TimeInterval = 20
    /// A fix rougher than this isn't one to stand by (the trip's own guidance trusts 65 m).
    static let goodMetres: CLLocationAccuracy = 65

    override init() {
        super.init()
        real.delegate = self
        real.desiredAccuracy = kCLLocationAccuracyBestForNavigation
        real.distanceFilter = kCLDistanceFilterNone
        real.activityType = .otherNavigation
    }

    /// The stand-in, once a second while no good fix has come for a while.
    private func standInNow() {
        guard Date.now.timeIntervalSince(lastGood) > Self.staleAfter, let place = standIn?(.now) else { return }
        delegate?.locationManager(self, didUpdate: [place])
    }
}

extension StandInLocationManager: @preconcurrency MLNLocationManager {
    var authorizationStatus: CLAuthorizationStatus { real.authorizationStatus }
    var headingOrientation: CLDeviceOrientation {
        get { real.headingOrientation }
        set { real.headingOrientation = newValue }
    }

    func requestAlwaysAuthorization() { real.requestAlwaysAuthorization() }
    func requestWhenInUseAuthorization() { real.requestWhenInUseAuthorization() }

    func startUpdatingLocation() {
        real.startUpdatingLocation()
        tick?.invalidate()
        tick = Timer.scheduledTimer(withTimeInterval: 1, repeats: true) { [weak self] _ in
            MainActor.assumeIsolated { self?.standInNow() }
        }
    }

    func stopUpdatingLocation() {
        real.stopUpdatingLocation()
        tick?.invalidate()
        tick = nil
    }

    func startUpdatingHeading() { real.startUpdatingHeading() }
    func stopUpdatingHeading() { real.stopUpdatingHeading() }
    func dismissHeadingCalibrationDisplay() { real.dismissHeadingCalibrationDisplay() }
}

extension StandInLocationManager: @preconcurrency CLLocationManagerDelegate {
    func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        guard let last = locations.last else { return }
        let good = last.horizontalAccuracy >= 0 && last.horizontalAccuracy <= Self.goodMetres
        if good { lastGood = last.timestamp }
        // A rough fix while the trip can tell better: the trip's.
        if !good, standIn?(.now) != nil, Date.now.timeIntervalSince(lastGood) > Self.staleAfter { standInNow(); return }
        delegate?.locationManager(self, didUpdate: locations)
    }

    func locationManager(_ manager: CLLocationManager, didUpdateHeading newHeading: CLHeading) {
        delegate?.locationManager(self, didUpdate: newHeading)
    }

    func locationManagerShouldDisplayHeadingCalibration(_ manager: CLLocationManager) -> Bool {
        delegate?.locationManagerShouldDisplayHeadingCalibration(self) ?? false
    }

    func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        delegate?.locationManager(self, didFailWithError: error)
    }

    func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        delegate?.locationManagerDidChangeAuthorization(self)
    }
}
#endif
