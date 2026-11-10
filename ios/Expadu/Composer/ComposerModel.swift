import API
import Core
import CoreLocation
import Foundation
import Observation

/// Composer's page state: the workspace as the server last gave it, the question being answered
/// and how far it has got, and the line that says what just happened. Every write returns the
/// workspace, so what the page shows is always the server's.
@MainActor @Observable
final class ComposerModel {
    enum Phase: Equatable { case loading, problem(String), ready }

    /// A question on its way: shown at once, with the assistant's progress under it.
    struct Pending: Equatable {
        let text: String
        var words: String
    }

    struct Note: Equatable, Identifiable {
        let id = UUID()
        let text: String
        var problem = false
    }

    private(set) var phase = Phase.loading
    private(set) var workspace: ComposerWorkspace?
    private(set) var pending: Pending?
    private(set) var note: Note?
    /// The action in flight, by its own key, so only its button shows it's working.
    private(set) var busy: String?
    /// What Composer sends and to whom; nil until read. Sending is the go-ahead while it's shown.
    private(set) var disclosure: ComposerDisclosure?
    private var noteTask: Task<Void, Never>?

    var conversation: ComposerConversation? { workspace?.current }
    var messages: [ComposerMessage] { conversation?.messages ?? [] }
    /// A conversation is on the paper: they asked, or opened one. Composer opens on the wall.
    private(set) var showing = false
    var talking: Bool { (showing && !messages.isEmpty) || pending != nil }

    /// What the menu opened in place of the conversation: My Plans or Saved.
    enum Collection: Equatable { case plans, saved }
    var collection: Collection? { didSet { if collection == nil { collectionBack = nil } } }
    /// The view a pushed view goes back to (Saved, chosen from My Plans).
    private(set) var collectionBack: Collection?
    /// True while a pushed view slides back out.
    private(set) var leaving = false

    /// From one view to another: it pushes in, and the head's ‹ goes back.
    func push(_ next: Collection) {
        let from = collection
        collectionBack = from
        collection = next
        collectionBack = from
    }

    /// From the drawer: the view on its own, with no way back but the menu.
    func show(_ next: Collection) {
        collectionBack = nil
        collection = next
    }

    func back() {
        guard let to = collectionBack else { return }
        leaving = true
        collection = to
        collectionBack = nil
        Task { try? await Task.sleep(for: .milliseconds(450)); leaving = false }
    }
    /// The day My Plans and Saved are about.
    var day = ComposerModel.today

    /// Where new days are planned from, for as long as the app runs (the web keeps it for the
    /// browser's session). None: where the app knows they are.
    var start: ComposerStart? {
        get { Self.kept }
        set { Self.kept = newValue }
    }
    private static var kept: ComposerStart?

    /// Today in Cologne, as the server's days are named ("2026-11-09").
    static var today: String { day(Date()) }

    static func day(_ date: Date) -> String {
        let format = DateFormatter()
        format.calendar = Calendar(identifier: .gregorian)
        format.locale = Locale(identifier: "en_US_POSIX")
        format.timeZone = TimeZone(identifier: "Europe/Berlin")
        format.dateFormat = "yyyy-MM-dd"
        return format.string(from: date)
    }

    static func date(_ day: String) -> Date? {
        let format = DateFormatter()
        format.calendar = Calendar(identifier: .gregorian)
        format.locale = Locale(identifier: "en_US_POSIX")
        format.timeZone = TimeZone(identifier: "Europe/Berlin")
        format.dateFormat = "yyyy-MM-dd HH:mm"
        return format.date(from: day + " 12:00")
    }

    /// "Saturday 14 November", as the web asks Composer to plan a day.
    static func long(_ day: String) -> String {
        guard let date = date(day) else { return day }
        let format = DateFormatter()
        format.locale = Locale(identifier: "en_GB")
        format.timeZone = TimeZone(identifier: "Europe/Berlin")
        format.dateFormat = "EEEE d MMMM"
        return format.string(from: date)
    }

    /// "today", or "Sat 14 Nov".
    static func short(_ day: String) -> String {
        if day == today { return "today" }
        guard let date = date(day) else { return day }
        let format = DateFormatter()
        format.locale = Locale(identifier: "en_GB")
        format.timeZone = TimeZone(identifier: "Europe/Berlin")
        format.dateFormat = "EEE d MMM"
        return format.string(from: date)
    }

    func start(_ api: AppAPI) async {
        guard workspace == nil else { return }
        await load(api)
        disclosure = try? await api.processingNotice("composer_assist")
    }

    func load(_ api: AppAPI) async {
        do {
            workspace = try await api.composerWorkspace()
            phase = .ready
        } catch {
            if workspace == nil { phase = .problem(Self.words(error)) }
        }
    }

    // MARK: Asking

    /// Their message into the conversation, then Composer's answer to it. A new question starts
    /// its own conversation.
    func send(_ text: String, api: AppAPI, place: CLLocationCoordinate2D?, label: String?, fresh: Bool = false) async {
        let text = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty, pending == nil, let workspace else { return }
        // From the wall, a question starts its own conversation; on the paper it continues this one.
        let fresh = fresh || !showing
        showing = true
        collection = nil
        pending = Pending(text: text, words: "Reading your request…")
        do {
            var revision = workspace.revision
            if fresh, !(conversation?.messages.isEmpty ?? true) {
                let reply = try await api.composerCommand("fresh", revision: revision)
                if let next = reply.workspace { self.workspace = next; revision = next.revision }
            }
            let stored = try await api.composerCommand("message", revision: revision, ["text": text])
            guard let next = stored.workspace else {
                pending = nil
                say(stored.refusal ?? "That didn’t go through. Try again in a moment.", problem: true)
                return
            }
            self.workspace = next
            // The go-ahead for this one request, when Composer's AI is on at all.
            let processing = disclosure?.available == true ? disclosure.map { ComposerProcessing(disclosure: $0) } : nil
            let watching = processing.map { watch($0.requestID, api: api) }
            // From their chosen starting point when there is one, else where the phone is.
            let answer = if let start {
                try await api.composerAsk(latitude: start.lat, longitude: start.lng, from: start.label, place: start.place, processing: processing)
            } else {
                try await api.composerAsk(latitude: place?.latitude, longitude: place?.longitude, from: label, processing: processing)
            }
            watching?.cancel()
            if let answered = answer.workspace {
                self.workspace = answered
            } else {
                await recover(api)
                if let refusal = answer.refusal { say(refusal, problem: true) }
            }
        } catch {
            await recover(api)
            say(Self.words(error), problem: true)
        }
        pending = nil
    }

    /// Ask again after it didn't go through, with what they wrote.
    func retry(_ text: String, api: AppAPI, place: CLLocationCoordinate2D?, label: String?) async {
        await send(text, api: api, place: place, label: label)
    }

    /// While it works, what it is looking at, as the web shows it.
    private func watch(_ requestID: String, api: AppAPI) -> Task<Void, Never> {
        Task { [weak self] in
            while !Task.isCancelled {
                try? await Task.sleep(for: .milliseconds(700))
                guard !Task.isCancelled, let progress = await api.composerProgress(requestID) else { continue }
                if self?.pending != nil { self?.pending?.words = progress.words }
            }
        }
    }

    /// Lost on the way: read the workspace again; a question still without an answer says so.
    private func recover(_ api: AppAPI) async {
        await load(api)
        guard let workspace, let last = messages.last, last.mine else { return }
        if let reply = try? await api.composerCommand("reply", revision: workspace.revision, ["kind": "failed"]), let next = reply.workspace {
            self.workspace = next
        }
    }

    // MARK: Conversations

    /// Back to the wall; the next question starts its own conversation.
    func newQuestion(_ api: AppAPI) async {
        showing = false
        collection = nil
    }

    func open(_ id: String, api: AppAPI) async {
        guard let workspace else { return }
        if id != workspace.active {
            await write("open-\(id)", api) { () async throws(APIFailure) in try await api.composerCommand("resume", revision: workspace.revision, ["id": id]) }
        }
        showing = true
        collection = nil
    }

    func rename(_ id: String, to title: String, api: AppAPI) async {
        await write("rename-\(id)", api, done: "Renamed.") { () async throws(APIFailure) in try await api.renameConversation(id, title: title) }
    }

    func delete(_ id: String, api: AppAPI) async {
        await write("delete-\(id)", api, done: "Conversation deleted.") { () async throws(APIFailure) in try await api.deleteConversation(id) }
    }

    func deleteHistory(_ api: AppAPI) async {
        guard let workspace else { return }
        await write("history", api, done: "Conversation history deleted.") { () async throws(APIFailure) in
            try await api.composerCommand("delete_history", revision: workspace.revision)
        }
    }

    // MARK: Actions on answers

    func apply(_ token: String, api: AppAPI) async {
        await write("apply-\(token)", api) { () async throws(APIFailure) in try await api.composerApply(token) }
    }

    func undo(_ token: String, api: AppAPI) async {
        await write("undo-\(token)", api) { () async throws(APIFailure) in try await api.composerUndo(token) }
    }

    /// An event or place into a day's plan, by its candidate id. From My Plans or Saved, the
    /// conversation shows it done, with its Undo.
    @discardableResult
    func add(_ candidate: String, date: String, api: AppAPI) async -> Bool {
        guard let workspace else { return false }
        let done = await write("add-\(candidate)", api, done: "Added to your plan.") { () async throws(APIFailure) in
            try await api.composerCommand("add", revision: workspace.revision, ["candidate": candidate, "date": date])
        }
        if done, collection != nil { collection = nil; showing = true }
        return done
    }

    /// Undo one change to a plan (24 hours); the conversation says so.
    func undoChange(_ id: String, api: AppAPI) async {
        guard let workspace else { return }
        await write("undo-change-\(id)", api) { () async throws(APIFailure) in
            try await api.composerCommand("undo", revision: workspace.revision, ["change": id])
        }
    }

    // MARK: My Plans

    /// A personal activity in a day's plan (not a booking, nor a reminder).
    func appointment(name: String, location: String, date: String, start: String, end: String, api: AppAPI) async -> Bool {
        guard let workspace else { return false }
        var fields: [String: String] = ["name": name, "date": date, "start": start, "end": end]
        if !location.isEmpty { fields["location"] = location }
        let done = await write("appointment", api) { () async throws(APIFailure) in
            try await api.composerCommand("appointment", revision: workspace.revision, fields)
        }
        if done { collection = nil; showing = true }
        return done
    }

    /// The day's plan put aside; the conversation says so, with Undo.
    func cancelDay(_ date: String, api: AppAPI) async {
        guard let workspace else { return }
        let done = await write("cancel-\(date)", api) { () async throws(APIFailure) in
            try await api.composerCommand("cancel_day", revision: workspace.revision, ["date": date])
        }
        if done { collection = nil; showing = true }
    }

    /// Change it with Composer: the day's plan, in the conversation, to change by asking.
    func openPlan(_ date: String, api: AppAPI) async {
        guard let workspace else { return }
        let done = await write("open-plan", api) { () async throws(APIFailure) in
            try await api.composerCommand("open", revision: workspace.revision, ["date": date])
        }
        if done { collection = nil; showing = true }
    }

    /// The planned days, by date; today counts when Today has something on it.
    func planned(_ day: String, todayHas: Bool) -> Bool {
        !(workspace?.plans[day]?.slots.isEmpty ?? true) || (day == Self.today && todayHas)
    }

    /// Composer may read their Paperwork record: remembered until they turn it off, then the
    /// question is asked again with it.
    func allowPaperwork(_ permission: ComposerBlock.Permission, question: String, api: AppAPI, place: CLLocationCoordinate2D?, label: String?) async {
        busy = "permission"
        do { try await api.setPaperworkPermission(true, disclosure: permission.disclosure) } catch {
            busy = nil
            say(Self.words(error), problem: true)
            return
        }
        busy = nil
        await send(question, api: api, place: place, label: label)
    }

    /// Stop Composer: future processing ends and saved responses are removed.
    func withdraw(_ api: AppAPI) async {
        busy = "withdraw"
        defer { busy = nil }
        do {
            let message = try await api.withdrawProcessing("composer_assist")
            disclosure = try? await api.processingNotice("composer_assist")
            await load(api)
            say(message ?? "Stopped. Composer won’t send anything on.")
        } catch {
            say(Self.words(error), problem: true)
        }
    }

    @discardableResult
    private func write(_ key: String, _ api: AppAPI, done: String? = nil, _ work: () async throws(APIFailure) -> AppAPI.ComposerReply) async -> Bool {
        guard busy == nil else { return false }
        busy = key
        defer { busy = nil }
        do {
            let reply = try await work()
            if let next = reply.workspace { workspace = next } else { await load(api) }
            if let refusal = reply.refusal { say(refusal, problem: true); return false }
            if let done { say(done) }
            return true
        } catch {
            say(Self.words(error), problem: true)
            return false
        }
    }

    func say(_ text: String, problem: Bool = false) {
        noteTask?.cancel()
        note = Note(text: text, problem: problem)
        noteTask = Task {
            try? await Task.sleep(for: .seconds(problem ? 5 : 3.4))
            if !Task.isCancelled { note = nil }
        }
    }

    static func words(_ error: Error) -> String {
        switch error as? APIFailure {
        case let .refused(message)?: message
        case .tooManyTries?: "That’s a lot of questions at once. Wait a moment and ask again."
        case .signedOut?: "You were signed out. Sign in again to use Composer."
        default: "That didn’t go through. Check your connection and try again."
        }
    }
}
