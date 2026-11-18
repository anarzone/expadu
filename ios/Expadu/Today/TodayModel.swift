import API
import OSLog
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
        // After an action (a quiet reload) the place already known will do, and a fresher one is found
        // behind it for next time: asking the phone first waited up to 5 s for a precise fix, indoors
        // nearly always, and held Start trip on "Starting…" until it read as a tap that did nothing
        // (the owner, 14 Nov). A full load asks the phone.
        let recent = placeAt.map { Date.now.timeIntervalSince($0) < 120 } ?? false
        let place: CLLocationCoordinate2D?
        if quietly, let known = self.place {
            place = known
            if !recent {
                Task { [weak self] in
                    guard let self, let fresh = await self.locator.coordinate() else { return }
                    self.place = fresh
                    self.placeAt = .now
                }
            }
        } else {
            place = await locator.coordinate()
            if place != nil { placeAt = .now }
        }
        self.place = place
        do throws(APIFailure) {
            // Their calendar's busy times only, if they turned it on (MyCalendar): never what's in it.
            var fresh = try await api.today(latitude: place?.latitude, longitude: place?.longitude, busy: MyCalendar.shared.busyHeader())
            // A trip being started stays shown until the server answers for it.
            if let starting { fresh.trip = starting }
            // The island shows the trip the server has, before the guidance hears of it: one ended
            // anywhere (here, the web, arrived) goes from the Lock Screen too.
            TripActivity.shared.running(fresh.trip)
            withAnimation(animated) { page = fresh }
            if phase != .ready { waveSince = .now }
            phase = .ready
            reportShown(api)
            // No trip: the Leave card's countdown in the island when it's close.
            if page?.trip == nil { TripActivity.shared.leave(page?.leave) }
        } catch {
            if page != nil, quietly { return }
            phase = .problem(error == .signedOut ? "You’ve been signed out." : "Couldn’t reach Expadu. Check your connection.")
        }
    }

    /// What just changed, for the note; `undo` puts it back.
    struct Note: Equatable, Identifiable {
        enum Undo: Equatable { case change(String), restore(id: String, date: String), unsave(TodayPage.Idea), signal(TodayPage.Idea), way(String), ride(String), trip(String), routine(Int) }

        let id = UUID()
        let text: String
        let symbol: String
        var undo: Undo?
        var problem = false
    }

    private(set) var note: Note?
    /// Routines from their trips: the one to offer, and theirs (GET /routines).
    var routines: RoutinesState?
    /// The routine just set from the offer, shown where the offer was.
    var routineSet: RoutinesState.Routine?
    /// The Leave card's own note (the canvas's .lv-say): what happened to the trip or its way, with Undo.
    private(set) var cardNote: Note?
    /// Each change to the card's way: its values turn over and it glows (lvflip, lvglow).
    private(set) var cardFlips = 0
    private var cardTimer: Task<Void, Never>?

    private func sayInCard(_ next: Note, seconds: Int = 7) {
        withAnimation(Motion.spring) { cardNote = next }
        cardTimer?.cancel()
        cardTimer = Task { [weak self] in
            try? await Task.sleep(for: .seconds(seconds))
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

    // MARK: Trip sync: the tram they're really on

    /// Unsure which tram they're on (the 16 and the 18 a minute apart): asked in place, one tap each.
    struct RideChoice: Equatable {
        let index: Int
        let from: String
        let at: Date
        let bus: Bool
        let rides: [AppAPI.TripRide]
    }

    private(set) var rideChoice: RideChoice?
    /// Rides already settled this trip (switched, chosen, or waved off): never asked again.
    private var settledRides: Set<String> = []

    /// The phone left a ride's stop at another time than planned: which tram was it? Sure, the trip
    /// follows it with Undo in the card's note; unsure, asked in place; the planned one late, nothing.
    func rideLeft(_ api: AppAPI, leg: TodayPage.Trip.Leg, index: Int, at left: Date) async {
        let key = "\(leg.from)|\(leg.departTime)"
        guard !settledRides.contains(key) else { return }
        do throws(APIFailure) {
            let found = try await api.tripRideCheck(leg: index, from: leg.from, at: leg.departAt, leftAt: left)
            PhoneFollow.log.info("which tram: \(String(describing: found), privacy: .public)")
            switch found {
            case .planned, .unknown:
                settledRides.insert(key)
            case let .sure(ride):
                settledRides.insert(key)
                await takeRide(api, ride, index: index, from: leg.from, at: leg.departAt)
            case let .choose(rides):
                withAnimation(Motion.spring) { rideChoice = RideChoice(index: index, from: leg.from, at: leg.departAt, bus: leg.mode == "bus", rides: rides) }
            }
        } catch {}
    }

    /// The trip follows that tram from its stop on: the rest planned again from it, with Undo.
    func takeRide(_ api: AppAPI, _ ride: AppAPI.TripRide, index: Int, from: String, at: Date) async {
        settledRides.insert("\(from)|\(Self.clock(at))")
        withAnimation(.easeOut(duration: 0.2)) { rideChoice = nil }
        do throws(APIFailure) {
            let switched = try await api.tripRideSwitch(leg: index, from: from, at: at, to: ride)
            await rideSwitched(api, switched, said: "You’re on the \(switched.line) at \(switched.departTime) · there \(switched.arriveTime)")
        } catch {
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
        }
    }

    // MARK: The change as a live board (When your tram is late)

    /// The board on the trip: the ride it's for, when they'll be ready at its stop, the ride after it a
    /// tram must still make, and whether they're on their way to its stop (the first tram).
    struct LiveBoard {
        let leg: Int
        let ready: Date
        let connection: ChangeBoard.Connection?
        let boarding: Bool
        var board: ChangeBoard
    }

    private(set) var changeBoard: LiveBoard?
    /// Switch trams for me off: the better tram, offered with "Take it".
    var changeSuggestion: (run: ChangeBoard.Run, reason: ChangeBoard.Reason)? {
        guard let changeBoard, !changeBoard.board.autoSwitch else { return nil }
        return better(changeBoard)
    }
    /// Boards already switched on (by the run), so one isn't switched twice while the trip reloads.
    @ObservationIgnored private var boardSwitched: Set<String> = []
    /// Stops where they chose a tram by hand: Expadu then only steps in when theirs won't do, never to
    /// move them to a faster one they passed over.
    private var pickedByHand: Set<String> = []

    private func handKey(_ leg: Int) -> String? {
        guard let legs = page?.trip?.legs, legs.indices.contains(leg) else { return nil }
        return legs[leg].from
    }

    private func better(_ live: LiveBoard) -> (run: ChangeBoard.Run, reason: ChangeBoard.Reason)? {
        guard let better = live.board.better(ready: live.ready, connection: live.connection) else { return nil }
        if better.reason == .faster, let key = handKey(live.leg), pickedByHand.contains(key) { return nil }
        return better
    }

    /// Why they're moved, in a sentence: "Your 16 at 19:47 is cancelled."
    static func why(_ reason: ChangeBoard.Reason, yours: ChangeBoard.Run?, stop: String, connection: ChangeBoard.Connection?) -> String {
        let tram = yours.map { "\($0.line) at \($0.departTime)" } ?? "tram"
        switch reason {
        case .cancelled: return "Your \(tram) is cancelled."
        case .missed: return "Your \(tram) leaves before you get to \(stop)."
        case .late:
            let late = yours?.delayMin.map { $0 > 0 ? " is running \($0) minutes late, so it" : "" } ?? ""
            return "Your \(tram)\(late) gets to \(connection?.stop ?? "the change") too late for the \(connection?.line ?? "next tram") at \(connection?.departTime ?? "")."
        case .faster: return ""
        }
    }

    /// The board read again, and, with switch trams for me on, the trip moved to a better tram than
    /// theirs: the earliest they can catch that still makes the ride after it, or one sooner there.
    func refreshChangeBoard(_ api: AppAPI, here: Guide.Position?) async {
        guard let trip = page?.trip, let next = trip.nextBoard(here: here, at: .now) else {
            if changeBoard != nil { withAnimation(Motion.spring) { changeBoard = nil } }
            return
        }
        let leg = trip.legs[next.leg]
        // Walking to it: from when they can be at the stop (two minutes' grace), so an earlier tram that still makes the change shows.
        guard let board = try? await api.tripRideBoard(leg: next.leg, from: leg.from, at: leg.departAt,
                                                       since: next.boarding ? next.ready.addingTimeInterval(-120) : nil) else { return }
        let live = LiveBoard(leg: next.leg, ready: next.ready, connection: next.connection, boarding: next.boarding, board: board)
        withAnimation(Motion.spring) { changeBoard = live }
        guard board.autoSwitch, let better = better(live), boardSwitched.insert(better.run.id).inserted else { return }
        let run = better.run
        let said = better.reason == .faster
            ? "The \(run.line) at \(run.departTime) gets you there \(Self.minutesSooner(board.yours, than: run)) sooner, so you’re on it now."
            : Self.why(better.reason, yours: board.yours, stop: leg.from, connection: next.connection)
                + " You’re now on the \(run.line) at \(run.departTime)."
                + (board.makes(run, next.connection) && next.connection != nil ? " You’ll still be there at \(next.connection!.thereTime)." : "")
        await switchToRun(api, run, leg: next.leg, said: said)
    }

    /// A tram on the board taken: tapped, or "Take it". With Undo.
    func takeRun(_ api: AppAPI, _ run: ChangeBoard.Run) async {
        guard let changeBoard else { return }
        boardSwitched.insert(run.id)
        if let key = handKey(changeBoard.leg) { pickedByHand.insert(key) }
        await switchToRun(api, run, leg: changeBoard.leg, said: "You’re now on the \(run.line) at \(run.departTime).")
    }

    /// Switch trams for me, on or off: kept on Expadu's side; the board follows at once.
    func setAutoSwitch(_ api: AppAPI, _ on: Bool) async {
        changeBoard?.board.autoSwitch = on
        do throws(APIFailure) {
            try await api.setAutoSwitchTrams(on)
        } catch {
            changeBoard?.board.autoSwitch = !on
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
        }
    }

    private func switchToRun(_ api: AppAPI, _ run: ChangeBoard.Run, leg: Int, said: String) async {
        guard let legs = page?.trip?.legs, legs.indices.contains(leg) else { return }
        let tripLeg = legs[leg]
        let ride = AppAPI.TripRide(line: run.line, color: run.color, departAt: run.departAtText, departTime: run.departTime, arriveTime: run.arriveTime, trip: run.trip)
        do throws(APIFailure) {
            let switched = try await api.tripRideSwitch(leg: leg, from: tripLeg.from, at: tripLeg.departAt, to: ride)
            await rideSwitched(api, switched, said: said)
            changeBoard = nil
        } catch {
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
        }
    }

    private static func minutesSooner(_ yours: ChangeBoard.Run?, than run: ChangeBoard.Run) -> String {
        let minutes = max(1, Int(((yours?.arriveAt ?? run.arriveAt).timeIntervalSince(run.arriveAt) / 60).rounded()))
        return minutes == 1 ? "1 minute" : "\(minutes) minutes"
    }

    /// A change a delay broke: the next tram there, taken for them, with Undo.
    func catchNext(_ api: AppAPI, leg: TodayPage.Trip.Leg, index: Int, readyAt: Date) async {
        let key = "next|\(leg.from)|\(leg.departTime)"
        guard !settledRides.contains(key) else { return }
        settledRides.insert(key)
        do throws(APIFailure) {
            let switched = try await api.tripRideNext(leg: index, from: leg.from, at: leg.departAt, readyAt: readyAt)
            await rideSwitched(api, switched, said: "The \(leg.line ?? "connection") goes before you get there: take the \(switched.line) at \(switched.departTime) · there \(switched.arriveTime)")
        } catch {
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
        }
    }

    /// Not any of them: the trip stays as it is, and this ride isn't asked about again.
    func dismissRideChoice() {
        if let choice = rideChoice { settledRides.insert("\(choice.from)|\(Self.clock(choice.at))") }
        withAnimation(.easeOut(duration: 0.2)) { rideChoice = nil }
    }

    private func rideSwitched(_ api: AppAPI, _ switched: AppAPI.TripRideSwitch, said: String) async {
        await load(api, quietly: true)
        cardFlips += 1
        // Long enough to see on the way and undo; the steps themselves show the new times.
        sayInCard(Note(text: said, symbol: "tram.fill", undo: .ride(switched.undo)), seconds: 45)
    }

    private static func clock(_ date: Date) -> String {
        date.formatted(Date.FormatStyle().hour(.twoDigits(amPM: .omitted)).minute(.twoDigits))
    }

    /// A new trip replaced the one they were on (one tap, no question): said on the trip, with Undo.
    func tripReplaced(_ replaced: AppAPI.Replaced) {
        sayInCard(Note(text: "Replaced your trip to \(replaced.to)", symbol: "arrow.triangle.swap", undo: .trip(replaced.undo)), seconds: 20)
    }

    /// Undo from the card's note: the way the plan had before, the tram the trip had before, or the trip a new one replaced.
    func undoCard(_ api: AppAPI) async {
        if case let .trip(undo)? = cardNote?.undo {
            withAnimation(.easeOut(duration: 0.2)) { cardNote = nil }
            do throws(APIFailure) {
                try await api.undoTripReplace(undo)
                await load(api, quietly: true)
                cardFlips += 1
                sayInCard(Note(text: "Back on the trip you had.", symbol: "arrow.uturn.backward"))
            } catch {
                say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
            }
            return
        }
        if case let .ride(undo)? = cardNote?.undo {
            withAnimation(.easeOut(duration: 0.2)) { cardNote = nil }
            do throws(APIFailure) {
                try await api.tripRideUndo(undo)
                await load(api, quietly: true)
                cardFlips += 1
                sayInCard(Note(text: "Back to the tram you had.", symbol: "arrow.uturn.backward"))
            } catch {
                say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
            }
            return
        }
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
        case let .ride(undo):
            do throws(APIFailure) {
                try await api.tripRideUndo(undo)
                say(Note(text: "Back to the tram you had", symbol: "arrow.uturn.backward"))
                await load(api, quietly: true)
            } catch {
                say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
            }
        case let .trip(undo):
            do throws(APIFailure) {
                try await api.undoTripReplace(undo)
                say(Note(text: "Back on the trip you had", symbol: "arrow.uturn.backward"))
                await load(api, quietly: true)
            } catch {
                say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
            }
        case let .signal(idea):
            pending.removeValue(forKey: idea.id)?.cancel()
            withAnimation(.snappy) { _ = hidden.remove(idea.id) }
            say(Note(text: "Put back", symbol: "arrow.uturn.backward"))
        case let .routine(id):
            // The routine just set, or the Not now just said, taken back: the offer is made again.
            do throws(APIFailure) {
                try await api.forgetRoutine(id)
                withAnimation(Motion.spring) { routineSet = nil }
                await loadRoutines(api)
                say(Note(text: "Put back", symbol: "arrow.uturn.backward"))
            } catch {
                say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
            }
        }
    }

    // MARK: Routines

    /// Read with Today: the routine to offer, and theirs.
    func loadRoutines(_ api: AppAPI) async {
        guard let fresh = try? await api.routines() else { return }
        withAnimation(Motion.spring) { routines = fresh }
    }

    /// Yes to the offer, with the days and time they set: it folds into the routine, with Undo.
    func confirmRoutine(_ api: AppAPI, _ offer: RoutinesState.Offer, weekdays: [Int], at: String) async -> Bool {
        do throws(APIFailure) {
            let id = try await api.confirmRoutine(toKey: offer.toKey, weekdays: weekdays, at: at)
            withAnimation(Motion.spring) {
                routineSet = RoutinesState.Routine(id: id, toLabel: offer.toLabel, fromKey: offer.fromKey, weekdays: weekdays, at: at, remind: true)
                routines?.offer = nil
            }
            say(Note(text: "Routine set. I’ll tell you when to leave.", symbol: "bell", undo: .routine(id)))
            return true
        } catch {
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
            return false
        }
    }

    /// Not now: the offer goes for a month, with Undo.
    func notNowRoutine(_ api: AppAPI, _ offer: RoutinesState.Offer) async {
        do throws(APIFailure) {
            let id = try await api.dismissRoutine(toKey: offer.toKey)
            withAnimation(Motion.spring) { routines?.offer = nil }
            say(Note(text: "I won’t ask about this one for a month.", symbol: "clock.arrow.circlepath", undo: id.map { .routine($0) }))
        } catch {
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
        }
    }

    // MARK: What you'd go to (EventPicks)

    /// Their picks as they tap, ahead of the server's answer; nil: the page's own.
    private var picksShown: [String]?
    /// The posters open or folded on this phone (Done, Not now, Change); nil: as the page says.
    private(set) var picksOpen: Bool?
    /// Each save after the one before, so the last tap wins.
    private var picksSaving: Task<Void, Never>?

    var picked: [String] { picksShown ?? page?.picks?.picked ?? [] }

    /// A poster tapped: in or out, kept at once, and tonight's ideas move to where they now go.
    func togglePick(_ api: AppAPI, _ key: String) {
        var next = picked
        if let at = next.firstIndex(of: key) { next.remove(at: at) } else { next.append(key) }
        picksShown = next
        savePicks(api, next, done: false)
    }

    /// Done or Not now: folded into its line, and Today stops asking.
    func closePicks(_ api: AppAPI) {
        withAnimation(Motion.spring) { picksOpen = false }
        savePicks(api, picked, done: true)
    }

    /// Change: the posters again, as they were picked.
    func reopenPicks() {
        withAnimation(Motion.spring) { picksOpen = true }
    }

    private func savePicks(_ api: AppAPI, _ kinds: [String], done: Bool) {
        let before = picksSaving
        picksSaving = Task { [weak self] in
            await before?.value
            guard let self else { return }
            do throws(APIFailure) {
                try await api.savePicks(kinds, done: done)
                // Another tap since: its own save reloads.
                guard self.picked == kinds else { return }
                await self.load(api, quietly: true, animated: Motion.spring)
                if self.picksShown == kinds { self.picksShown = nil }
            } catch {
                self.picksShown = nil
                self.say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
            }
        }
    }

    /// Less like this: its kind lower from tomorrow, nothing hidden; sent once Undo has passed.
    func lessLikeThis(_ api: AppAPI, idea: TodayPage.Idea) {
        later(api, Signal(action: .lessLikeThis, event: idea.id), for: idea)
        say(Note(text: "Got it. You’ll see fewer like this from tomorrow.", symbol: "minus.circle", undo: .signal(idea)))
    }

    /// Not for me: off Today at once.
    func notForMe(_ api: AppAPI, idea: TodayPage.Idea) {
        hidden.insert(idea.id)
        later(api, Signal(action: .notForMe, event: idea.id), for: idea)
        say(Note(text: "It’s off Today now.", symbol: "xmark.circle", undo: .signal(idea)))
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
    /// When `place` was found.
    private var placeAt: Date?

    /// I'm on my way with no way to hand (the Leave card's not loaded, or the Lock Screen asked after
    /// it went): Today again, then the first way if there is one now, else why not, in words.
    func findWay(_ api: AppAPI) async {
        await load(api, quietly: true)
        if let way = page?.leave?.ways.first {
            _ = await startTrip(api, way: way, replace: false)
        } else if page?.leave == nil {
            say(Note(text: "Nothing to leave for right now. Open an event’s Getting there to start a trip.", symbol: "info.circle"))
        } else {
            say(Note(text: "We couldn’t find a way there right now. Try again in a moment.", symbol: "exclamationmark.circle", problem: true))
        }
    }

    /// I'm on my way, in one tap: a trip already running is replaced, said on the new one with Undo.
    /// Shown at once, as Go is in Maps: when the way's steps are here the trip goes on the page and
    /// `opened` runs before the server answers (waiting for it and then for the whole day read as a tap
    /// that did nothing, the owner, 14 Nov); its answer then replaces the shown trip quietly. Without
    /// them, `opened` runs once the server has it. Turned down: the trip there was comes back and
    /// `startProblem` says why (where the tap was: the Leave sheet, or the card).
    /// True only when the server still asks first (never from here, which always replaces).
    func startTrip(_ api: AppAPI, way: TodayPage.Way, replace: Bool = true, opened: (() -> Void)? = nil) async -> Bool {
        startProblem = nil
        let shown: TodayPage.Trip?? = way.legs.isEmpty ? nil
            : .some(showStarting(TodayPage.Trip(to: page?.leave?.to ?? "", arriveTime: way.arriveTime, startTime: page?.leave?.start, legs: way.legs)))
        if shown != nil { opened?() }
        do throws(APIFailure) {
            let started = try await api.startTrip(way: way, latitude: place?.latitude, longitude: place?.longitude, replace: replace)
            guard case let .started(replaced) = started else {
                if let shown { notStarting(shown) }
                return true
            }
            await self.started(api)
            if shown == nil, page?.trip != nil { opened?() }
            if let replaced { tripReplaced(replaced) }
        } catch {
            if let shown { notStarting(shown) }
            let why = Self.words(error)
            startProblem = why
            say(Note(text: why, symbol: "exclamationmark.circle", problem: true))
            // The way it asked for has gone (that tram left): the card shows the next one at once.
            await load(api, quietly: true)
        }
        return false
    }

    /// Why the last Start trip didn't start, for where it was tapped.
    private(set) var startProblem: String?

    /// Start trip, shown before the server has it: kept on the page through any reload until its
    /// answer comes, so a reload in between never takes the trip away.
    private var starting: TodayPage.Trip?

    /// The trip picked, on the page at once; the one it replaces comes back, for if it's turned down.
    func showStarting(_ trip: TodayPage.Trip) -> TodayPage.Trip? {
        let before = page?.trip
        starting = trip
        page?.trip = trip
        return before
    }

    /// The server took it: its own trip (with the live times) in place of the one shown.
    func started(_ api: AppAPI) async {
        starting = nil
        cardNote = nil
        await load(api, quietly: true)
    }

    /// Turned down: the trip there was before, or none.
    func notStarting(_ before: TodayPage.Trip?) {
        starting = nil
        page?.trip = before
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
        let text = arrived ? "You made it." : page?.leave?.leaveTime.map { "Trip ended. Your leave time of \($0) still stands." } ?? "Trip ended."
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
            sayInCard(Note(text: "Changed: now leave at \(way.leaveTime), \(how).", symbol: "arrow.triangle.branch", undo: .way(undo)))
            return nil
        } catch {
            await load(api, quietly: true)
            return Self.words(error)
        }
    }

    func agree(_ api: AppAPI, to pause: TodayPage.RankPause) async -> Bool {
        do throws(APIFailure) {
            try await api.agreeToOrder(pause)
            say(Note(text: "Thanks. Today will be in your order again in a minute.", symbol: "checkmark.circle.fill"))
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

    /// Departures near them: the same board reply Travel's board reads (the nearest stop's live runs,
    /// with KVB's delays and DELFI's cancellations), so Today never shows other times than Travel.
    func loadBoard(_ api: AppAPI) async {
        let place = await locator.coordinate() ?? OneLocation.neumarkt
        guard let reply = try? await api.station(latitude: place.latitude, longitude: place.longitude), let station = reply.station else { return }
        let now = Date.now
        let rows: [Board.Departure] = station.lines.flatMap { line in
            line.dirs.compactMap { dir -> Board.Departure? in
                let runs = dir.runs.filter { ($0.date ?? .distantPast) >= now.addingTimeInterval(-30) }
                guard let next = runs.first else { return nil }
                return Board.Departure(line: line.line, direction: dir.dest, stop: station.name, color: line.color ?? "#4A4A4A",
                                       minutes: runs.prefix(3).map { $0.minutes(from: now) }, delay: next.delay ?? 0,
                                       cancelled: next.cancelled || dir.cancelled, disrupted: dir.disrupted)
            }
        }
        .sorted { ($0.minutes.first ?? 999) < ($1.minutes.first ?? 999) }
        withAnimation(.snappy) { board = Board(stops: [Board.Stop(name: station.name, walkMinutes: station.walkMin ?? 0)], departures: rows) }
    }
}
