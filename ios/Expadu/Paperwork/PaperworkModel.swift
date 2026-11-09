import API
import Core
import Foundation
import Observation

/// Paperwork's page state: the record as the server last gave it, what is being changed, and the
/// line that says what just changed. Every write re-reads the plan, so what the page shows is
/// always the server's (docs/contracts/bureaucracy-v2.md).
@MainActor @Observable
final class PaperworkModel {
    enum Phase: Equatable { case loading, problem(String), ready }

    struct Note: Equatable, Identifiable {
        let id = UUID()
        let text: String
        var problem = false
    }

    private(set) var phase = Phase.loading
    private(set) var entry: PaperworkEntry?
    /// The action in flight, by its own key, so only its button shows it's working.
    private(set) var busy: String?
    private(set) var note: Note?
    /// "Your steps for Cologne are ready." for a moment after the questions.
    var justReady = false
    /// A requirement just marked ready: its stamp lands once.
    private(set) var stamped: String?
    private var noteTask: Task<Void, Never>?

    var plan: PaperworkPlan? { entry?.plan }

    func start(_ api: AppAPI) async {
        guard entry == nil else { return }
        await load(api)
    }

    func load(_ api: AppAPI) async {
        do {
            let fresh = try await api.paperwork()
            entry = fresh
            phase = .ready
        } catch {
            if entry == nil { phase = .problem(Self.words(error)) }
        }
    }

    /// One write, then the plan again. Returns whether it went through.
    @discardableResult
    func run(_ key: String, _ api: AppAPI, done text: String?, _ work: () async throws(APIFailure) -> Void) async -> Bool {
        guard busy == nil else { return false }
        busy = key
        defer { busy = nil }
        do {
            try await work()
        } catch {
            say(Self.words(error), problem: true)
            await load(api)
            return false
        }
        await load(api)
        if let text { say(text) }
        return true
    }

    func stamp(_ requirement: String) {
        stamped = requirement
        Task { try? await Task.sleep(for: .seconds(1.6)); if stamped == requirement { stamped = nil } }
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
        case .tooManyTries?: "Too many tries just now. Wait a moment and try again."
        case .signedOut?: "You were signed out. Sign in again to see your Paperwork."
        default: "Couldn’t reach Expadu. Check your connection and try again."
        }
    }
}
