import API
import Core
import CoreLocation
import DesignSystem
import SwiftUI

/// Travel, as the canvas has it (Main.dc.html, simplified 6 Nov): the closest stop's name, its lines as chips,
/// and one list of departures in each line's own colour, the minutes on split flaps.
struct TravelView: View {
    @Environment(Session.self) private var session
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var model = TravelModel()

    var body: some View {
        let lead = model.leadColor
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                header.padding(.horizontal, 16).padding(.top, 8).padding(.bottom, 14)
                content
            }
        }
        .scrollIndicators(.hidden)
        .refreshable { await model.load(session.api) }
        .background(lead.ignoresSafeArea())
        .foregroundStyle(lead.onColor)
        .animation(reduceMotion ? nil : .easeInOut(duration: 0.55), value: model.leadLine)
        .task { await model.start(session.api) }
    }

    private var header: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 8) {
                Text(model.board?.name ?? "Nearby")
                    .font(.posterGrotesque(model.board?.name.count ?? 0 > 10 ? 38 : 44))
                    .textCase(.uppercase)
                    .tracking(-0.8)
                    .lineLimit(1).minimumScaleFactor(0.6)
                    .accessibilityAddTraits(.isHeader)
                Spacer(minLength: 0)
                AccountButton()
            }
            HStack(spacing: 6) {
                Circle().frame(width: 8, height: 8)
                Text(model.status).font(.geist(13, .medium))
            }
            .opacity(0.95)
            if let board = model.board, board.lines.count > 1 {
                LineChips(lines: board.lines, colors: model.colors, selected: $model.line)
                    .padding(.horizontal, -16)
                    .padding(.top, 4)
            }
        }
    }

    @ViewBuilder
    private var content: some View {
        switch model.phase {
        case .loading:
            ProgressView().tint(.white).frame(maxWidth: .infinity).padding(.top, 80)
        case let .problem(text):
            VStack(spacing: 14) {
                Text(text).font(.geist(16, .medium)).multilineTextAlignment(.center)
                Button("Try again") { Task { await model.load(session.api) } }
                    .font(.geist(16, .semibold))
                    .padding(.horizontal, 18).frame(height: 44)
                    .background(.white.opacity(0.22), in: .capsule)
            }
            .frame(maxWidth: .infinity).padding(.horizontal, 32).padding(.top, 60)
        case .ready:
            let rows = model.board?.showing(model.line) ?? []
            if rows.isEmpty {
                Text(model.line.map { "Nothing on line \($0) right now." } ?? "Nothing leaves nearby right now.")
                    .font(.geist(16, .medium)).frame(maxWidth: .infinity).padding(.top, 60)
            }
            LazyVStack(spacing: 0) {
                ForEach(rows) { row in
                    DepartureRow(departure: row).transition(.opacity)
                }
            }
            .animation(reduceMotion ? nil : .snappy(duration: 0.3), value: model.line)
            .padding(.bottom, 120)
        }
    }
}

/// The lines here as small badges; one tap filters the list, a second shows all again.
private struct LineChips: View {
    let lines: [String]
    let colors: [String: Color]
    @Binding var selected: String?

    var body: some View {
        ScrollView(.horizontal) {
            HStack(spacing: 4) {
                chip("All", color: .clear, on: selected == nil) { selected = nil }
                ForEach(lines, id: \.self) { line in
                    let color = colors[line] ?? .gray
                    chip(Board.badge(line), color: color, on: selected == line) { selected = selected == line ? nil : line }
                }
            }
            .padding(.horizontal, 16)
        }
        .scrollIndicators(.hidden)
    }

    private func chip(_ title: String, color: Color, on: Bool, tap: @escaping () -> Void) -> some View {
        Button(action: tap) {
            Text(title)
                .font(.geist(13.5, .bold)).monospacedDigit()
                .foregroundStyle(on ? Palette.text : color == .clear ? .white : color.onColor)
                .frame(minWidth: 34).frame(height: 28).padding(.horizontal, 4)
                .background(on ? Palette.posterPaper : color, in: .rect(cornerRadius: 8))
                .overlay { RoundedRectangle(cornerRadius: 8).strokeBorder(.white.opacity(on ? 0 : 0.85), lineWidth: 1.5) }
                .frame(minHeight: 44)
                .contentShape(.rect)
        }
        .buttonStyle(PressStyle())
        .accessibilityLabel(title == "All" ? "All lines" : "Line \(title)")
        .accessibilityAddTraits(on ? .isSelected : [])
    }
}

/// A line in its own colour: number, where it goes, and the next departure on two flaps.
struct DepartureRow: View {
    let departure: Board.Departure

    var body: some View {
        // KVB's colour as a gradient (LineLook), its type picked to read on it.
        let look = LineLook(hex: departure.color)
        HStack(spacing: 10) {
            Text(departure.badge)
                .font(.posterGrotesque(departure.badge.count > 2 ? 22 : 29)).tracking(-0.6)
                .lineLimit(1).minimumScaleFactor(0.7)
                .frame(width: 56, alignment: .leading)
            VStack(alignment: .leading, spacing: 1) {
                Label(departure.direction, systemImage: "arrow.right")
                    .labelStyle(Arrowed())
                    .font(.geist(17, .bold)).tracking(-0.2)
                    .lineLimit(1)
                Text(subline).font(.geist(12, .medium)).opacity(0.92).lineLimit(1)
            }
            Spacer(minLength: 4)
            HStack(spacing: 2) {
                ForEach(Array(departure.flaps.enumerated()), id: \.offset) { _, digit in
                    Flap(character: digit)
                }
            }
            .opacity(departure.cancelled ? 0.45 : 1)
        }
        .foregroundStyle(look.ink)
        .padding(.leading, 14).padding(.trailing, 16)
        .frame(height: 56)
        .background(look.fill)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(spoken)
    }

    private var subline: String {
        var parts = ["from \(departure.stop)"]
        if departure.cancelled { parts.append("cancelled") } else if departure.delay > 0 { parts.append("\(departure.delay) min late") }
        if departure.minutes.count > 1 { parts.append("then " + departure.minutes.dropFirst().map { "\($0)" }.joined(separator: ", ") + " min") }
        return parts.joined(separator: " · ")
    }

    private var spoken: String {
        let next = departure.minutes.first.map { $0 == 0 ? "now" : "in \($0) minutes" } ?? ""
        return "Line \(departure.badge) to \(departure.direction), \(next), \(subline)"
    }
}

private struct Arrowed: LabelStyle {
    func makeBody(configuration: Configuration) -> some View {
        HStack(spacing: 5) {
            configuration.icon.font(.system(size: 12, weight: .bold))
            configuration.title
        }
    }
}

/// One split-flap digit: a dark card with the hinge line across its middle.
private struct Flap: View {
    let character: Character

    var body: some View {
        Text(String(character))
            .font(.geist(29, .bold)).monospacedDigit()
            .foregroundStyle(Color(hex: 0xFFF6E3))
            .frame(width: 26, height: 40)
            .background(
                LinearGradient(colors: [Color(hex: 0x2A2620), Color(hex: 0x1B1812)], startPoint: .top, endPoint: .bottom),
                in: .rect(cornerRadius: 5)
            )
            .overlay { Rectangle().fill(.black.opacity(0.75)).frame(height: 1) }
            .contentTransition(.numericText(countsDown: true))
    }
}

@Observable
@MainActor
final class TravelModel {
    enum Phase: Equatable { case loading, ready, problem(String) }

    private(set) var board: Board?
    private(set) var phase = Phase.loading
    var line: String?
    private let locator = OneLocation()
    private var refreshing: Task<Void, Never>?

    /// The line the board takes its colour from: the chosen one, else the soonest.
    var leadLine: String? { line ?? board?.departures.first?.line }
    var leadColor: Color { colors[leadLine ?? ""] ?? Color(hex: 0xA01E5A) }
    var colors: [String: Color] {
        // The deep shade: light official colours (3, 4, 17) still carry the page's white type.
        Dictionary(board?.departures.map { ($0.line, LineLook(hex: $0.color).deep) } ?? [], uniquingKeysWith: { first, _ in first })
    }

    var status: String {
        switch phase {
        case .loading: return "Finding the closest stop…"
        case .problem: return "Times unavailable right now"
        case .ready:
            guard let stop = board?.stops.first else { return "Live times" }
            return "\(stop.walkMinutes) min walk · Live times"
        }
    }

    func start(_ api: AppAPI) async {
        await load(api)
        refreshing?.cancel()
        // The board keeps itself current, as a station's does.
        refreshing = Task { [weak self] in
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(30))
                guard let self, !Task.isCancelled else { return }
                await self.load(api, quietly: true)
            }
        }
    }

    func load(_ api: AppAPI, quietly: Bool = false) async {
        if board == nil && !quietly { phase = .loading }
        let place = await locator.coordinate() ?? OneLocation.neumarkt
        do throws(APIFailure) {
            let next = try await api.nearby(latitude: place.latitude, longitude: place.longitude)
            withAnimation(.snappy) { board = next }
            if let line, !next.lines.contains(line) { self.line = nil }
            phase = .ready
        } catch {
            if board == nil {
                phase = .problem(error == .signedOut ? "You’ve been signed out." : "Couldn’t reach Expadu. Check your connection.")
            }
        }
    }
}

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

extension Color {
    /// KVB's colours come from the server as `#RRGGBB`.
    init(hexString: String) {
        self.init(hex: UInt32(hexString.trimmingCharacters(in: CharacterSet(charactersIn: "#")), radix: 16) ?? 0x1A4CD4)
    }

    /// Ink that reads on this colour: dark on light lines (12, 15), white on the rest.
    var onColor: Color {
        let ui = UIColor(self)
        var r: CGFloat = 0, g: CGFloat = 0, b: CGFloat = 0, a: CGFloat = 0
        ui.getRed(&r, green: &g, blue: &b, alpha: &a)
        let luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b
        return luminance > 0.55 ? Color(hex: 0x1D2A05) : .white
    }
}
