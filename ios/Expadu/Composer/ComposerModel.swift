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
            let answer = try await api.composerAsk(latitude: place?.latitude, longitude: place?.longitude, from: label, processing: processing)
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
    }

    func open(_ id: String, api: AppAPI) async {
        guard let workspace else { return }
        if id != workspace.active {
            await write("open-\(id)", api) { () async throws(APIFailure) in try await api.composerCommand("resume", revision: workspace.revision, ["id": id]) }
        }
        showing = true
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

    /// An event or place into a day's plan, by its candidate id.
    func add(_ candidate: String, date: String, api: AppAPI) async {
        guard let workspace else { return }
        await write("add-\(candidate)", api, done: "Added to your plan.") { () async throws(APIFailure) in
            try await api.composerCommand("add", revision: workspace.revision, ["candidate": candidate, "date": date])
        }
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

    private func write(_ key: String, _ api: AppAPI, done: String? = nil, _ work: () async throws(APIFailure) -> AppAPI.ComposerReply) async {
        guard busy == nil else { return }
        busy = key
        defer { busy = nil }
        do {
            let reply = try await work()
            if let next = reply.workspace { workspace = next } else { await load(api) }
            if let refusal = reply.refusal { say(refusal, problem: true) } else if let done { say(done) }
        } catch {
            say(Self.words(error), problem: true)
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
