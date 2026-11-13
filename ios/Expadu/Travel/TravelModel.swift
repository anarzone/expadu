import API
import Core
import CoreLocation
import DesignSystem
import SwiftUI

/// Travel (the canvas's Main.dc.html): one station's board in its lines' colours, a sheet for one
/// departure, the map of the lines, the stop picker, and KVB's notice. Plan a trip and its routes
/// have their own model (TripPlanner).
@Observable
@MainActor
final class TravelModel {
    enum Phase: Equatable {
        /// No station yet: asking the phone where it is.
        case finding
        /// The phone won't say, and no stop was picked.
        case noLocation
        case ready
        case problem(String)
    }

    /// One departure open in the sheet: a line one way and which of its next runs.
    struct Open: Equatable {
        let line: String
        let direction: Int
        var run: Int
    }

    private(set) var reply: StationReply?
    private(set) var phase = Phase.finding
    /// The last read didn't get through (underground the signal comes and goes): the board stays up,
    /// its minutes counting down, and is asked again sooner. Never swapped for an error.
    private(set) var unreached = false
    /// The line the rail filters by; nil shows them all.
    var line: String?
    var open: Open?
    var map = false
    var picker = false
    var notice: Station.Notice?
    private(set) var now = Date()
    /// When the board was last read.
    private var readAt = Date()
    private(set) var place: CLLocationCoordinate2D?
    /// The board's stop came from a rough fix; it may not be the stop they're at.
    private(set) var placeRough = false
    /// The latest fix was rough (kept the board as it was, or built it from a guess).
    private(set) var roughNow = false
    /// A stop they picked; nil follows where they are.
    private(set) var picked: String?
    /// The stop shown while its board loads: the picked one.
    var loadingName: String? { reply == nil ? picked : nil }
    private(set) var linesMap: LinesMap?
    private(set) var routes: [String: LineRoute] = [:]
    private(set) var stopBoards: [String: LineStop] = [:]
    private var reading: Set<String> = []
    private let locator = OneLocation()
    private var refreshing: Task<Void, Never>?

    var station: Station? { reply?.station }
    var favourite: Bool { reply?.favourite ?? false }

    // MARK: The board

    /// Every departure here, soonest first, filtered by the rail's line.
    var departures: [Departure] {
        guard let station else { return [] }
        var all = station.departures(now: now)
        // A quiet stop (a line or two) would show one row each way and an empty board: its next few runs show instead.
        if all.count < 5 { all = station.departures(now: now, runs: 3) }
        guard let line else { return all }
        return all.filter { $0.line.line == line }
    }

    /// The line the board takes its colour from: the open one, the rail's, else the soonest.
    var leadLine: Station.Line? {
        if let open, let line = station?.lines.first(where: { $0.line == open.line }) { return line }
        if let line, let found = station?.lines.first(where: { $0.line == line }) { return found }
        return station?.departures(now: now).first?.line
    }

    var lead: LineLook { LineLook(hex: leadLine?.color ?? "#A01E5A") }

    func look(_ line: String) -> LineLook {
        LineLook(hex: station?.lines.first { $0.line == line }?.color ?? "#4A4A4A")
    }

    /// "Closest · Live times", "Scheduled · not live", or where the times stand.
    var subLine: String {
        guard let station else { return "" }
        let basis: String = if unreached {
            "No signal · times from " + (station.observedAt.flatMap(Self.clock) ?? Self.clock(at: readAt))
        } else {
            switch station.timeBasis {
            case .realtime where !station.isStale: "Live times"
            case .stale, .realtime: "Not live · last times " + (station.observedAt.flatMap(Self.clock) ?? "")
            case .scheduled: "Scheduled · not live"
            case .unavailable: "Times unavailable right now"
            }
        }
        if line != nil || map { return basis }
        if picked == nil, placeRough { return "Location rough · tap the name to pick your stop" }
        let source = picked != nil ? "Picked" : station.walkMin.map { "\($0) min walk" } ?? "Closest"
        return "\(source) · \(basis)"
    }

    var live: Bool { station?.timeBasis == .realtime && station?.isStale == false }

    /// The board's problem is the connection (nothing read yet), not the stop.
    var signalLost: Bool { phase == .problem("No signal right now") }

    /// Each line's next run from here, for the rail.
    func nextMinutes(_ line: String) -> Int? {
        station?.departures(now: now).first { $0.line.line == line && !$0.run.cancelled }?.run.minutes(from: now)
    }

    // MARK: Loading

    func start(_ api: AppAPI) async {
        if reply == nil { await load(api) }
        refreshing?.cancel()
        // The board keeps itself current, as a station's does; its minutes count down between reads.
        refreshing = Task { [weak self] in
            var tick = 0
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(15))
                guard let self, !Task.isCancelled else { return }
                self.now = .now
                tick += 1
                // Every other tick; every tick while the last read didn't get through.
                if tick % 2 == 0 || self.unreached || self.reply == nil { await self.load(api, quietly: true) }
            }
        }
    }

    func stop() {
        refreshing?.cancel()
        refreshing = nil
    }

    func load(_ api: AppAPI, quietly: Bool = false) async {
        if picked == nil {
            if !quietly && reply == nil { phase = .finding }
            let fix = await locator.fix()
            if let fix {
                let rough = fix.horizontalAccuracy > OneLocation.rough
                // A rough fix (underground, between tall buildings) never replaces a stop found from a good one.
                if rough, reply?.station != nil, !placeRough {
                    roughNow = true
                } else {
                    place = fix.coordinate
                    placeRough = rough
                    roughNow = rough
                }
            }
            guard let place else {
                if reply == nil { phase = .noLocation }
                return
            }
            await read(api, quietly: quietly) { () async throws(APIFailure) -> StationReply in try await api.station(latitude: place.latitude, longitude: place.longitude) }
        } else if let picked {
            await read(api, quietly: quietly) { () async throws(APIFailure) -> StationReply in try await api.station(named: picked, latitude: self.place?.latitude, longitude: self.place?.longitude) }
        }
    }

    private func read(_ api: AppAPI, quietly: Bool, _ fetch: () async throws(APIFailure) -> StationReply) async {
        do throws(APIFailure) {
            let next = try await fetch()
            now = .now
            readAt = now
            withAnimation(.snappy) {
                unreached = false
                reply = next
                if let line, next.station?.lines.contains(where: { $0.line == line }) != true { self.line = nil }
                phase = next.station == nil ? (picked == nil ? .problem("No KVB stop near you") : .problem("No departures from \(picked ?? "") in the feed")) : .ready
            }
        } catch {
            now = .now
            if error == .signedOut {
                phase = .problem("You’ve been signed out.")
            } else if reply?.station != nil {
                // A board is up (a pull to refresh, Try again, the timer): it stays, and says it's not
                // been reached; the times it has still count down.
                withAnimation(.snappy) { unreached = true }
            } else if reply == nil || !quietly {
                // Nothing to show yet: say why plainly, and keep asking (every tick, see start).
                phase = .problem("No signal right now")
            }
        }
    }

    /// Asks again for where they are (the "Where are you?" state).
    func useLocation(_ api: AppAPI) async {
        phase = .finding
        await load(api)
    }

    /// Another station: its board, from the top, with nothing open.
    func go(to name: String?, _ api: AppAPI) async {
        withAnimation(.snappy) {
            picked = name
            line = nil
            open = nil
            map = false
            picker = false
            linesMap = nil
            reply = nil
            phase = .finding
        }
        await load(api)
    }

    func toggleFavourite(_ api: AppAPI) async {
        guard let station, let reply else { return }
        let on = !reply.favourite
        self.reply = StationReply(station: station, favourite: on, stepFreeNeed: reply.stepFreeNeed, deutschlandticket: reply.deutschlandticket)
        do throws(APIFailure) { _ = try await api.favouriteStop(station.name, on: on) } catch {
            self.reply = reply
        }
    }

    // MARK: One departure

    /// The ways of a line here, as the sheet's other-direction button turns between them.
    func directions(of line: String) -> [Int] {
        let dirs = station?.lines.first { $0.line == line }?.dirs ?? []
        var seen: [Int] = []
        for dir in dirs where !seen.contains(dir.direction ?? 0) { seen.append(dir.direction ?? 0) }
        return seen
    }

    /// A line's runs one way, soonest first (its dirs that end in different places, merged).
    func runs(line: String, direction: Int) -> [(dir: Station.Direction, run: Station.Run)] {
        let dirs = station?.lines.first { $0.line == line }?.dirs.filter { ($0.direction ?? 0) == direction } ?? []
        return dirs.flatMap { dir in dir.runs.map { (dir, $0) } }
            .sorted { ($0.run.date ?? .distantFuture) < ($1.run.date ?? .distantFuture) }
    }

    func openDeparture(_ departure: Departure, _ api: AppAPI) {
        let direction = departure.dir.direction ?? 0
        let index = runs(line: departure.line.line, direction: direction).firstIndex { $0.run.id == departure.run.id } ?? 0
        let next = Open(line: departure.line.line, direction: direction, run: index)
        withAnimation(.smooth(duration: 0.45)) { open = open == next ? nil : next }
        Task { await route(departure.line.line, api) }
    }

    func turnDirection(_ api: AppAPI) {
        guard let open else { return }
        let ways = directions(of: open.line)
        guard ways.count > 1, let i = ways.firstIndex(of: open.direction) else { return }
        withAnimation(.smooth(duration: 0.35)) { self.open = Open(line: open.line, direction: ways[(i + 1) % ways.count], run: 0) }
    }

    func route(_ line: String, _ api: AppAPI) async {
        guard let station, routes[line] == nil else { return }
        do throws(APIFailure) {
            if let route = try await api.lineRoute(line, stop: station.name, latitude: place?.latitude, longitude: place?.longitude) {
                routes[line] = route
            }
        } catch {}
    }

    /// The stops a run goes on to from here, and the ones before, from the timetable's route.
    func stops(for dir: Station.Direction, line: String) -> (before: [LineRoute.Stop], after: [LineRoute.Stop])? {
        guard let route = routes[line], let station else { return nil }
        let names = [dir.dest] + dir.via
        for way in route.ways {
            guard let here = way.stops.firstIndex(where: { $0.here || Self.same($0.name, station.name) }) else { continue }
            let after = Array(way.stops[(here + 1)...])
            // The way whose next stops are this run's via, or which ends where it goes.
            if after.contains(where: { stop in names.contains { Self.same(stop.name, $0) } }) || Self.same(way.towards, dir.dest) {
                return (Array(way.stops[..<here]), after)
            }
        }
        return nil
    }

    /// A stop's own board on this line, read once as its row shows.
    func readStop(_ name: String, line: String, _ api: AppAPI) async {
        let key = "\(line)|\(name)"
        guard stopBoards[key] == nil, !reading.contains(key) else { return }
        reading.insert(key)
        defer { reading.remove(key) }
        do throws(APIFailure) {
            if let board = try await api.lineStop(line, stop: name) { stopBoards[key] = board }
        } catch {}
    }

    /// When this run reaches a stop further on: that stop's first run this way at or after it leaves here.
    func time(at stop: String, line: String, ahead: [String], after: Date) -> (time: String, live: Bool, date: Date)? {
        guard let board = stopBoards["\(line)|\(stop)"] else { return nil }
        let towards = board.dirs.filter { dir in ([dir.dest] + dir.via).contains { name in ahead.contains { Self.same($0, name) } } }
        let pick = (towards.isEmpty ? board.dirs : towards).flatMap(\.runs)
            .filter { !$0.cancelled && ($0.date ?? .distantPast) >= after }
            .sorted { ($0.date ?? .distantFuture) < ($1.date ?? .distantFuture) }
        return pick.first.flatMap { run in run.date.map { (run.time, run.timeBasis.isLive && !board.isStale, $0) } }
    }

    func board(_ name: String, line: String) -> Bool { stopBoards["\(line)|\(name)"] != nil }

    // MARK: Map

    func toggleMap(_ api: AppAPI) async {
        withAnimation(.smooth(duration: 0.4)) { map.toggle() }
        if map, linesMap == nil, let station {
            do throws(APIFailure) {
                linesMap = try await api.linesMap(stop: station.name, latitude: place?.latitude, longitude: place?.longitude)
            } catch {}
        }
    }

    func showOnMap(_ line: String, _ api: AppAPI) async {
        withAnimation(.smooth(duration: 0.4)) { self.line = line }
        if !map { await toggleMap(api) }
    }

    // MARK: Helpers

    static func same(_ a: String, _ b: String) -> Bool {
        let x = plain(a), y = plain(b)
        return !x.isEmpty && !y.isEmpty && (x == y || x.contains(y) || y.contains(x))
    }

    private static func plain(_ text: String) -> String {
        text.lowercased()
            .folding(options: .diacriticInsensitive, locale: .init(identifier: "de"))
            .replacingOccurrences(of: "köln ", with: "")
            .replacingOccurrences(of: "koln ", with: "")
            .replacingOccurrences(of: "str.", with: "strasse")
            .replacingOccurrences(of: "straße", with: "strasse")
            .filter { $0.isLetter || $0.isNumber }
    }

    static func clock(_ iso: String) -> String? {
        let f = ISO8601DateFormatter()
        guard let date = f.date(from: iso) else { return nil }
        return date.formatted(.dateTime.hour(.twoDigits(amPM: .omitted)).minute(.twoDigits))
    }

    static func clock(at date: Date) -> String {
        date.formatted(.dateTime.hour(.twoDigits(amPM: .omitted)).minute(.twoDigits))
    }
}
