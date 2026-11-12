import API
import Core
import CoreLocation
import Maps
import DesignSystem
import SafariServices
import SwiftUI

// MARK: - The model

@Observable
@MainActor
final class TodayModel {
    enum Phase: Equatable { case loading, ready, problem(String) }

    private(set) var phase = Phase.loading
    private(set) var page: TodayPage?
    /// The departures nearest the person, read when Cologne today's fold opens.
    private(set) var board: Board?
    private let locator = OneLocation()
    private var refreshing: Task<Void, Never>?

    /// The bell's count: every note that needs the person.
    var alerts: Int { page?.needs.count ?? 0 }
    /// When the header's mark last began to wave: on opening, and on a tap of the brand.
    private(set) var waveSince: Date?
    /// A tap of the brand takes Today back to its top.
    private(set) var topRequests = 0

    func brandTapped() {
        topRequests += 1
        waveSince = .now
    }

    func start(_ api: AppAPI) async {
        await load(api)
        refreshing?.cancel()
        // The Leave card counts down; the day re-reads each minute.
        refreshing = Task { [weak self] in
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(60))
                guard let self, !Task.isCancelled else { return }
                await self.load(api, quietly: true)
            }
        }
    }

    func load(_ api: AppAPI, quietly: Bool = false, animated: Animation? = nil) async {
        let place = await locator.coordinate()
        self.place = place
        do throws(APIFailure) {
            let fresh = try await api.today(latitude: place?.latitude, longitude: place?.longitude)
            withAnimation(animated) { page = fresh }
            if phase != .ready { waveSince = .now }
            phase = .ready
            reportShown(api)
            // No trip: the Leave card's countdown in the island when it's close; a trip ended elsewhere, gone.
            if page?.trip == nil { TripActivity.shared.leave(page?.leave) }
        } catch {
            if page != nil, quietly { return }
            phase = .problem(error == .signedOut ? "You’ve been signed out." : "Couldn’t reach Expadu. Check your connection.")
        }
    }

    /// What just changed, for the note; `undo` puts it back.
    struct Note: Equatable, Identifiable {
        enum Undo: Equatable { case change(String), restore(id: String, date: String), unsave(TodayPage.Idea), signal(TodayPage.Idea), way(String) }

        let id = UUID()
        let text: String
        let symbol: String
        var undo: Undo?
        var problem = false
    }

    private(set) var note: Note?
    /// The Leave card's own note (the canvas's .lv-say): what happened to the trip or its way, with Undo.
    private(set) var cardNote: Note?
    /// Each change to the card's way: its values turn over and it glows (lvflip, lvglow).
    private(set) var cardFlips = 0
    private var cardTimer: Task<Void, Never>?

    private func sayInCard(_ next: Note) {
        withAnimation(Motion.spring) { cardNote = next }
        cardTimer?.cancel()
        cardTimer = Task { [weak self] in
            try? await Task.sleep(for: .seconds(7))
            guard !Task.isCancelled else { return }
            withAnimation(.easeOut(duration: 0.3)) { self?.cardNote = nil }
        }
    }
    /// Ideas they said aren't for them: off Today at once (the server keeps them off once Undo has passed).
    private(set) var hidden: Set<Int> = []
    /// Not now on the paused line, for this session (the server keeps it off a week).
    private(set) var pauseSetAside = false
    /// A turned-down idea's signal waits out its Undo, as the web's signalLater.
    private var pending: [Int: Task<Void, Never>] = [:]
    /// Ideas already reported shown, so each counts once a day.
    private var seen: Set<String> = []

    /// Ideas still to show: not turned down, and not the event they're already on their way to.
    var shownIdeas: [TodayPage.Idea] {
        let going = page?.trip?.to
        return (page?.ideas ?? []).filter { !hidden.contains($0.id) && $0.title != going }
    }
    /// What was added this session, so its + shows a check at once.
    private(set) var added: Set<String> = []
    private var noteTimer: Task<Void, Never>?

    private func say(_ next: Note) {
        note = next
        noteTimer?.cancel()
        noteTimer = Task { [weak self] in
            try? await Task.sleep(for: .seconds(6))
            guard !Task.isCancelled else { return }
            self?.note = nil
        }
    }

    /// One change to the day through the server, then the day re-read so it moves in place.
    func change(_ api: AppAPI, action: String, id: String? = nil, date: String? = nil, candidate: String? = nil, symbol: String,
                name: String? = nil, start: String? = nil, end: String? = nil, location: String? = nil) async {
        guard let page else { return }
        if let candidate { added.insert(candidate) }
        do throws(APIFailure) {
            let done = try await api.changeDay(revision: page.revision, action: action, id: id, date: date, candidate: candidate, name: name, start: start, end: end, location: location)
            say(Note(text: done.label ?? "Done", symbol: symbol, undo: done.undoable ? done.id.map { .change($0) } : nil))
            await load(api, quietly: true)
        } catch {
            if let candidate { added.remove(candidate) }
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
        }
    }

    func markDone(_ api: AppAPI, plan: TodayPage.Plan, date: String) async {
        guard let page else { return }
        do throws(APIFailure) {
            _ = try await api.changeDay(revision: page.revision, action: "complete", id: plan.id, date: date)
            say(Note(text: "\(plan.title) is done", symbol: "checkmark.circle.fill", undo: .restore(id: plan.id, date: date)))
            await load(api, quietly: true)
        } catch {
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
        }
    }

    func restore(_ api: AppAPI, plan: TodayPage.Plan, date: String) async {
        guard let page else { return }
        do throws(APIFailure) {
            _ = try await api.changeDay(revision: page.revision, action: "uncomplete", id: plan.id, date: date)
            say(Note(text: "\(plan.title) is back on your day", symbol: "arrow.uturn.backward"))
            await load(api, quietly: true)
        } catch {
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
        }
    }

    func editPlan(_ api: AppAPI, plan: TodayPage.Plan, date: String, name: String, start: String, end: String, place: String) async -> Bool {
        let where_ = place.trimmingCharacters(in: .whitespaces)
        await change(api, action: "edit", id: plan.id, date: date, symbol: "pencil", name: name.trimmingCharacters(in: .whitespaces),
                     start: start, end: end, location: where_.isEmpty ? nil : where_)
        return note?.problem != true
    }

    func addPlan(_ api: AppAPI, name: String, date: String, start: String, end: String, place: String) async -> Bool {
        let where_ = place.trimmingCharacters(in: .whitespaces)
        await change(api, action: "appointment", date: date, symbol: "calendar.badge.plus", name: name.trimmingCharacters(in: .whitespaces),
                     start: start, end: end, location: where_.isEmpty ? nil : where_)
        return note?.problem != true
    }

    /// Not interested, from an event's details: off Today too, until Undo.
    func hideIdea(_ id: Int, _ hide: Bool) {
        withAnimation(.snappy) { if hide { hidden.insert(id) } else { hidden.remove(id) } }
    }

    /// Add to my day from What's on: the same add Today's own button sends.
    func isAdded(_ candidate: String) -> Bool { added.contains(candidate) }

    /// A save made in What's on shows on Today's own idea too.
    func markSaved(_ candidate: String, saved: Bool) {
        guard let index = page?.ideas.firstIndex(where: { $0.candidate == candidate }) else { return }
        page?.ideas[index].saved = saved
    }

    /// The bookmark flips at once; the server's answer settles it.
    func toggleSave(_ api: AppAPI, idea: TodayPage.Idea) async {
        guard let startsAt = idea.startsAt, let index = page?.ideas.firstIndex(where: { $0.id == idea.id }) else { return }
        let saving = !idea.saved
        page?.ideas[index].saved = saving
        do throws(APIFailure) {
            _ = try await api.saveEvent(id: idea.id, startsAt: startsAt, saved: saving)
            say(Note(text: saving ? "Saved for later" : "Removed from saved", symbol: saving ? "bookmark.fill" : "bookmark",
                     undo: saving ? .unsave(idea) : nil))
        } catch {
            page?.ideas[index].saved = !saving
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
        }
    }

    /// Undo from the card's note: the way the plan had before.
    func undoCard(_ api: AppAPI) async {
        guard case let .way(undo)? = cardNote?.undo else { return }
        withAnimation(.easeOut(duration: 0.2)) { cardNote = nil }
        do throws(APIFailure) {
            try await api.undoWay(undo)
            await load(api, quietly: true)
            cardFlips += 1
            sayInCard(Note(text: "Back to the way you had.", symbol: "arrow.uturn.backward"))
        } catch {
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
        }
    }

    func undo(_ api: AppAPI) async {
        guard let undo = note?.undo, let page else { return }
        note = nil
        switch undo {
        case let .change(id):
            do throws(APIFailure) {
                _ = try await api.changeDay(revision: page.revision, action: "undo", change: id)
                added.removeAll()
                say(Note(text: "Put back", symbol: "arrow.uturn.backward"))
                await load(api, quietly: true)
            } catch {
                say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
            }
        case let .restore(id, date):
            if let plan = page.plans.first(where: { $0.id == id }) { await restore(api, plan: plan, date: date) }
        case let .unsave(idea):
            var saved = idea
            saved.saved = true
            await toggleSave(api, idea: saved)
        case let .way(undo):
            do throws(APIFailure) {
                try await api.undoWay(undo)
                say(Note(text: "Back to the way you had", symbol: "arrow.uturn.backward"))
                await load(api, quietly: true)
            } catch {
                say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
            }
        case let .signal(idea):
            pending.removeValue(forKey: idea.id)?.cancel()
            withAnimation(.snappy) { _ = hidden.remove(idea.id) }
            say(Note(text: "Put back", symbol: "arrow.uturn.backward"))
        }
    }

    /// Less like this: its kind lower from tomorrow, nothing hidden; sent once Undo has passed.
    func lessLikeThis(_ api: AppAPI, idea: TodayPage.Idea) {
        later(api, Signal(action: .lessLikeThis, event: idea.id), for: idea)
        say(Note(text: "Noted. Fewer like this from tomorrow.", symbol: "minus.circle", undo: .signal(idea)))
    }

    /// Not for me: off Today at once.
    func notForMe(_ api: AppAPI, idea: TodayPage.Idea) {
        hidden.insert(idea.id)
        later(api, Signal(action: .notForMe, event: idea.id), for: idea)
        say(Note(text: "Taken off Today.", symbol: "xmark.circle", undo: .signal(idea)))
    }

    private func later(_ api: AppAPI, _ signal: Signal, for idea: TodayPage.Idea) {
        pending[idea.id]?.cancel()
        pending[idea.id] = Task { [weak self] in
            try? await Task.sleep(for: .seconds(5))
            guard !Task.isCancelled else { return }
            try? await api.send([signal])
            self?.pending.removeValue(forKey: idea.id)
        }
    }

    /// The ideas Today showed, with their places in the list, once a day each.
    private func reportShown(_ api: AppAPI) {
        guard let page else { return }
        let fresh = Array(shownIdeas.prefix(4).enumerated()).filter { seen.insert("\(page.date):\($0.element.id)").inserted }
        guard !fresh.isEmpty else { return }
        let signals = fresh.map { Signal(action: .impression, event: $0.element.id, position: $0.offset) }
        Task { try? await api.send(signals) }
    }

    /// Where the phone was when Today was last read: trips start there, as on Today.
    /// Where the phone was when Today was last read; Getting there plans from here too.
    private(set) var place: CLLocationCoordinate2D?

    /// I'm on my way with no way to hand (the Leave card's not loaded, or the Lock Screen asked after
    /// it went): Today again, then the first way if there is one now, else why not, in words.
    func findWay(_ api: AppAPI) async {
        await load(api, quietly: true)
        if let way = page?.leave?.ways.first {
            _ = await startTrip(api, way: way, replace: false)
        } else if page?.leave == nil {
            say(Note(text: "Nothing to leave for right now. Open an event’s Getting there to start a trip.", symbol: "info.circle"))
        } else {
            say(Note(text: "No way there was found for now. Try again in a moment.", symbol: "exclamationmark.circle", problem: true))
        }
    }

    /// I'm on my way. True when another trip is running and they should be asked first.
    func startTrip(_ api: AppAPI, way: TodayPage.Way, replace: Bool) async -> Bool {
        do throws(APIFailure) {
            let started = try await api.startTrip(way: way, latitude: place?.latitude, longitude: place?.longitude, replace: replace)
            if started == .running { return true }
            cardNote = nil
            await load(api, quietly: true)
        } catch {
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
            // The way it asked for has gone (that tram left): the card shows the next one at once.
            await load(api, quietly: true)
        }
        return false
    }

    /// I'm on my way on a way Getting there planned: Today shows the trip live after it.
    func startWay(_ api: AppAPI, plan: WayPlan, way: Int, replace: Bool) async -> (running: Bool, problem: String?) {
        do throws(APIFailure) {
            let started = try await api.startWay(plan: plan.plan, way: way, replace: replace)
            if started == .running { return (true, nil) }
            await load(api, quietly: true)
            return (false, nil)
        } catch {
            return (false, Self.words(error))
        }
    }

    /// Off the way or the ride gone: our planner again, from where the phone is to the same place,
    /// and the trip goes on with the first way. Nil when it did, else why not.
    func newWay(_ api: AppAPI, trip: TodayPage.Trip, from: TodayPage.Point) async -> String? {
        guard let end = trip.legs.last?.end else { return "This trip has no place to go to." }
        do throws(APIFailure) {
            let plan = try await api.planWay(to: trip.to, latitude: end.latitude, longitude: end.longitude,
                                             fromLatitude: from.latitude, fromLongitude: from.longitude, startsAt: nil)
            guard let way = plan.ways.first else { return "No route from here right now." }
            _ = try await api.startWay(plan: plan.plan, way: 0, replace: true)
            await load(api, quietly: true)
            cardFlips += 1
            let how = way.lines.isEmpty ? "on foot" : way.legs.filter(\.rides).map(\.name).joined(separator: ", then ")
            sayInCard(Note(text: "New route: leave \(way.leaveTime), \(how), there \(way.arriveTime).", symbol: "arrow.triangle.branch"))
            return nil
        } catch {
            return Self.words(error)
        }
    }

    /// The trip being ended: true when they made it. The card shows it in place before it goes.
    private(set) var finishing: Bool?

    func endTrip(_ api: AppAPI, arrived: Bool) async {
        withAnimation(.spring(duration: 0.45, bounce: 0.25)) { finishing = arrived }
        let shown = Date.now
        do throws(APIFailure) {
            try await api.endTrip()
            TripActivity.shared.end()
        } catch {
            withAnimation(.easeOut(duration: 0.25)) { finishing = nil }
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
            return
        }
        // Let the finish be seen, then the card leaves (its own removal transition) and the day closes up.
        let left = 1.1 - Date.now.timeIntervalSince(shown)
        if left > 0 { try? await Task.sleep(for: .seconds(left)) }
        await load(api, quietly: true, animated: Motion.ios(0.5))
        // Cleared only once the card is gone, so it leaves still showing the finish.
        Task { try? await Task.sleep(for: .seconds(0.7)); finishing = nil }
        let text = arrived ? "You made it." : page?.leave?.leaveTime.map { "Trip ended. Leave at \($0) still stands." } ?? "Trip ended."
        // In the Leave card when there is one; otherwise the line over the bar.
        if page?.leave?.isKnown == true {
            cardFlips += 1
            sayInCard(Note(text: text, symbol: "checkmark"))
        } else {
            say(Note(text: text, symbol: "checkmark"))
        }
    }

    /// Use this: the card shows that way from now on, with Undo. Nil when it went through, else why not.
    func keepWay(_ api: AppAPI, way: TodayPage.Way) async -> String? {
        do throws(APIFailure) {
            let undo = try await api.keepWay(way, latitude: place?.latitude, longitude: place?.longitude)
            await load(api, quietly: true)
            // "tram 1", "tram 1, then bus 146": the rides as the canvas names them.
            let rides = way.legs.filter(\.rides).map { $0.name.prefix(1).lowercased() + $0.name.dropFirst() }
            let how = way.lines.isEmpty ? (way.mode == "bike" ? "by bike" : "on foot") : rides.isEmpty ? way.lines.map { "line \($0.line)" }.joined(separator: ", then ") : rides.joined(separator: ", then ")
            cardFlips += 1
            sayInCard(Note(text: "Changed: leave at \(way.leaveTime), \(how).", symbol: "arrow.triangle.branch", undo: .way(undo)))
            return nil
        } catch {
            await load(api, quietly: true)
            return Self.words(error)
        }
    }

    func agree(_ api: AppAPI, to pause: TodayPage.RankPause) async -> Bool {
        do throws(APIFailure) {
            try await api.agreeToOrder(pause)
            say(Note(text: "Agreed. Today is in your order again in a minute.", symbol: "checkmark.circle.fill"))
            withAnimation(.snappy) { pauseSetAside = true }
            return true
        } catch {
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
            return false
        }
    }

    func orderLater(_ api: AppAPI) async {
        withAnimation(.snappy) { pauseSetAside = true }
        try? await api.orderLater()
    }

    private static func words(_ error: APIFailure) -> String {
        switch error {
        case let .refused(why): why
        case .signedOut: "You’ve been signed out."
        case .tooManyTries: "Too many tries. Wait a moment."
        case .unreachable: "Couldn’t reach Expadu. Check your connection."
        }
    }

    func loadBoard(_ api: AppAPI) async {
        let place = await locator.coordinate() ?? OneLocation.neumarkt
        if let next = try? await api.nearby(latitude: place.latitude, longitude: place.longitude) {
            withAnimation(.snappy) { board = next }
        }
    }
}
