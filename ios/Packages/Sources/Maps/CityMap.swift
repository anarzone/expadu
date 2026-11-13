#if canImport(MapLibre) && canImport(UIKit)
import CoreLocation
import MapLibre
import SwiftUI
import UIKit

/// The city map, open source end to end: MapLibre drawing OpenStreetMap tiles in the OpenFreeMap
/// style the web uses. Everything on it is ours: the ways in their line colours, walking dotted,
/// our pins, and where the camera goes. It can follow the phone while someone walks.
public struct CityMap: UIViewRepresentable {
    /// A way drawn on the map.
    public struct Line: Equatable {
        public let id: String
        public let points: [CLLocationCoordinate2D]
        public let color: UIColor
        public let width: Double
        public let dashed: Bool
        /// Drawn faint: a stretch already walked, or a way not picked.
        public let faint: Bool

        public init(id: String, points: [CLLocationCoordinate2D], color: UIColor, width: Double = 5, dashed: Bool = false, faint: Bool = false) {
            self.id = id
            self.points = points
            self.color = color
            self.width = width
            self.dashed = dashed
            self.faint = faint
        }

        public static func == (a: Line, b: Line) -> Bool {
            a.id == b.id && a.points.count == b.points.count && a.color == b.color && a.width == b.width && a.dashed == b.dashed && a.faint == b.faint
                && a.points.first?.latitude == b.points.first?.latitude && a.points.last?.longitude == b.points.last?.longitude
        }
    }

    /// A mark on the map.
    public struct Pin: Equatable {
        public enum Kind: Equatable {
            /// Where the way starts (the phone when it's known).
            case start
            /// Where it ends.
            case destination
            /// A line's badge where it's boarded: "18" on the line's colour.
            case badge(text: String, fill: UIColor, ink: UIColor)
            /// A place, with its SF Symbol.
            case place(symbol: String)
            /// A stop a line passes: a small ring in the line's colour; close up (street level), the
            /// station's badge with the mode's symbol, and its name.
            case stop(ring: UIColor, symbol: String = "tram.fill")
        }

        public let id: String
        public let coordinate: CLLocationCoordinate2D
        public let title: String
        public let kind: Kind

        public init(id: String, coordinate: CLLocationCoordinate2D, title: String, kind: Kind) {
            self.id = id
            self.coordinate = coordinate
            self.title = title
            self.kind = kind
        }

        public static func == (a: Pin, b: Pin) -> Bool {
            a.id == b.id && a.title == b.title && a.kind == b.kind && a.coordinate.latitude == b.coordinate.latitude && a.coordinate.longitude == b.coordinate.longitude
        }
    }

    let lines: [Line]
    let pins: [Pin]
    /// The points the camera fits on first show (and when they change).
    let fit: [CLLocationCoordinate2D]
    let padding: UIEdgeInsets
    let interactive: Bool
    /// Walking: the camera follows the phone, turned the way it's heading.
    let follow: Bool
    let showsUser: Bool
    /// A new value fits the camera again even when `fit` is the same (Show the whole way after panning).
    let fitID: Int
    /// How far up from the bottom the map's credit (i) sits, clear of whatever lies over the map there.
    let creditInset: CGFloat
    /// Parts of the map covered by something laid over it (a list at the bottom): the map's centre, its
    /// fits and following the phone all keep clear of them, and a change moves the map smoothly.
    let viewInset: UIEdgeInsets

    public init(lines: [Line], pins: [Pin], fit: [CLLocationCoordinate2D], padding: UIEdgeInsets = UIEdgeInsets(top: 40, left: 40, bottom: 40, right: 40),
                interactive: Bool = true, follow: Bool = false, showsUser: Bool = false, fitID: Int = 0, creditInset: CGFloat = 8, viewInset: UIEdgeInsets = .zero) {
        self.fitID = fitID
        self.creditInset = creditInset
        self.viewInset = viewInset
        self.lines = lines
        self.pins = pins
        self.fit = fit
        self.padding = padding
        self.interactive = interactive
        self.follow = follow
        self.showsUser = showsUser
    }

    /// The map's style: our own tile server's when the build names one (MapStyleLight / MapStyleDark
    /// in Info.plist), else OpenFreeMap's (OpenStreetMap data), as the web's maps use.
    static func style(dark: Bool) -> URL {
        let own = Bundle.main.object(forInfoDictionaryKey: dark ? "MapStyleDark" : "MapStyleLight") as? String
        if let own, !own.isEmpty, let url = URL(string: own) { return url }
        return URL(string: dark ? "https://tiles.openfreemap.org/styles/dark" : "https://tiles.openfreemap.org/styles/positron")!
    }

    public func makeCoordinator() -> Coordinator { Coordinator() }

    public func makeUIView(context: Context) -> MLNMapView {
        let dark = context.environment.colorScheme == .dark
        let map = SizedMapView(frame: .zero, styleURL: Self.style(dark: dark))
        map.laidOut = { [weak coordinator = context.coordinator] in coordinator?.fitIfPending() }
        map.delegate = context.coordinator
        map.logoView.isHidden = true
        // OpenStreetMap's credit stays: the (i) opens it.
        map.attributionButtonPosition = .bottomLeft
        map.attributionButton.tintColor = UIColor.secondaryLabel
        map.compassView.compassVisibility = .hidden
        map.allowsRotating = interactive
        map.allowsTilting = false
        map.isScrollEnabled = interactive
        map.isZoomEnabled = interactive
        context.coordinator.dark = dark
        context.coordinator.map = map
        context.coordinator.apply(self)
        return map
    }

    public func updateUIView(_ map: MLNMapView, context: Context) {
        let dark = context.environment.colorScheme == .dark
        if dark != context.coordinator.dark {
            context.coordinator.dark = dark
            map.styleURL = Self.style(dark: dark)
        }
        map.isScrollEnabled = interactive
        map.isZoomEnabled = interactive
        map.attributionButtonMargins = CGPoint(x: 8, y: creditInset)
        // Only a map that is given an inset takes it over from the system's automatic one.
        if viewInset != .zero || context.coordinator.insetSet, map.contentInset != viewInset {
            context.coordinator.insetSet = true
            map.automaticallyAdjustsContentInset = false
            map.setContentInset(viewInset, animated: true, completionHandler: nil)
        }
        context.coordinator.apply(self)
    }

    @MainActor
    public final class Coordinator: NSObject, MLNMapViewDelegate {
        weak var map: MLNMapView?
        var dark = false
        /// The map was given a viewInset once; it keeps managing its own from then on.
        var insetSet = false
        private var spec: CityMap?
        private var drawnLines: [Line] = []
        private var drawnPins: [Pin] = []
        private var fitKey = ""
        private var animateFit = false
        private var pendingFit: (points: [CLLocationCoordinate2D], padding: UIEdgeInsets)?
        private var styleReady = false
        /// Whether the map is close enough for stops to show their badge and name.
        let stops = StopDetail()

        func apply(_ spec: CityMap) {
            self.spec = spec
            guard let map else { return }
            map.showsUserLocation = spec.showsUser || spec.follow
            if spec.follow {
                if map.userTrackingMode != .followWithCourse { map.setUserTrackingMode(.followWithCourse, animated: true, completionHandler: nil) }
            } else {
                if map.userTrackingMode != .none { map.userTrackingMode = .none }
                let key = "\(spec.fitID)|" + spec.fit.map { String(format: "%.5f,%.5f", $0.latitude, $0.longitude) }.joined(separator: ";")
                if key != fitKey, !spec.fit.isEmpty {
                    // The first fit sets the scene; every later one flies there.
                    animateFit = !fitKey.isEmpty
                    fitKey = key
                    pendingFit = (spec.fit, spec.padding)
                    fitIfPending()
                }
            }
            if spec.pins != drawnPins { drawPins(map, spec.pins) }
            if styleReady, spec.lines != drawnLines { drawLines(map, spec.lines) }
        }

        /// The last fit and the size it was made at: a map still growing into its place (a keyboard going
        /// down as the screen opens) fits again at its full size.
        private var lastFit: (points: [CLLocationCoordinate2D], padding: UIEdgeInsets, size: CGSize, at: Date)?

        /// The camera can only fit once the map has its size.
        func fitIfPending() {
            guard let map, map.bounds.width > 0, map.bounds.height > 0 else { return }
            if let pending = pendingFit {
                pendingFit = nil
                lastFit = (pending.points, pending.padding, map.bounds.size, .now)
                fitCamera(map, pending.points, padding: pending.padding, animated: animateFit)
            } else if let last = lastFit, last.size != map.bounds.size, Date.now.timeIntervalSince(last.at) < 1.5 {
                lastFit = (last.points, last.padding, map.bounds.size, last.at)
                fitCamera(map, last.points, padding: last.padding, animated: false)
            }
        }

        private func fitCamera(_ map: MLNMapView, _ all: [CLLocationCoordinate2D], padding wanted: UIEdgeInsets, animated: Bool) {
            // Padding that leaves almost no room (a short map under a tall panel) shrinks to keep 120 pt for the way.
            var padding = wanted
            let room = map.bounds.height - padding.top - padding.bottom
            if room < 120, padding.top + padding.bottom > 0 {
                let k = max(0, map.bounds.height - 120) / (padding.top + padding.bottom)
                padding.top *= k
                padding.bottom *= k
            }
            // MapLibre throws on a point off the globe; such a point is never drawn or fitted.
            let points = all.filter { CLLocationCoordinate2DIsValid($0) && !($0.latitude == 0 && $0.longitude == 0) }
            guard !points.isEmpty else { return }
            if points.count == 1 {
                map.setCenter(points[0], zoomLevel: 15, animated: animated)
                return
            }
            var coordinates = points
            map.setVisibleCoordinates(&coordinates, count: UInt(coordinates.count), edgePadding: padding, animated: animated)
        }

        private func drawLines(_ map: MLNMapView, _ lines: [Line]) {
            guard let style = map.style else { return }
            for line in drawnLines {
                if let layer = style.layer(withIdentifier: "way-\(line.id)") { style.removeLayer(layer) }
                if let casing = style.layer(withIdentifier: "way-\(line.id)-casing") { style.removeLayer(casing) }
                if let source = style.source(withIdentifier: "way-\(line.id)") { style.removeSource(source) }
            }
            for line in lines where line.points.count > 1 {
                var points = line.points
                let shape = MLNPolylineFeature(coordinates: &points, count: UInt(points.count))
                let source = MLNShapeSource(identifier: "way-\(line.id)", shape: shape, options: nil)
                style.addSource(source)
                if !line.dashed {
                    // A white edge so a line reads on any street colour.
                    let casing = MLNLineStyleLayer(identifier: "way-\(line.id)-casing", source: source)
                    casing.lineColor = NSExpression(forConstantValue: UIColor.white)
                    casing.lineWidth = NSExpression(forConstantValue: line.width + 3)
                    casing.lineCap = NSExpression(forConstantValue: "round")
                    casing.lineJoin = NSExpression(forConstantValue: "round")
                    casing.lineOpacity = NSExpression(forConstantValue: line.faint ? 0.4 : 0.9)
                    style.addLayer(casing)
                }
                let layer = MLNLineStyleLayer(identifier: "way-\(line.id)", source: source)
                layer.lineColor = NSExpression(forConstantValue: line.color)
                layer.lineWidth = NSExpression(forConstantValue: line.width)
                layer.lineCap = NSExpression(forConstantValue: "round")
                layer.lineJoin = NSExpression(forConstantValue: "round")
                layer.lineOpacity = NSExpression(forConstantValue: line.faint ? 0.35 : 1)
                if line.dashed { layer.lineDashPattern = NSExpression(forConstantValue: [0.1, 1.8]) }
                style.addLayer(layer)
            }
            drawnLines = lines
        }

        private func drawPins(_ map: MLNMapView, _ pins: [Pin]) {
            if let old = map.annotations { map.removeAnnotations(old.filter { $0 is PinAnnotation }) }
            map.addAnnotations(pins.map(PinAnnotation.init))
            drawnPins = pins
        }

        public func mapView(_ mapView: MLNMapView, didFinishLoading style: MLNStyle) {
            styleReady = true
            drawnLines = []
            if let spec { drawLines(mapView, spec.lines) }
        }

        public func mapView(_ mapView: MLNMapView, viewFor annotation: MLNAnnotation) -> MLNAnnotationView? {
            guard let pin = (annotation as? PinAnnotation)?.pin else { return nil }
            let view = MLNAnnotationView(reuseIdentifier: nil)
            let host = UIHostingController(rootView: PinView(pin: pin, stops: stops))
            host.view.backgroundColor = .clear
            let size = host.sizeThatFits(in: CGSize(width: 240, height: 80))
            host.view.frame = CGRect(origin: .zero, size: size)
            view.frame = host.view.frame
            view.addSubview(host.view)
            // The point sits at the pin's tip (a flag), a stop's mark (its name hangs below), or its middle.
            switch pin.kind {
            case .destination: view.centerOffset = CGVector(dx: 0, dy: -size.height / 2 + 4)
            case .stop:
                view.centerOffset = CGVector(dx: 0, dy: (size.height - StopMark.mark) / 2)
                view.isUserInteractionEnabled = false
            default: break
            }
            return view
        }

        // Stops show their badge and name once the map is at street level, and back to rings when it's not.
        public func mapViewRegionIsChanging(_ mapView: MLNMapView) { closeUp(mapView) }

        public func mapView(_ mapView: MLNMapView, regionDidChangeAnimated animated: Bool) { closeUp(mapView) }

        private func closeUp(_ map: MLNMapView) {
            let near = map.zoomLevel >= StopDetail.zoom
            guard near != stops.near else { return }
            withAnimation(.smooth(duration: 0.25)) { stops.near = near }
        }

        public func mapView(_ mapView: MLNMapView, annotationCanShowCallout annotation: MLNAnnotation) -> Bool { false }
    }
}

/// A map that says when it has its size, so the camera can fit the way.
final class SizedMapView: MLNMapView {
    var laidOut: (() -> Void)?

    override func layoutSubviews() {
        super.layoutSubviews()
        laidOut?()
    }
}

/// An annotation that carries its pin.
final class PinAnnotation: NSObject, MLNAnnotation {
    let pin: CityMap.Pin
    nonisolated(unsafe) var coordinate: CLLocationCoordinate2D
    var title: String? { pin.title }

    init(_ pin: CityMap.Pin) {
        self.pin = pin
        coordinate = pin.coordinate
    }
}

/// How each pin looks: the start a blue dot, the destination our flag, a line its badge, a place its symbol.
private struct PinView: View {
    let pin: CityMap.Pin
    let stops: StopDetail

    var body: some View {
        switch pin.kind {
        case .start:
            Circle().fill(Color(red: 0.18, green: 0.44, blue: 0.82)).frame(width: 14, height: 14)
                .overlay { Circle().strokeBorder(.white, lineWidth: 3) }
                .shadow(color: .black.opacity(0.25), radius: 2, y: 1)
        case .destination:
            VStack(spacing: 2) {
                Image(systemName: "flag.fill").font(.system(size: 14, weight: .bold)).foregroundStyle(.white)
                    .frame(width: 32, height: 32)
                    .background(Color(red: 0.76, green: 0.23, blue: 0.05), in: .circle)
                    .overlay { Circle().strokeBorder(.white, lineWidth: 2.5) }
                    .shadow(color: .black.opacity(0.25), radius: 3, y: 2)
                if !pin.title.isEmpty {
                    Text(pin.title).font(.system(size: 12, weight: .bold)).lineLimit(1)
                        .padding(.horizontal, 6).padding(.vertical, 2)
                        .foregroundStyle(Self.labelInk)
                    .background(.white.opacity(0.92), in: .capsule)
                }
            }
        case let .badge(text, fill, ink):
            Text(text).font(.system(size: 12, weight: .heavy)).foregroundStyle(Color(ink))
                .padding(.horizontal, 6).frame(minWidth: 22, minHeight: 20)
                .background(Color(fill), in: .rect(cornerRadius: 5))
                .overlay { RoundedRectangle(cornerRadius: 5).strokeBorder(.white, lineWidth: 1.5) }
        case let .place(symbol):
            VStack(spacing: 2) {
                Image(systemName: symbol).font(.system(size: 13, weight: .semibold)).foregroundStyle(.white)
                    .frame(width: 28, height: 28)
                    .background(Color(red: 0.18, green: 0.44, blue: 0.82), in: .circle)
                    .overlay { Circle().strokeBorder(.white, lineWidth: 2) }
                Text(pin.title).font(.system(size: 11, weight: .semibold)).lineLimit(1)
                    .foregroundStyle(Self.labelInk)
                    .padding(.horizontal, 5).padding(.vertical, 1)
                    .background(.white.opacity(0.92), in: .capsule)
            }
        case let .stop(ring, symbol):
            StopMark(title: pin.title, ring: Color(ring), symbol: symbol, detail: stops)
        }
    }

    /// Labels sit on a white tag in light and dark maps alike, so their type stays dark.
    static let labelInk = Color(red: 0x21 / 255, green: 0x1D / 255, blue: 0x15 / 255)
}

/// How close the map is, shared by its stops.
@MainActor @Observable
final class StopDetail {
    /// Street level: a stop's badge and name can be read without crowding the map.
    static let zoom = 13.8
    var near = false
}

/// A stop: a ring in its line's colour from afar; close up, the station's badge (as the board's own
/// station has it) and its name under it.
private struct StopMark: View {
    /// The mark's box: the point sits at its middle.
    static let mark: CGFloat = 24

    let title: String
    let ring: Color
    let symbol: String
    let detail: StopDetail

    var body: some View {
        let near = detail.near
        VStack(spacing: 2) {
            ZStack {
                Circle().fill(.white).frame(width: 9, height: 9)
                    .overlay { Circle().strokeBorder(ring, lineWidth: 2.5) }
                    .opacity(near ? 0 : 1)
                Image(systemName: symbol).font(.system(size: 11, weight: .semibold)).foregroundStyle(.white)
                    .frame(width: 22, height: 22)
                    .background(Color(red: 0.18, green: 0.44, blue: 0.82), in: .circle)
                    .overlay { Circle().strokeBorder(.white, lineWidth: 2) }
                    .scaleEffect(near ? 1 : 0.4)
                    .opacity(near ? 1 : 0)
            }
            .frame(width: Self.mark, height: Self.mark)
            Text(title).font(.system(size: 11, weight: .semibold)).lineLimit(1)
                .foregroundStyle(PinView.labelInk)
                .padding(.horizontal, 5).padding(.vertical, 1)
                .background(.white.opacity(0.92), in: .capsule)
                .opacity(near ? 1 : 0)
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(title)
    }
}
#endif
