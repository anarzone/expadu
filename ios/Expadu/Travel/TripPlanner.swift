import API
import Core
import CoreLocation
import SwiftUI

/// Plan a trip and its routes (the canvas's "Plan a trip" page and the printed route tickets): where
/// from and to, when, what matters, the ways in the order you'd pick them, Add to plan, and I'm on
/// my way. The order is the web's own (resources/js/components/travel/plan/plan-model.ts).
@Observable
@MainActor
final class TripPlanner {
    enum Field { case from, to }

    enum Pref: String, CaseIterable, Identifiable {
        case fewer, lessWalk, avoidDelays, stepfree
        var id: String { rawValue }
        var label: String {
            switch self {
            case .fewer: "Fewer changes"
            case .lessWalk: "Less walking"
            case .avoidDelays: "Avoid delays"
            case .stepfree: "Step-free"
            }
        }
    }

    enum Sort: Int, CaseIterable, Identifiable {
        case best, arrival, duration, walking, changes
        var id: Int { rawValue }
        var label: String {
            switch self {
            case .best: "Best route"
            case .arrival: "Earliest arrival"
            case .duration: "Shortest journey"
            case .walking: "Least walking"
            case .changes: "Fewest changes"
            }
        }
    }

    enum When: Equatable {
        case now
        case leave(Date)
        case arrive(Date)
    }

    /// One way as the deck shows it: the journey, its place in the answer, the lifts out on it.
    struct Option: Identifiable, Equatable {
        let index: Int
        let journey: TripPlan.Journey
        let lifts: [TripPlan.Lift]
        var id: Int { index }
    }

    // The page
    var shown = false
    var showTrip = false
    /// Leave at (Start trip, Other routes) for the next trip on their day, from Today or the board.
    var leaveOpen = false
    /// Other routes was picked in Leave at: Plan a trip opens for it once the sheet has gone.
    var otherRoutesFor: TodayPage.Leave?
    /// The trip opens on its map (the board card's hold menu), once.
    var tripOnMap = false
    var field = Field.to
    var query = ""
    var listening = false
    /// From: nil is where the phone is.
    var from: TripPoint?
    var fromStation: String?
    var when = When.now
    var prefs: Set<Pref> = []
    private(set) var places = TravelPlaces()
    private(set) var suggestions: [TripSuggestion] = []
    private(set) var searched = false
    var homeSheet: String?

    // The routes
    private(set) var destination: TripPoint?
    /// A plan on their day it goes to: its start is the deadline, and Add to plan keeps the way for it.
    private(set) var target: TravelPlaces.Plan?
    private(set) var answer: TripPlan?
    private var raw: Data?
    private(set) var loading = false
    private(set) var loadStep = 0
    private(set) var problem: String?
    var sort = Sort.best
    private(set) var more = false
    private(set) var added: [Int: PlannedTrip] = [:]
    var savedSheet: PlannedTrip?
    var ask: Option?
    var deutschlandticket = false
    var startingProblem: String?
    /// The way being added or started right now, so its ticket can say so.
    private(set) var busy: Int?

    var routesShown: Bool { destination != nil }
    @ObservationIgnored let follower = PhoneFollow()
    @ObservationIgnored var following = false
    private let locator = OneLocation()
    private var here: CLLocationCoordinate2D?

    // MARK: Opening

    /// The board's stop when Plan a trip opened from Travel (its ★ and the lines straight from it); nil elsewhere.
    private(set) var station: Station?
    private(set) var stationFavourite = false

    func open(from station: Station?, favourite: Bool = false, listening: Bool = false) {
        self.station = station
        stationFavourite = favourite
        fromStation = station?.name
        field = .to
        query = ""
        self.listening = listening
        destination = nil
        target = nil
        answer = nil
        shown = true
    }

    /// Other ways for the next trip on their day: its routes, Add to plan kept for it. The plan's point
    /// comes with Travel's places, so it's found once they're read.
    private var pendingPlan: (id: String?, title: String)?

    func openPlan(_ leave: TodayPage.Leave) {
        station = nil
        fromStation = nil
        field = .to
        query = ""
        listening = false
        answer = nil
        destination = nil
        target = nil
        pendingPlan = (leave.planId, leave.to)
        shown = true
    }

    func loadPlaces(_ api: AppAPI) async {
        do throws(APIFailure) { places = try await api.travelPlaces() } catch {}
        here = await locator.coordinate()
        if let pending = pendingPlan {
            pendingPlan = nil
            if let plan = places.today.first(where: { $0.id == pending.id }) ?? places.today.first(where: { $0.title == pending.title }) {
                await go(to: TripPoint(name: plan.place ?? plan.title, lat: plan.lat, lng: plan.lng), target: plan, api)
            }
        }
    }

    func suggest(_ api: AppAPI) async {
        let q = query.trimmingCharacters(in: .whitespaces)
        guard q.count >= 2 else { suggestions = []; searched = false; return }
        try? await Task.sleep(for: .milliseconds(220))
        guard !Task.isCancelled else { return }
        do throws(APIFailure) {
            suggestions = try await api.tripSuggestions(q, role: field == .from ? "origin" : "destination")
        } catch { suggestions = [] }
        searched = true
    }

    func setFrom(_ end: TripPoint?) {
        withAnimation(.snappy) {
            from = end
            field = .to
            query = ""
        }
    }

    // MARK: Routes

    /// The routes to a place from anywhere (an event's Getting there, a Composer card): from where the
    /// phone is, arriving five minutes before it starts when it has a time, else leaving now. The same
    /// routes, detail and Start trip as Travel's.
    func routes(to end: TripPoint, startsAt: Date?, _ api: AppAPI) async {
        station = nil
        fromStation = nil
        from = nil
        when = startsAt.map { .arrive($0.addingTimeInterval(-5 * 60)) } ?? .now
        // From where the phone is (Plan a trip reads it as it opens; this way in skips that page).
        if here == nil { here = await locator.coordinate() }
        await go(to: end, api)
    }

    func go(to end: TripPoint, target: TravelPlaces.Plan? = nil, _ api: AppAPI) async {
        withAnimation(.smooth(duration: 0.4)) {
            destination = end
            self.target = target
            answer = nil
            added = [:]
            more = false
            sort = .best
        }
        await plan(api)
    }

    func plan(_ api: AppAPI) async {
        guard let destination else { return }
        loading = true
        problem = nil
        loadStep = 0
        // What's being checked, as the flaps say it, while the planner works.
        let steps = Task { @MainActor in
            for step in 1...2 {
                try? await Task.sleep(for: .milliseconds(700))
                guard !Task.isCancelled else { return }
                withAnimation(.snappy) { self.loadStep = step }
            }
        }
        defer { steps.cancel() }
        let start = from ?? here.map { TripPoint(name: "Your location", lat: $0.latitude, lng: $0.longitude) }
        let asked: AppAPI.TripWhen = switch when {
        case .now: .now
        case let .leave(date): .leave(date)
        case let .arrive(date): .arrive(date)
        }
        do throws(APIFailure) {
            // Going to a plan on their day with no time picked: there by its start, with a margin.
            let byPlan: AppAPI.TripWhen? = when == .now ? target?.startDate.map { .arrive($0.addingTimeInterval(-5 * 60)) } : nil
            let reply = try await api.planTrip(to: destination, from: start, when: byPlan ?? asked, more: more)
            withAnimation(.smooth(duration: 0.45)) {
                answer = reply.plan
                raw = reply.raw
                loading = false
                if reply.plan.journeys.isEmpty { problem = "No routes right now" }
            }
        } catch {
            withAnimation(.smooth) {
                loading = false
                switch error {
                case let .refused(text): problem = text
                default: problem = "Couldn’t reach the planner. Try again in a moment."
                }
            }
        }
    }

    func showMore(_ api: AppAPI) async {
        more.toggle()
        await plan(api)
    }

    func closeRoutes() {
        withAnimation(.smooth(duration: 0.4)) {
            destination = nil
            target = nil
            answer = nil
        }
    }

    /// The ways in the order you'd pick them (the web's rankOptions).
    var options: [Option] {
        guard let answer else { return [] }
        let all = answer.journeys.enumerated().map { i, journey in
            Option(index: i, journey: journey, lifts: answer.lifts.indices.contains(i) ? answer.lifts[i] : [])
        }
        func minutes(_ iso: String) -> Double { (TravelJSONAccessDates.date(iso)?.timeIntervalSince1970 ?? 0) / 60 }
        func walking(_ j: TripPlan.Journey) -> Int { j.mode != "transit" ? j.durationMin : j.legs.filter { $0.mode == "walk" && $0.distanceM != 0 }.reduce(0) { $0 + $1.durationMin } }
        func delay(_ j: TripPlan.Journey) -> Int? {
            let live = j.rides.filter { $0.realtime == true }
            return live.isEmpty ? nil : live.map { $0.delayMin ?? 0 }.max()
        }
        func penalty(_ o: Option) -> Int {
            (prefs.contains(.fewer) ? o.journey.transfers * 15 : 0)
                + (prefs.contains(.lessWalk) ? walking(o.journey) : 0)
                + (prefs.contains(.avoidDelays) ? (o.journey.cancelled ? 60 : 0) + max(0, delay(o.journey) ?? 0) * 3 : 0)
                + (prefs.contains(.stepfree) && !o.lifts.isEmpty ? 60 : 0)
        }
        let by = target?.startDate.map { $0.timeIntervalSince1970 / 60 }
        let margin = 5.0
        let byDeadline = (sort == .arrival || sort == .best) && by != nil
        func fit(_ o: Option) -> Int {
            guard let by else { return 0 }
            let spare = by - minutes(o.journey.arriveAt)
            return spare >= margin ? 0 : spare >= 0 ? 1 : 2
        }
        func key(_ o: Option) -> Double {
            switch sort {
            case .best: 0
            case .arrival: minutes(o.journey.arriveAt)
            case .duration: Double(o.journey.durationMin)
            case .walking: Double(walking(o.journey))
            case .changes: Double(o.journey.transfers)
            }
        }
        let sorted = all.sorted { a, b in
            let order = penalty(a) - penalty(b)
            if order != 0 { return order < 0 }
            if byDeadline, fit(a) != fit(b) { return fit(a) < fit(b) }
            if byDeadline {
                let leave = minutes(b.journey.departAt) - minutes(a.journey.departAt)
                let there = minutes(a.journey.arriveAt) - minutes(b.journey.arriveAt)
                let first = fit(a) == 0 ? (leave != 0 ? leave : there) : (there != 0 ? there : leave)
                return first < 0
            }
            if sort == .best { return a.index < b.index }
            let k = key(a) - key(b)
            return k != 0 ? k < 0 : minutes(a.journey.departAt) < minutes(b.journey.departAt)
        }
        guard sort == .best else { return sorted }
        // Each different way once, then the next runs of the ways already listed.
        var seen = Set<String>(), different: [Option] = [], again: [Option] = []
        for option in sorted {
            let way = option.journey.mode + ":" + option.journey.lines.joined(separator: ">")
            if seen.contains(way) { again.append(option) } else { different.append(option) }
            seen.insert(way)
        }
        return different + again
    }

    /// Minutes to spare before the plan they're going to, when there is one.
    func spare(_ journey: TripPlan.Journey) -> Int? {
        guard let start = target?.startDate, let there = journey.arriveDate else { return nil }
        return Int((start.timeIntervalSince(there) / 60).rounded(.down))
    }

    // MARK: Keeping and going

    func addToPlan(_ option: Option, _ api: AppAPI) async {
        guard busy == nil, let target, let journey = raw.flatMap({ TripPlan.raw($0, index: option.index) }) else { return }
        busy = option.index
        defer { busy = nil }
        if let kept = added[option.index] {
            withAnimation(.snappy) { added[option.index] = nil }
            do throws(APIFailure) { try await api.removePlannedTrip(kept.id) } catch { withAnimation(.snappy) { added[option.index] = kept } }
            return
        }
        do throws(APIFailure) {
            let planned = try await api.planTrip(journey: journey, date: Self.day(target.startDate ?? .now), target: target.id, remind: true)
            withAnimation(.snappy) { added[option.index] = planned }
            savedSheet = planned
        } catch {
            if case let .refused(text) = error { startingProblem = text } else { startingProblem = "Couldn’t add it (\(error)). Try again in a moment." }
        }
    }

    func remind(_ on: Bool, _ api: AppAPI) async {
        guard let kept = savedSheet else { return }
        do throws(APIFailure) {
            let next = try await api.remindPlannedTrip(kept.id, remind: on)
            savedSheet = next
            if let i = added.first(where: { $0.value.id == kept.id })?.key { added[i] = next }
        } catch {}
    }

    func undoAdd(_ planned: PlannedTrip, _ api: AppAPI) async {
        if let i = added.first(where: { $0.value.id == planned.id })?.key { withAnimation(.snappy) { added[i] = nil } }
        do throws(APIFailure) { try await api.removePlannedTrip(planned.id) } catch {}
    }

    /// I'm on my way on this way. Another trip running asks first.
    func start(_ option: Option, replace: Bool, _ api: AppAPI, today: TodayModel) async -> Bool {
        guard busy == nil, let destination, let journey = raw.flatMap({ TripPlan.raw($0, index: option.index) }) else { return false }
        busy = option.index
        defer { busy = nil }
        let start = from ?? here.map { TripPoint(name: "Your location", lat: $0.latitude, lng: $0.longitude) }
        do throws(APIFailure) {
            let result = try await api.startTravelTrip(journey: journey, to: destination, from: start,
                                                       plan: target.map { (Self.day($0.startDate ?? .now), $0.id) }, replace: replace)
            switch result {
            case .started:
                await today.load(api, quietly: true)
                withAnimation(.smooth(duration: 0.45)) {
                    shown = false
                    self.destination = nil
                    answer = nil
                    showTrip = true
                }
                return true
            case .busy:
                ask = option
                return false
            }
        } catch {
            startingProblem = "Couldn’t start the trip. Try again in a moment."
            return false
        }
    }

    func setTicket(_ held: Bool, _ api: AppAPI) async {
        deutschlandticket = held
        do throws(APIFailure) { try await api.setDeutschlandticket(held) } catch {}
    }

    func saveHome(_ category: String, hit: TripSuggestion, _ api: AppAPI) async {
        do throws(APIFailure) {
            _ = try await api.setTravelPlace(category, name: category == "home" ? "Home" : "Work", address: hit.area.map { "\(hit.name), \($0)" } ?? hit.name,
                                             latitude: hit.lat, longitude: hit.lng)
            places = try await api.travelPlaces()
        } catch {}
    }

    static func day(_ date: Date) -> String {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TimeZone(identifier: "Europe/Berlin") ?? .current
        let c = calendar.dateComponents([.year, .month, .day], from: date)
        return String(format: "%04d-%02d-%02d", c.year ?? 2026, c.month ?? 1, c.day ?? 1)
    }

    var whenLabel: String {
        switch when {
        case .now: "Leave now"
        case let .leave(date): "Leave at " + Self.clock(date)
        case let .arrive(date): "Be there by " + Self.clock(date)
        }
    }

    static func clock(_ date: Date) -> String {
        date.formatted(.dateTime.hour(.twoDigits(amPM: .omitted)).minute(.twoDigits))
    }
}

/// Dates from the server's ISO times.
enum TravelJSONAccessDates {
    static func date(_ iso: String) -> Date? {
        let f = ISO8601DateFormatter()
        return f.date(from: iso)
    }
}
