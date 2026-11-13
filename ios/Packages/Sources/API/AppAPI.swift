import Core
import Foundation
import HTTPTypes
import OpenAPIRuntime
import OpenAPIURLSession

/// What a sign-in call can end in.
public enum SignInOutcome: Equatable, Sendable {
    case signedIn(StoredSession)
    case twoFactor(challenge: String)
}

public enum APIFailure: Error, Equatable, Sendable {
    /// The server turned this down, in its own words.
    case refused(String)
    case tooManyTries
    case signedOut
    case unreachable
}

/// The one door to /api/v1. Screens never see the generated types.
public struct AppAPI: Sendable {
    private let client: Client
    private let device: String
    /// Paperwork's v2 plan is the web's own record, read as JSON with its own model (Core/Paperwork).
    private let baseURL: URL
    private let token: @Sendable () -> String?

    public init(baseURL: URL, device: String, token: @escaping @Sendable () -> String?) {
        self.baseURL = baseURL
        self.token = token
        let middleware = BearerToken(token: token)
        client = Client(
            serverURL: baseURL,
            configuration: Configuration(dateTranscoder: .iso8601),
            transport: URLSessionTransport(),
            // Outermost, so each try goes out with the token as it is then.
            middlewares: [RetryReads(), middleware]
        )
        self.device = device
    }

    public func signIn(email: String, password: String, remember: Bool) async throws(APIFailure) -> SignInOutcome {
        let output = try await calling {
            try await client.signIn_token(body: .json(.init(email: email, password: password, deviceName: device, remember: remember)))
        }
        switch output {
        case let .ok(ok): return try outcome(try body { try ok.body.json })
        case let .unprocessableContent(refusal): throw refused(refusal)
        case let .undocumented(status, _): throw failure(status)
        }
    }

    public func answerTwoFactor(challenge: String, code: String?, recoveryCode: String?) async throws(APIFailure) -> SignInOutcome {
        let output = try await calling {
            try await client.signIn_twoFactor(body: .json(.init(challenge: challenge, code: code, recoveryCode: recoveryCode)))
        }
        switch output {
        case let .ok(ok): return try outcome(try body { try ok.body.json })
        case let .unprocessableContent(refusal): throw refused(refusal)
        case let .undocumented(status, _): throw failure(status)
        }
    }

    public func exchange(code: String, verifier: String) async throws(APIFailure) -> SignInOutcome {
        let output = try await calling {
            try await client.signIn_exchange(body: .json(.init(code: code, verifier: verifier, deviceName: device)))
        }
        switch output {
        case let .ok(ok): return try outcome(try body { try ok.body.json })
        case let .unprocessableContent(refusal): throw refused(refusal)
        case let .undocumented(status, _): throw failure(status)
        }
    }

    /// Sign in with Apple: the sheet's identity token and the nonce whose hash it carries.
    public func signInWithApple(identityToken: String, nonce: String, name: String?) async throws(APIFailure) -> SignInOutcome {
        let output = try await calling {
            try await client.signIn_apple(body: .json(.init(identityToken: identityToken, nonce: nonce, name: name, deviceName: device)))
        }
        switch output {
        case let .ok(ok): return try outcome(try body { try ok.body.json })
        case let .unprocessableContent(refusal): throw refused(refusal)
        case let .undocumented(status, _): throw failure(status)
        }
    }

    /// When the sign-in screen opened, sealed by the server. Sign-up and the reset link carry it back
    /// a few seconds later, as the website's forms do, so a script can't post them blind.
    public func formStamp() async throws(APIFailure) -> String {
        let output = try await calling { try await client.signIn_form() }
        switch output {
        case let .ok(ok): return try body { try ok.body.json }.drawn
        case let .undocumented(status, _): throw failure(status)
        }
    }

    public func register(name: String, email: String, password: String, drawn: String) async throws(APIFailure) -> SignInOutcome {
        let output = try await calling {
            try await client.signIn_register(body: .json(.init(name: name, email: email, password: password, passwordConfirmation: password, deviceName: device, drawn: drawn)))
        }
        switch output {
        case let .ok(ok): return try outcome(try body { try ok.body.json })
        case let .unprocessableContent(refusal): throw refused(refusal)
        case let .undocumented(status, _): throw failure(status)
        }
    }

    public func forgotPassword(email: String, drawn: String?) async throws(APIFailure) {
        let output = try await calling {
            try await client.signIn_forgotPassword(body: .json(.init(email: email, drawn: drawn)))
        }
        switch output {
        case .ok: return
        case let .unprocessableContent(refusal): throw refused(refusal)
        case let .undocumented(status, _): throw failure(status)
        }
    }

    public func refresh() async throws(APIFailure) -> StoredSession {
        let output = try await calling { try await client.signIn_refresh() }
        switch output {
        case let .ok(ok):
            guard case let .signedIn(session) = try outcome(try body { try ok.body.json }) else { throw .unreachable }
            return session
        case .unauthorized: throw .signedOut
        case let .undocumented(status, _): throw failure(status)
        }
    }

    public func signOut() async throws(APIFailure) {
        let output = try await calling { try await client.signIn_signOut() }
        switch output {
        case .noContent, .unauthorized: return
        case let .undocumented(status, _): throw failure(status)
        }
    }

    public func me() async throws(APIFailure) -> Account {
        let output = try await calling { try await client.account_show() }
        switch output {
        case let .ok(ok): return account(try body { try ok.body.json })
        case .unauthorized: throw .signedOut
        case let .undocumented(status, _): throw failure(status)
        }
    }

    public func resendVerification() async throws(APIFailure) {
        let output = try await calling { try await client.account_resendVerification() }
        switch output {
        case .ok: return
        case .unauthorized: throw .signedOut
        case let .undocumented(status, _): throw failure(status)
        }
    }

    public func sendDeletionCode() async throws(APIFailure) {
        let output = try await calling { try await client.account_sendDeletionCode() }
        switch output {
        case .ok: return
        case .unprocessableContent: throw .unreachable
        case .unauthorized: throw .signedOut
        case let .undocumented(status, _): throw failure(status)
        }
    }

    public func deleteAccount(password: String?, code: String?) async throws(APIFailure) {
        let output = try await calling {
            try await client.account_destroy(body: .json(.init(value1: .init(), value2: .init(password: password, code: code))))
        }
        switch output {
        case .noContent: return
        case let .unprocessableContent(refusal): throw refused(refusal)
        case .unauthorized: throw .signedOut
        case let .undocumented(status, _): throw failure(status)
        }
    }

    /// Travel's board around a place.
    /// One change to the day (done, undone, remove, add, a personal plan, undo), as the web's Today makes it.
    public func changeDay(revision: Int, action: String, id: String? = nil, date: String? = nil, candidate: String? = nil, change: String? = nil,
                          name: String? = nil, start: String? = nil, end: String? = nil, location: String? = nil) async throws(APIFailure) -> DayChange {
        guard let action = Components.Schemas.DayCommandRequest.ActionPayload(rawValue: action) else { throw .unreachable }
        let input = Components.Schemas.DayCommandRequest(revision: revision, action: action, id: id, change: change, candidate: candidate, date: date,
                                                         start: start, end: end, name: name, location: location)
        let output = try await calling { try await client.day_command(body: .json(input)) }
        switch output {
        case let .ok(ok):
            let done = try body { try ok.body.json }
            return DayChange(revision: done.revision, id: done.change?.id, label: done.change?.label, undoable: done.change?.undoable ?? false)
        case .unauthorized: throw .signedOut
        case let .unprocessableContent(refusal): throw refused(refusal)
        case .forbidden: throw .refused("Confirm your email address first.")
        case let .undocumented(status, _):
            throw status == 409 ? .refused("Your day changed on another device. Pull to check again.") : failure(status)
        }
    }

    /// Save an event for later, or let it go.
    public func saveEvent(id: Int, startsAt: Date, saved: Bool) async throws(APIFailure) -> Bool {
        let input = Components.Schemas.SaveEventRequest(eventId: id, occurrenceStart: startsAt, saved: saved)
        let output = try await calling { try await client.events_save(body: .json(input)) }
        switch output {
        case let .ok(ok): return try body { try ok.body.json }.saved
        case .unauthorized: throw .signedOut
        case let .unprocessableContent(refusal): throw refused(refusal)
        case .forbidden: throw .refused("Confirm your email address first.")
        case let .undocumented(status, _): throw failure(status)
        }
    }

    /// Impressions and turned-down ideas from Today, as the web's /api/signals; dropped unless they opted in.
    public func send(_ signals: [Signal]) async throws(APIFailure) {
        guard !signals.isEmpty else { return }
        let input = Components.Schemas.RecordSignalsRequest(signals: signals.map {
            .init(action: .init(rawValue: $0.action.rawValue) ?? .impression, surface: .today, itemType: .event, itemId: $0.event, position: $0.position)
        })
        let output = try await calling { try await client.signal(body: .json(input)) }
        switch output {
        case .accepted: return
        case .unauthorized: throw .signedOut
        case let .unprocessableContent(refusal): throw refused(refusal)
        case .forbidden: throw .refused("Confirm your email address first.")
        case let .undocumented(status, _): throw failure(status)
        }
    }

    /// Their yes to the changed notice they were shown; Today is put in their order again.
    public func agreeToOrder(_ pause: TodayPage.RankPause) async throws(APIFailure) {
        let output = try await calling {
            try await client.todayRank_update(body: .json(.init(noticeVersion: pause.noticeVersion, providerVersion: pause.providerVersion)))
        }
        switch output {
        case .ok: return
        case .unauthorized: throw .signedOut
        case let .unprocessableContent(refusal): throw refused(refusal)
        case let .undocumented(status, _): throw status == 409 ? .refused("Nothing is waiting for you to agree to.") : failure(status)
        default: throw .unreachable
        }
    }

    /// Not now: the line stays off Today for a week.
    public func orderLater() async throws(APIFailure) {
        let output = try await calling { try await client.todayRank_later() }
        switch output {
        case .ok: return
        case .unauthorized: throw .signedOut
        case let .undocumented(status, _): throw failure(status)
        }
    }

    /// I'm on my way, on one of the ways Today sent. A trip already running isn't replaced unless asked:
    /// that comes back as `.running` with where it's going.
    public func startTrip(way: TodayPage.Way, latitude: Double?, longitude: Double?, replace: Bool = false) async throws(APIFailure) -> TripStart {
        let input = Components.Schemas.TodayWayRequest(mode: .init(rawValue: way.mode) ?? .transit, departs: way.lines.isEmpty ? nil : way.departs, lat: latitude, lng: longitude, replace: replace)
        let output = try await calling { try await client.todayTrip_start(body: .json(input)) }
        switch output {
        case .ok: return .started
        case .unauthorized: throw .signedOut
        case let .unprocessableContent(refusal): throw refused(refusal)
        case .forbidden: throw .refused("Confirm your email address first.")
        case let .undocumented(status, _):
            if status == 409 { return .running }
            throw failure(status)
        }
    }

    /// Getting there: the ways from where the phone is (or was last known) to a place, there five minutes before `startsAt`.
    public func planWay(to name: String, latitude: Double, longitude: Double, fromLatitude: Double?, fromLongitude: Double?, startsAt: Date?) async throws(APIFailure) -> WayPlan {
        let input = Components.Schemas.JourneyPlanRequest(toLat: latitude, toLng: longitude, toName: name, fromLat: fromLatitude, fromLng: fromLongitude, startsAt: startsAt)
        let output = try await calling { try await client.journey_plan(body: .json(input)) }
        switch output {
        case let .ok(ok):
            let plan = try body { try ok.body.json }
            return WayPlan(
                plan: plan.plan, from: plan.from, to: plan.to,
                ways: plan.ways.enumerated().map { index, way in
                    var mapped = TodayPage.Way(id: index, leaveTime: way.leaveTime, arriveTime: way.arriveTime, spareMinutes: way.spareMin, status: way.status, mode: way.mode,
                                               lines: way.lines.map { .init(line: $0.line, color: $0.color) }, departs: way.departs, from: way.from,
                                               walkMinutes: way.walkMin, transfers: way.transfers, minutes: way.minutes, inPlan: way.inPlan, live: way.live)
                    mapped.legs = way.legs.compactMap { leg in
                        guard let departAt = Self.date(from: leg.departAt), let arriveAt = Self.date(from: leg.arriveAt) else { return nil }
                        return TodayPage.Trip.Leg(mode: leg.mode, line: leg.line, color: leg.color, headsign: leg.headsign, from: leg.from, to: leg.to,
                                                  departAt: departAt, arriveAt: arriveAt, departTime: leg.departTime, arriveTime: leg.arriveTime, minutes: leg.minutes,
                                                  stops: leg.stops, polyline: leg.polyline,
                                              turns: leg.turns.map { TodayPage.Turn(direction: $0.direction, street: $0.street, metres: $0.metres, polyline: $0.polyline, levelChange: $0.levelChange) },
                                              stopsOnTheWay: leg.stopsOnTheWay.map { TodayPage.Trip.Leg.Stop(name: $0.name, time: $0.time, latitude: $0.lat, longitude: $0.lng) },
                                              delayMin: leg.delayMin, cancelled: leg.cancelled)
                    }
                    return mapped
                },
                ticket: plan.ticket.map { WayPlan.Ticket(covered: $0.covered, label: $0.label, price: $0.price, how: $0.how) },
                rain: plan.rain.map { (at: $0.at, chance: $0.chance) },
                lifts: plan.lifts,
                notices: plan.notices.map { WayPlan.Notice(title: $0.title, lines: $0.lines, major: $0.major) }
            )
        case .unauthorized: throw .signedOut
        case let .unprocessableContent(refusal): throw refused(refusal)
        case .forbidden: throw .refused("Confirm your email address first.")
        case let .undocumented(status, _):
            if status == 429 { throw .tooManyTries }
            throw failure(status)
        }
    }

    /// I'm on my way, on a way Getting there planned.
    public func startWay(plan: String, way: Int, replace: Bool = false) async throws(APIFailure) -> TripStart {
        let input = Components.Schemas.JourneyStartRequest(plan: plan, way: way, replace: replace)
        let output = try await calling { try await client.journey_start(body: .json(input)) }
        switch output {
        case .ok: return .started
        case .unauthorized: throw .signedOut
        case let .unprocessableContent(refusal): throw refused(refusal)
        case .forbidden: throw .refused("Confirm your email address first.")
        case let .undocumented(status, _):
            if status == 409 { return .running }
            throw failure(status)
        }
    }

    /// I'm there: the trip ends.
    public func endTrip() async throws(APIFailure) {
        let output = try await calling { try await client.todayTrip_end() }
        switch output {
        case .ok: return
        case .unauthorized: throw .signedOut
        case let .undocumented(status, _): throw failure(status)
        default: throw .unreachable
        }
    }

    /// Use this: the plan keeps that way. Returns what Undo sends.
    public func keepWay(_ way: TodayPage.Way, latitude: Double?, longitude: Double?) async throws(APIFailure) -> String {
        let input = Components.Schemas.TodayWayRequest(mode: .init(rawValue: way.mode) ?? .transit, departs: way.lines.isEmpty ? nil : way.departs, lat: latitude, lng: longitude)
        let output = try await calling { try await client.todayTrip_keep(body: .json(input)) }
        switch output {
        case let .ok(ok): return try body { try ok.body.json }.undo
        case .unauthorized: throw .signedOut
        case let .unprocessableContent(refusal): throw refused(refusal)
        case .forbidden: throw .refused("Confirm your email address first.")
        case let .undocumented(status, _): throw failure(status)
        }
    }

    /// Undo Use this.
    public func undoWay(_ undo: String) async throws(APIFailure) {
        let output = try await calling { try await client.todayTrip_undo(path: .init(undo: undo)) }
        switch output {
        case .ok: return
        case .unauthorized: throw .signedOut
        case .gone: throw .refused("That can’t be undone any more.")
        case let .undocumented(status, _): throw failure(status)
        default: throw .unreachable
        }
    }

    public func nearby(latitude: Double, longitude: Double) async throws(APIFailure) -> Board {
        let output = try await calling { try await client.travel_nearby(query: .init(lat: latitude, lng: longitude)) }
        switch output {
        case let .ok(ok):
            let board = try body { try ok.body.json }
            return Board(
                stops: board.stops.map { Board.Stop(name: $0.name, walkMinutes: $0.walkMin) },
                departures: board.departures.map {
                    Board.Departure(line: $0.line, direction: $0.direction, stop: $0.stop, color: $0.color, minutes: $0.minutes,
                                    delay: $0.delay, cancelled: $0.cancelled, disrupted: $0.disrupted)
                }
            )
        case .unauthorized: throw .signedOut
        case let .unprocessableContent(refusal): throw refused(refusal)
        case let .undocumented(status, _): throw failure(status)
        }
    }

    /// What's on for a day (today when nil).
    // MARK: Paperwork (the web's v2 routes, routes/app/paperwork-v2.php)

    /// Their Paperwork record: ready, set up still, or stopped.
    public func paperwork() async throws(APIFailure) -> PaperworkEntry {
        let data = try await paperwork("GET", "plan")
        do { return try PaperworkEntry.decode(data) } catch { throw .unreachable }
    }

    /// First time: the city, then their own record. The questions follow in a session.
    public func setUpPaperwork(city: String) async throws(APIFailure) {
        _ = try await paperwork("PUT", "people/self/city", ["city": city])
        _ = try await paperwork("POST", "people/self")
    }

    /// A question session; its questions come one at a time.
    public func startQuestions(person: Int, jurisdiction: String) async throws(APIFailure) -> Int {
        let data = try await paperwork("POST", "people/\(person)/question-sessions", ["jurisdiction": jurisdiction, "request_id": Self.requestID()])
        guard let id = (try? JSONSerialization.jsonObject(with: data) as? [String: Any])?["session_id"] as? Int else { throw .unreachable }
        return id
    }

    public func nextQuestion(session: Int) async throws(APIFailure) -> PaperworkOffer {
        let data = try await paperwork("POST", "question-sessions/\(session)/next", ["request_id": Self.requestID()])
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        do { return try decoder.decode(PaperworkOffer.self, from: data) } catch { throw .unreachable }
    }

    /// "value", or "unknown" (I'm not sure) / "declined" (prefer not to say) / "not_applicable".
    public func answer(session: Int, question: PaperworkQuestion, value: PaperworkAnswer, state: String = "value") async throws(APIFailure) {
        guard let id = question.id, let token = question.token else { throw .unreachable }
        _ = try await paperwork("POST", "question-sessions/\(session)/answers/\(id)",
                                ["token": token, "value": value.json, "answer_state": state, "operation": "assert"])
    }

    public func skip(session: Int, question: PaperworkQuestion) async throws(APIFailure) {
        guard let id = question.id, let token = question.token else { throw .unreachable }
        _ = try await paperwork("POST", "question-sessions/\(session)/defer/\(id)", ["token": token])
    }

    /// Start tracking a proposed task.
    public func track(_ process: PaperworkProcess, person: Int, jurisdiction: String) async throws(APIFailure) {
        guard let review = process.reviewToken else { throw .unreachable }
        _ = try await paperwork("POST", "people/\(person)/processes",
                                ["jurisdiction": jurisdiction, "occurrence_key": process.occurrenceKey, "review_token": review, "request_id": Self.requestID()])
    }

    /// A step ticked or unticked, a status reported, a submission recorded: the task's own event.
    /// Undoing a record (untracking, retracting, cancelling an appointment) goes without the review token, as on the web.
    public func record(_ event: String, on process: PaperworkProcess, payload: [String: Any] = [:], reviewed: Bool = true) async throws(APIFailure) {
        guard let id = process.id else { throw .unreachable }
        var body: [String: Any] = ["request_id": Self.requestID(), "expected_version": process.version, "event": event, "payload": payload]
        body["review_token"] = (reviewed ? process.reviewToken : nil) ?? NSNull()
        _ = try await paperwork("POST", "processes/\(id)/events", body)
    }

    /// "I have this": their record of a document, one per name, keeping what it already says.
    public func have(_ requirement: PaperworkRequirement, existing: PaperworkEvidence?, person: Int, available: Bool = true) async throws(APIFailure) {
        let refs = Array(Set((existing?.details.requirementRefs ?? []) + [requirement.id])).sorted()
        let details: [String: Any] = ["label": existing?.details.label ?? requirement.label, "kind": existing?.details.kind ?? "document",
                                      "reported_available": available, "expires_on": existing?.details.expiresOn ?? NSNull(), "requirement_refs": refs]
        _ = try await paperwork("PUT", "people/\(person)/evidence/\(existing?.id ?? UUID().uuidString.lowercased())",
                                ["request_id": Self.requestID(), "expected_version": existing?.version ?? 0, "details": details])
    }

    /// "I don't have it": the document's record lets go of this need (archived when nothing else uses it).
    public func release(_ requirement: PaperworkRequirement, holders: [PaperworkEvidence], person: Int) async throws(APIFailure) {
        for item in holders {
            let refs = (item.details.requirementRefs ?? []).filter { $0 != requirement.id }
            let details: [String: Any] = ["label": item.details.label, "kind": item.details.kind, "reported_available": item.details.reportedAvailable,
                                          "expires_on": item.details.expiresOn ?? NSNull(), "requirement_refs": refs]
            _ = try await paperwork("PUT", "people/\(person)/evidence/\(item.id)",
                                    ["request_id": Self.requestID(), "expected_version": item.version, "details": details, "status": refs.isEmpty ? "archived" : "active"])
        }
    }

    /// Changed guidance: keep going with the updated steps; recorded progress stays.
    public func review(_ process: PaperworkProcess) async throws(APIFailure) {
        guard let id = process.id, let token = process.reviewToken else { throw .unreachable }
        _ = try await paperwork("POST", "processes/\(id)/review", ["request_id": Self.requestID(), "expected_version": process.version, "review_token": token,
                                                                    "bind_occurrence": process.bindOccurrence ?? NSNull(), "confirmed": true])
    }

    /// Their own record's person, for when there's no plan to read it from (processing stopped).
    public func paperworkPerson() async -> Int? {
        guard let data = try? await paperwork("GET", "people"),
              let people = (try? JSONSerialization.jsonObject(with: data) as? [String: Any])?["people"] as? [[String: Any]] else { return nil }
        return people.first { $0["is_self"] as? Bool == true }?["id"] as? Int
    }

    /// A paused run of questions carries on.
    public func resumeQuestions(session: Int) async throws(APIFailure) {
        _ = try await paperwork("POST", "question-sessions/\(session)/resume", ["revisit_deferred": false])
    }

    /// Their answers, for Your situation.
    public func facts(person: Int) async throws(APIFailure) -> PaperworkFacts {
        let data = try await paperwork("GET", "people/\(person)/facts")
        do { return try PaperworkFacts.decode(data) } catch { throw .unreachable }
    }

    /// What each answer asks, so it can be changed in place.
    public func factSchema() async throws(APIFailure) -> [PaperworkFactDefinition] {
        struct List: Decodable { let facts: [PaperworkFactDefinition] }
        let data = try await paperwork("GET", "facts/schema")
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        do { return try decoder.decode(List.self, from: data).facts } catch { throw .unreachable }
    }

    /// A real change in their circumstances, or a first answer outside a question.
    public func changeFact(person: Int, key: String, value: PaperworkAnswer, state: String = "value", revision: Int) async throws(APIFailure) {
        _ = try await paperwork("PUT", "people/\(person)/facts/\(key)",
                                ["value": value.json, "answer_state": state, "effective_from": NSNull(), "expected_revision": revision])
    }

    /// "Mark as ready": checked for this task.
    public func confirm(_ requirement: PaperworkRequirement, evidence: PaperworkEvidence, on process: PaperworkProcess) async throws(APIFailure) {
        guard let id = process.id else { throw .unreachable }
        _ = try await paperwork("POST", "processes/\(id)/requirements/\(requirement.id)/confirm",
                                ["request_id": Self.requestID(), "expected_version": process.version, "evidence_id": evidence.id,
                                 "evidence_version": evidence.version, "requirement_hash": requirement.semanticHash, "confirmed": true])
    }

    /// Not ready after all: only the check goes; the document's record stays.
    public func unconfirm(_ requirement: PaperworkRequirement, on process: PaperworkProcess) async throws(APIFailure) {
        guard let id = process.id else { throw .unreachable }
        _ = try await paperwork("DELETE", "processes/\(id)/requirements/\(requirement.id)/confirmation",
                                ["request_id": Self.requestID(), "expected_version": process.version])
    }

    /// Stop (GDPR Art. 18) or resume Paperwork using their record.
    public func setPaperworkStopped(_ stopped: Bool, person: Int) async throws(APIFailure) {
        _ = try await paperwork("PUT", "people/\(person)/processing-restriction", ["stopped": stopped])
    }

    /// The web app this API belongs to: where downloading or deleting the record happens, behind the password.
    public var site: URL { baseURL.deletingLastPathComponent().deletingLastPathComponent() }

    private func paperwork(_ method: String, _ path: String, _ body: [String: Any]? = nil) async throws(APIFailure) -> Data {
        var request = URLRequest(url: baseURL.appending(path: "paperwork/v2/" + path))
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if let token = token() { request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        if let body {
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.httpBody = try? JSONSerialization.data(withJSONObject: body)
        }
        let data: Data, response: URLResponse
        do { (data, response) = try await URLSession.shared.data(for: request) } catch { throw .unreachable }
        let status = (response as? HTTPURLResponse)?.statusCode ?? 0
        switch status {
        case 200..<300: return data
        case 401: throw .signedOut
        case 429: throw .tooManyTries
        default:
            // A stale version, a stopped record or a refused answer says why, in the server's words.
            let message = (try? JSONSerialization.jsonObject(with: data) as? [String: Any])?["message"] as? String
            throw message.map { .refused($0) } ?? .unreachable
        }
    }

    private static func requestID() -> String { UUID().uuidString.lowercased() }

    // MARK: Composer (the web's own routes, routes/app/composer-shared.php)

    /// What a write came back with: the workspace as it now is, and the server's words when it refused.
    public struct ComposerReply: Sendable {
        public let workspace: ComposerWorkspace?
        public let refusal: String?
    }

    public func composerWorkspace() async throws(APIFailure) -> ComposerWorkspace {
        let data = try await composer("GET", "composer/workspace").data
        do { return try ComposerWorkspace.decode(data) } catch { throw .unreachable }
    }

    /// One command on the workspace (message, fresh, resume, add, remove, undo, …). A stale revision
    /// (someone else wrote first: the web, another phone) reads the workspace again and tries once more.
    public func composerCommand(_ action: String, revision: Int, _ fields: [String: String] = [:]) async throws(APIFailure) -> ComposerReply {
        var body: [String: Any] = fields
        body["action"] = action
        body["revision"] = revision
        var reply = try await composer("POST", "composer/workspace", body)
        if reply.status == 409 {
            let fresh = try await composerWorkspace()
            body["revision"] = fresh.revision
            reply = try await composer("POST", "composer/workspace", body)
        }
        return Self.composerReply(reply)
    }

    /// Composer answers the last message: with the go-ahead to send it on (`processing`) when the
    /// notice was shown, and where they are, so the answer starts there.
    public func composerAsk(latitude: Double?, longitude: Double?, from label: String?, place: Int? = nil, processing: ComposerProcessing?) async throws(APIFailure) -> ComposerReply {
        var body: [String: Any] = ["page": "Composer"]
        if let latitude, let longitude { body["lat"] = latitude; body["lng"] = longitude }
        if let label { body["from_label"] = label }
        // One of their own places: its point is read on the server.
        if let place { body["from_place"] = place }
        if let processing { body["processing"] = processing.json }
        return Self.composerReply(try await composer("POST", "composer/ask", body, timeout: 75))
    }

    /// How far the assistant has got with a request.
    public func composerProgress(_ requestID: String) async -> ComposerProgress? {
        guard let reply = try? await composer("GET", "composer/progress/\(requestID)"), reply.status == 200 else { return nil }
        return try? ComposerProgress.decode(reply.data)
    }

    /// Apply a proposal, or undo a change Composer made (24 hours).
    public func composerApply(_ token: String) async throws(APIFailure) -> ComposerReply {
        Self.composerReply(try await composer("POST", "composer/apply", ["token": token]))
    }

    public func composerUndo(_ token: String) async throws(APIFailure) -> ComposerReply {
        Self.composerReply(try await composer("POST", "composer/undo", ["token": token]))
    }

    /// A Paperwork answer changed on its card ("Change" on a record row).
    public func composerAct(key: String, value: String) async throws(APIFailure) -> ComposerReply {
        Self.composerReply(try await composer("POST", "composer/act", ["action": "paperwork_fact", "key": key, "value": value]))
    }

    /// Rename (an empty title brings back the automatic one) or delete a conversation for good.
    public func renameConversation(_ id: String, title: String) async throws(APIFailure) -> ComposerReply {
        Self.composerReply(try await composer("PATCH", "composer/conversations/\(id)", ["title": title.isEmpty ? NSNull() : title]))
    }

    public func deleteConversation(_ id: String) async throws(APIFailure) -> ComposerReply {
        Self.composerReply(try await composer("DELETE", "composer/conversations/\(id)"))
    }

    /// The places and events that can go into a day's plan (saved ones only, or all near where they are).
    public func composerCatalogue(date: String, saved: Bool, latitude: Double?, longitude: Double?) async throws(APIFailure) -> [ComposerSlot] {
        var query = "composer/catalogue?date=\(date)&saved=\(saved ? 1 : 0)"
        if let latitude, let longitude { query += "&lat=\(latitude)&lng=\(longitude)" }
        struct Items: Decodable { let items: [ComposerSlot] }
        let data = try await composer("GET", query).data
        do { return try JSONDecoder.composer.decode(Items.self, from: data).items } catch { throw .unreachable }
    }

    /// Their saved events, still on or not (Composer's Saved).
    public func savedEvents() async throws(APIFailure) -> [ComposerSavedEvent] {
        let reply = try await composer("GET", "events/saved")
        guard reply.status == 200 else { throw .unreachable }
        do { return try ComposerSavedEvent.list(reply.data) } catch { throw .unreachable }
    }

    /// A place saved, unsaved ("clear") or left out from now on ("not_interested"), as Around here keeps it.
    public func placeFeedback(_ spot: Int, action: String) async throws(APIFailure) {
        let reply = try await composer("POST", "places/\(spot)/feedback", ["action": action])
        guard (200..<300).contains(reply.status) else { throw .unreachable }
    }

    /// Where they stand with a place: saved, been there.
    public func placeStanding(_ spot: Int) async throws(APIFailure) -> (saved: Bool, been: Bool) {
        let reply = try await composer("GET", "places/\(spot)/feedback")
        guard reply.status == 200 else { throw .unreachable }
        return Self.standing(reply.data)
    }

    /// Been there, or not after all.
    public func placeBeen(_ spot: Int, _ been: Bool) async throws(APIFailure) -> (saved: Bool, been: Bool) {
        let reply = try await composer(been ? "PUT" : "DELETE", "places/\(spot)/been", been ? [:] : nil)
        guard (200..<300).contains(reply.status) else { throw .unreachable }
        return Self.standing(reply.data)
    }

    private static func standing(_ data: Data) -> (saved: Bool, been: Bool) {
        let json = (try? JSONSerialization.jsonObject(with: data) as? [String: Any]) ?? [:]
        return ((json["state"] as? String) == "saved", json["been"] as? Bool ?? false)
    }

    /// "Good for laptop work?": whether they may answer (Been there or Saved first), and their answer.
    public func laptopAnswer(_ spot: Int) async throws(APIFailure) -> (canAnswer: Bool, answer: String?) {
        let reply = try await composer("GET", "places/\(spot)/work-answer")
        guard reply.status == 200 else { throw .unreachable }
        let json = (try? JSONSerialization.jsonObject(with: reply.data) as? [String: Any]) ?? [:]
        return (json["can_answer"] as? Bool ?? false, (json["answer"] as? [String: Any])?["laptop"] as? String)
    }

    public func answerLaptop(_ spot: Int, yes: Bool) async throws(APIFailure) {
        let reply = try await composer("PUT", "places/\(spot)/work-answer", ["laptop": yes ? "yes" : "no"])
        if reply.status == 403 || reply.status == 422 {
            let json = (try? JSONSerialization.jsonObject(with: reply.data) as? [String: Any]) ?? [:]
            throw .refused(json["message"] as? String ?? "Mark the place Been there or Saved first.")
        }
        guard (200..<300).contains(reply.status) else { throw .unreachable }
    }

    /// Addresses and places for a typed search (Starting point).
    public func geocode(_ query: String) async throws(APIFailure) -> [ComposerGeoHit] {
        let q = query.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed.subtracting(CharacterSet(charactersIn: "&+=?"))) ?? ""
        let reply = try await composer("GET", "geocode?q=\(q)")
        guard reply.status == 200 else { throw .unreachable }
        do { return try ComposerGeoHit.list(reply.data) } catch { throw .unreachable }
    }

    /// Their own places to plan from.
    public func composerPlaces() async throws(APIFailure) -> [ComposerOrigin] {
        let reply = try await composer("GET", "composer/places")
        guard reply.status == 200 else { throw .unreachable }
        do { return try ComposerOrigin.list(reply.data) } catch { throw .unreachable }
    }

    /// What a processing purpose sends, to whom, and whether it's on at all.
    public func processingNotice(_ purpose: String) async throws(APIFailure) -> ComposerDisclosure {
        let data = try await composer("GET", "privacy/processing/\(purpose)").data
        do { return try ComposerDisclosure.decode(data) } catch { throw .unreachable }
    }

    /// Stop it: future processing ends and saved responses are removed. The server's own words come back.
    public func withdrawProcessing(_ purpose: String) async throws(APIFailure) -> String? {
        let data = try await composer("DELETE", "privacy/processing/\(purpose)").data
        return (try? JSONSerialization.jsonObject(with: data) as? [String: Any])?["message"] as? String
    }

    /// Composer's remembered permission to read their Paperwork record.
    public func paperworkPermission() async throws(APIFailure) -> (enabled: Bool, available: Bool, hasRecord: Bool) {
        let data = try await composer("GET", "composer/paperwork-permission").data
        let json = (try? JSONSerialization.jsonObject(with: data) as? [String: Any]) ?? [:]
        return (json["enabled"] as? Bool ?? false, json["available"] as? Bool ?? false, json["has_record"] as? Bool ?? false)
    }

    public func setPaperworkPermission(_ enabled: Bool, disclosure: ComposerDisclosure?) async throws(APIFailure) {
        var body: [String: Any] = ["enabled": enabled]
        if enabled, let disclosure { body["notice_version"] = disclosure.noticeVersion; body["provider_version"] = disclosure.providerVersion }
        _ = Self.composerReply(try await composer("PUT", "composer/paperwork-permission", body))
    }

    // MARK: Travel

    private static func query(_ pairs: [(String, String)]) -> String {
        let allowed = CharacterSet.urlQueryAllowed.subtracting(CharacterSet(charactersIn: "&+=?#"))
        return pairs.map { "\($0.0)=\($0.1.addingPercentEncoding(withAllowedCharacters: allowed) ?? "")" }.joined(separator: "&")
    }

    /// Nearby: a station by its KVB name, else the closest to the point.
    public func station(named name: String? = nil, latitude: Double? = nil, longitude: Double? = nil) async throws(APIFailure) -> StationReply {
        var pairs: [(String, String)] = []
        if let name { pairs.append(("stop", name)) }
        if let latitude, let longitude { pairs += [("lat", String(latitude)), ("lng", String(longitude))] }
        let reply = try await composer("GET", "travel/station?" + Self.query(pairs))
        guard reply.status == 200 else { throw .unreachable }
        do { return try Station.decode(reply.data) } catch { throw .unreachable }
    }

    /// The stop picker: KVB stations by name.
    public func stations(_ query: String) async throws(APIFailure) -> [StationHit] {
        let reply = try await composer("GET", "travel/stations?" + Self.query([("q", query)]))
        guard reply.status == 200 else { throw .unreachable }
        do { return try StationHit.list(reply.data, key: "stations") } catch { throw .unreachable }
    }

    /// ★ a station, or take the star off. The list as it now is comes back.
    public func favouriteStop(_ name: String, on: Bool) async throws(APIFailure) -> [StationHit] {
        let reply = try await composer(on ? "POST" : "DELETE", "travel/favourite-stops", ["name": name])
        guard (200..<300).contains(reply.status) else { throw .unreachable }
        do { return try StationHit.list(reply.data, key: "favourites") } catch { throw .unreachable }
    }

    /// A line's whole route both ways, from this station.
    public func lineRoute(_ line: String, stop: String, latitude: Double?, longitude: Double?) async throws(APIFailure) -> LineRoute? {
        var pairs = [("stop", stop), ("line", line)]
        if let latitude, let longitude { pairs += [("lat", String(latitude)), ("lng", String(longitude))] }
        let reply = try await composer("GET", "travel/line-route?" + Self.query(pairs))
        guard reply.status == 200 else { throw .unreachable }
        struct Wrap: Decodable { let route: LineRoute? }
        do { return try TravelJSONAccess.decode(Wrap.self, reply.data).route } catch { throw .unreachable }
    }

    /// One stop's runs on a line.
    public func lineStop(_ line: String, stop: String) async throws(APIFailure) -> LineStop? {
        let reply = try await composer("GET", "travel/line-stop?" + Self.query([("stop", stop), ("line", line)]))
        guard reply.status == 200 else { throw .unreachable }
        struct Wrap: Decodable { let stop: LineStop? }
        do { return try TravelJSONAccess.decode(Wrap.self, reply.data).stop } catch { throw .unreachable }
    }

    /// Travel's map: the lines through the station and their next stops.
    public func linesMap(stop: String, latitude: Double?, longitude: Double?) async throws(APIFailure) -> LinesMap? {
        var pairs = [("stop", stop)]
        if let latitude, let longitude { pairs += [("lat", String(latitude)), ("lng", String(longitude))] }
        let reply = try await composer("GET", "travel/lines-map?" + Self.query(pairs))
        guard reply.status == 200 else { throw .unreachable }
        struct Wrap: Decodable { let map: LinesMap? }
        do { return try TravelJSONAccess.decode(Wrap.self, reply.data).map } catch { throw .unreachable }
    }

    /// Where a trip can go: ★ stops, saved places, recents, today's plans.
    public func travelPlaces() async throws(APIFailure) -> TravelPlaces {
        let reply = try await composer("GET", "travel/places")
        guard reply.status == 200 else { throw .unreachable }
        do { return try TravelPlaces.decode(reply.data) } catch { throw .unreachable }
    }

    /// Home or Work, set to a picked place.
    public func setTravelPlace(_ category: String, name: String, address: String?, latitude: Double, longitude: Double) async throws(APIFailure) -> TravelPlaces.Saved {
        var body: [String: Any] = ["name": name, "lat": latitude, "lng": longitude]
        if let address { body["address"] = address }
        let reply = try await composer("PUT", "travel/places/\(category)", body)
        guard reply.status == 200 else { throw .unreachable }
        do { return try TravelPlaces.saved(reply.data) } catch { throw .unreachable }
    }

    /// Places and stops as they type, in the body (never the URL).
    public func tripSuggestions(_ query: String, role: String) async throws(APIFailure) -> [TripSuggestion] {
        let reply = try await composer("POST", "travel/suggest", ["q": query, "role": role])
        guard reply.status == 200 else { throw .unreachable }
        do { return try TripSuggestion.list(reply.data) } catch { throw .unreachable }
    }

    public enum TripWhen: Sendable, Equatable {
        case now
        case leave(Date)
        case arrive(Date)
    }

    /// Plan a trip. The raw answer comes back too: a way is sent back as it came when it's kept or followed.
    public func planTrip(to: TripPoint, from: TripPoint?, when: TripWhen, more: Bool = false) async throws(APIFailure) -> (plan: TripPlan, raw: Data) {
        var body: [String: Any] = ["to_lat": to.lat, "to_lng": to.lng, "to_name": to.name, "checks_later": true]
        if let from { body["from_lat"] = from.lat; body["from_lng"] = from.lng; body["from_name"] = from.name }
        let iso = ISO8601DateFormatter()
        switch when {
        case .now: break
        case let .leave(date): body["depart_at"] = iso.string(from: date)
        case let .arrive(date): body["depart_at"] = iso.string(from: date); body["arrive_by"] = true
        }
        if more { body["more"] = true }
        let reply = try await composer("POST", "travel/plan", body, timeout: 40)
        if reply.status == 422 {
            let json = (try? JSONSerialization.jsonObject(with: reply.data) as? [String: Any]) ?? [:]
            throw .refused(json["code"] as? String == "location_required" ? "Where are you starting?" : json["message"] as? String ?? "No way found for this request.")
        }
        guard reply.status == 200 else { throw .unreachable }
        do { return (try TripPlan.decode(reply.data), reply.data) } catch { throw .unreachable }
    }

    /// Plan a trip's second request: each way as the boards at its stops run it, in the plan's order,
    /// and the reply as it came (Start trip and Add to my day send the way back as shown).
    public func tripChecks(_ id: String) async throws(APIFailure) -> (journeys: [TripPlan.Journey], raw: Data) {
        let reply = try await composer("POST", "travel/checks", ["checks": id])
        guard reply.status == 200 else { throw .unreachable }
        do { return (try TripPlan.liveJourneys(reply.data), reply.data) } catch { throw .unreachable }
    }

    /// Whether they hold a Deutschlandticket, for the fares.
    public func setDeutschlandticket(_ held: Bool) async throws(APIFailure) {
        let reply = try await composer("PUT", "travel/deutschlandticket", ["held": held])
        guard (200..<300).contains(reply.status) else { throw .unreachable }
    }

    /// Add to plan: this way for a plan on their day.
    public func planTrip(journey: Data, date: String, target: String, remind: Bool) async throws(APIFailure) -> PlannedTrip {
        let journey = (try? JSONSerialization.jsonObject(with: journey)) ?? [:]
        let reply = try await composer("POST", "travel/planned-trips", ["journey": journey, "date": date, "target_id": target, "remind": remind])
        if reply.status == 422 {
            let json = (try? JSONSerialization.jsonObject(with: reply.data) as? [String: Any]) ?? [:]
            throw .refused(json["message"] as? String ?? "That plan isn’t on your day.")
        }
        guard (200..<300).contains(reply.status) else { throw .unreachable }
        do { return try PlannedTrip.decode(reply.data) } catch { throw .unreachable }
    }

    public func remindPlannedTrip(_ id: Int, remind: Bool) async throws(APIFailure) -> PlannedTrip {
        let reply = try await composer("PATCH", "travel/planned-trips/\(id)", ["remind": remind])
        guard (200..<300).contains(reply.status) else { throw .unreachable }
        do { return try PlannedTrip.decode(reply.data) } catch { throw .unreachable }
    }

    public func removePlannedTrip(_ id: Int) async throws(APIFailure) {
        let reply = try await composer("DELETE", "travel/planned-trips/\(id)")
        guard (200..<300).contains(reply.status) || reply.status == 404 else { throw .unreachable }
    }

    public enum TravelTripStart: Sendable, Equatable {
        case started
        /// Another trip is being followed: to what, arriving when.
        case busy(to: String, arrive: String?)
    }

    /// I'm on my way, on a way from Plan a trip.
    public func startTravelTrip(journey: Data, to: TripPoint, from: TripPoint?, plan: (date: String, target: String)?, replace: Bool) async throws(APIFailure) -> TravelTripStart {
        let journey = (try? JSONSerialization.jsonObject(with: journey)) ?? [:]
        var body: [String: Any] = ["journey": journey, "destination": ["name": to.name, "lat": to.lat, "lng": to.lng], "replace": replace]
        if let from { body["origin"] = ["name": from.name, "lat": from.lat, "lng": from.lng] }
        if let plan { body["plan"] = ["date": plan.date, "target_id": plan.target] }
        // Asked again when the answer is lost on the way (underground at Appellhofplatz the first tap went
        // nowhere): the server takes the same journey again as the trip already started, never a second one.
        let reply = try await composer("POST", "travel/trip", body, timeout: 15, retry: true)
        if reply.status == 409 {
            let json = (try? JSONSerialization.jsonObject(with: reply.data) as? [String: Any]) ?? [:]
            let trip = json["trip"] as? [String: Any]
            let destination = trip?["destination"] as? [String: Any]
            return .busy(to: destination?["name"] as? String ?? "another place", arrive: trip?["arrive_time"] as? String)
        }
        // Turned down (the run has left, the way is over): say why, in the server's words.
        if reply.status == 422 {
            let json = (try? JSONSerialization.jsonObject(with: reply.data) as? [String: Any]) ?? [:]
            let reason = (json["errors"] as? [String: [String]])?.values.first?.first ?? json["message"] as? String
            throw .refused(reason ?? "This route can’t be started now. Find a fresh route.")
        }
        if reply.status == 429 { throw .tooManyTries }
        guard (200..<300).contains(reply.status) else { throw .unreachable }
        return .started
    }

    public func endTravelTrip() async throws(APIFailure) {
        let reply = try await composer("DELETE", "travel/trip")
        guard (200..<300).contains(reply.status) else { throw .unreachable }
    }

    // MARK: Trip sync: the tram they're really on

    /// A run of a ride's stretch: "18 at 17:42".
    public struct TripRide: Equatable, Hashable, Sendable {
        public let line: String
        public let color: String?
        public let departAt: String
        public let departTime: String
        public let arriveTime: String
        public let trip: String?

        init?(_ json: [String: Any]) {
            guard let line = json["line"] as? String, let departAt = json["depart_at"] as? String,
                  let departTime = json["depart_time"] as? String, let arriveTime = json["arrive_time"] as? String else { return nil }
            self.line = line
            color = json["color"] as? String
            self.departAt = departAt
            self.departTime = departTime
            self.arriveTime = arriveTime
            trip = json["trip"] as? String
        }
    }

    /// Which run the phone left a ride's stop on: the planned one, one for sure, or a choice.
    public enum TripRideCheck: Equatable, Sendable { case planned, sure(TripRide), choose([TripRide]), unknown }

    /// A trip switched to another run, and the token that puts it back.
    public struct TripRideSwitch: Equatable, Sendable {
        public let undo: String
        public let line: String
        public let departTime: String
        public let arriveTime: String
    }

    /// The ride, named as the trip has it (its stop, when it was to leave) and where it is in the trip.
    private func ride(_ leg: Int, from: String, at: Date) -> [String: Any] {
        ["leg": leg, "from": from, "at": ISO8601DateFormatter().string(from: at)]
    }

    public func tripRideCheck(leg: Int, from: String, at: Date, leftAt: Date) async throws(APIFailure) -> TripRideCheck {
        var body = ride(leg, from: from, at: at)
        body["left_at"] = ISO8601DateFormatter().string(from: leftAt)
        let reply = try await composer("POST", "travel/trip/ride/check", body)
        guard reply.status == 200, let json = try? JSONSerialization.jsonObject(with: reply.data) as? [String: Any] else { throw .unreachable }
        if json["planned"] as? Bool == true { return .planned }
        if let sure = (json["sure"] as? [String: Any]).flatMap(TripRide.init) { return .sure(sure) }
        let runs = ((json["runs"] as? [[String: Any]]) ?? []).compactMap(TripRide.init)
        return runs.count > 1 ? .choose(runs) : .unknown
    }

    public func tripRideSwitch(leg: Int, from: String, at: Date, to ride: TripRide) async throws(APIFailure) -> TripRideSwitch {
        var body = self.ride(leg, from: from, at: at)
        body["line"] = ride.line
        body["depart_at"] = ride.departAt
        if let trip = ride.trip { body["trip"] = trip }
        let reply = try await composer("POST", "travel/trip/ride", body)
        return try switched(reply)
    }

    /// A change a delay broke: the next tram there once they can be at its stop.
    public func tripRideNext(leg: Int, from: String, at: Date, readyAt: Date) async throws(APIFailure) -> TripRideSwitch {
        var body = ride(leg, from: from, at: at)
        body["ready_at"] = ISO8601DateFormatter().string(from: readyAt)
        let reply = try await composer("POST", "travel/trip/ride/next", body)
        return try switched(reply)
    }

    public func tripRideUndo(_ undo: String) async throws(APIFailure) {
        let reply = try await composer("POST", "travel/trip/ride/undo", ["undo": undo])
        if reply.status == 422 { throw .refused(Self.reason(reply.data) ?? "That can’t be undone any more.") }
        guard (200..<300).contains(reply.status) else { throw .unreachable }
    }

    private func switched(_ reply: Raw) throws(APIFailure) -> TripRideSwitch {
        if reply.status == 422 { throw .refused(Self.reason(reply.data) ?? "That tram can’t be followed from here.") }
        if reply.status == 429 { throw .tooManyTries }
        guard reply.status == 200, let json = try? JSONSerialization.jsonObject(with: reply.data) as? [String: Any],
              let undo = json["undo"] as? String, let line = json["line"] as? String,
              let departTime = json["depart_time"] as? String, let arriveTime = json["arrive_time"] as? String else { throw .unreachable }
        return TripRideSwitch(undo: undo, line: line, departTime: departTime, arriveTime: arriveTime)
    }

    /// A 422's reason in the server's words.
    private static func reason(_ data: Data) -> String? {
        let json = (try? JSONSerialization.jsonObject(with: data) as? [String: Any]) ?? [:]
        return (json["errors"] as? [String: [String]])?.values.first?.first ?? json["message"] as? String
    }

    private struct Raw: Sendable {
        let status: Int
        let data: Data
    }

    private static func composerReply(_ raw: Raw) -> ComposerReply {
        let json = (try? JSONSerialization.jsonObject(with: raw.data) as? [String: Any]) ?? [:]
        if (200..<300).contains(raw.status) { return ComposerReply(workspace: try? ComposerWorkspace.decode(raw.data), refusal: nil) }
        // A refusal says why, in the server's words, and may carry the workspace as it now is.
        let workspace = (json["workspace"]).flatMap { try? JSONSerialization.data(withJSONObject: $0) }.flatMap { try? ComposerWorkspace.decode($0) }
        let refusal = (json["refusal"] as? [String: Any])?["message"] as? String ?? json["message"] as? String ?? (json["text"] as? String)
        return ComposerReply(workspace: workspace, refusal: refusal ?? "That didn’t go through. Try again in a moment.")
    }

    /// Composer's requests: JSON with the token. Signed out and too many tries throw; any other
    /// answer comes back with its status, for the caller to read.
    /// `retry`: a dropped connection or a gateway hiccup is tried again after a short pause. Reads always
    /// are; a write only when the server takes it twice as once (starting the same trip).
    private func composer(_ method: String, _ path: String, _ body: [String: Any]? = nil, timeout: TimeInterval = 30, retry: Bool? = nil) async throws(APIFailure) -> Raw {
        guard let url = URL(string: path, relativeTo: baseURL.appendingPathComponent("")) else { throw .unreachable }
        var request = URLRequest(url: url, timeoutInterval: timeout)
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if let token = token() { request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        if let body {
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.httpBody = try? JSONSerialization.data(withJSONObject: body)
        }
        let again = retry ?? (method == "GET")
        var data = Data()
        var status = 0
        for attempt in 0...Transient.pauses.count {
            do {
                let response: URLResponse
                (data, response) = try await URLSession.shared.data(for: request)
                status = (response as? HTTPURLResponse)?.statusCode ?? 0
                guard again, Transient.gateway(status), attempt < Transient.pauses.count else { break }
            } catch {
                guard again, Transient.dropped(error), attempt < Transient.pauses.count else { throw .unreachable }
            }
            do { try await Task.sleep(for: Transient.pauses[attempt]) } catch { throw .unreachable }
        }
        switch status {
        case 401: throw .signedOut
        case 403 where path.hasPrefix("composer/workspace"): throw .refused("Confirm your email address first.")
        case 429: throw .tooManyTries
        case 500...: throw .unreachable
        default: return Raw(status: status, data: data)
        }
    }

    /// The bell's full record, newest first.
    public func alerts() async throws(APIFailure) -> [BellCard] {
        let output = try await calling { try await client.alerts_index() }
        switch output {
        case let .ok(ok):
            return try body { try ok.body.json }.alerts.map {
                BellCard(id: $0.id, category: $0.category, severity: $0.severity, title: $0.title, body: $0.body, source: $0.source,
                      at: $0.at.flatMap(Self.date(from:)), read: $0.read, url: $0.url.flatMap(URL.init(string:)), lines: $0.lines, stops: $0.stops)
            }
        case .unauthorized: throw .signedOut
        case let .undocumented(status, _): throw failure(status)
        default: throw .unreachable
        }
    }

    /// Opened: the card counts as read.
    public func readAlert(_ id: Int) async throws(APIFailure) {
        let output = try await calling { try await client.alerts_read(path: .init(alert: id)) }
        switch output {
        case .ok: return
        case .unauthorized: throw .signedOut
        case let .undocumented(status, _): throw failure(status)
        default: throw .unreachable
        }
    }

    /// What to bring to a Paperwork appointment.
    public func bringList(process: String) async throws(APIFailure) -> BringList {
        let output = try await calling { try await client.paperworkDocuments_index(path: .init(process: process)) }
        switch output {
        case let .ok(ok): return Self.bring(try body { try ok.body.json })
        case .unauthorized: throw .signedOut
        case let .undocumented(status, _): throw failure(status)
        default: throw .unreachable
        }
    }

    /// Ticked or unticked on Today; the same record as Paperwork's. Returns the list as it is now.
    public func tick(process: String, document: String, ready: Bool) async throws(APIFailure) -> BringList {
        let output = try await calling {
            try await client.paperworkDocuments_update(path: .init(process: process, document: document), body: .json(.init(ready: ready)))
        }
        switch output {
        case let .ok(ok): return Self.bring(try body { try ok.body.json })
        case .unauthorized: throw .signedOut
        case let .unprocessableContent(refusal): throw refused(refusal)
        case let .undocumented(status, _): throw failure(status)
        default: throw .unreachable
        }
    }

    private static func bring(_ list: Components.Schemas.BringListResource) -> BringList {
        BringList(title: list.title, ready: list.ready, total: list.total,
                  documents: list.documents.map { .init(id: $0.id, title: $0.title, ready: $0.ready, note: $0.note) })
    }

    public func whatsOn(date: String?) async throws(APIFailure) -> WhatsOn {
        let output = try await calling { try await client.events_day(query: .init(date: date)) }
        switch output {
        case let .ok(ok):
            let day = try body { try ok.body.json }
            return WhatsOn(
                date: day.date, today: day.today, ended: day.ended,
                days: day.days.map { WhatsOn.Day(date: $0.date, count: $0.count) },
                categories: day.categories.map { WhatsOn.Category(id: $0.id, label: $0.label) },
                listings: day.listings.map { listing in
                    let starts = Self.date(from: listing.startsAt)
                    let idea = TodayPage.Idea(id: listing.id, candidate: listing.candidate, saved: listing.saved, title: listing.title, category: listing.categoryLabel,
                                              emoji: listing.emoji, startsAt: starts, endsAt: listing.endsAt.flatMap(Self.date(from:)), timed: listing.timed,
                                              untimed: listing.untimed, tomorrow: day.date != day.today, price: listing.price, place: listing.place, area: listing.area)
                    return WhatsOn.Listing(idea: idea, category: listing.category, free: listing.free, noGerman: listing.noGerman,
                                           photo: listing.photoUrl.flatMap(URL.init(string:)), language: listing.language, summary: listing.summary,
                                           summaryLang: listing.summaryLang, tip: listing.tip, source: listing.sourceUrl.flatMap(URL.init(string:)),
                                           latitude: listing.lat, longitude: listing.lng)
                }
            )
        case .unauthorized: throw .signedOut
        case let .unprocessableContent(refusal): throw refused(refusal)
        case .forbidden: throw .refused("Confirm your email address first.")
        case let .undocumented(status, _): throw failure(status)
        }
    }

    /// Today: the day, when to leave for the next plan (from where the phone is), notes and ideas.
    public func today(latitude: Double?, longitude: Double?) async throws(APIFailure) -> TodayPage {
        let output = try await calling { try await client.today_show(query: .init(lat: latitude, lng: longitude)) }
        switch output {
        case let .ok(ok):
            let today = try body { try ok.body.json }
            var page = TodayPage(
                date: today.date,
                now: Self.date(from: today.now) ?? .now,
                weather: today.weather.map { w in
                    TodayPage.Weather(temperature: w.temperature, condition: w.condition, icon: w.icon, summary: w.summary, cycling: w.cycling,
                                      feelsLike: w.feelsLike, high: w.high, low: w.low,
                                      hours: w.hours.map { TodayPage.Weather.Hour(hour: $0.hour, temperature: $0.temperature, icon: $0.icon, rain: $0.rain) },
                                      today: w.today.map { TodayPage.Weather.Hour(hour: $0.hour, temperature: $0.temperature, icon: $0.icon, rain: $0.rain, condition: $0.condition) },
                                      days: w.days.map { TodayPage.Weather.Day(date: $0.date, low: $0.low, high: $0.high, icon: $0.icon, rain: $0.rain) })
                },
                plans: today.plans.map { p in
                    TodayPage.Plan(id: p.id, title: p.title, subtitle: p.subtitle, start: p.startTime, end: p.endTime, place: p.place, state: p.state, completed: p.completed,
                                   editable: p.editable, bring: p.bring.map { TodayPage.Plan.Bring(title: $0.title, ready: $0.ready, total: $0.total, process: $0.process) })
                },
                links: today.links.map {
                    TodayPage.Link(from: $0.fromId, to: $0.toId, reason: $0.reason, basis: $0.basis, travelMinutes: $0.travelMin, gapMinutes: $0.gapMin)
                },
                leave: today.leave.map {
                    TodayPage.Leave(to: $0.to, start: $0.startTime, status: $0.status, leaveTime: $0.leaveTime, leaveInMinutes: $0.leaveInMin,
                                    arriveTime: $0.arriveTime, spareMinutes: $0.spareMin, line: $0.line, color: $0.color, departs: $0.departs, from: $0.from,
                                    walkMinutes: $0.walkMin, transfers: $0.transfers, live: $0.live)
                },
                deadline: today.deadline.map {
                    TodayPage.Deadline(kind: $0.kind, document: $0.document, title: $0.title, date: $0.date, daysRemaining: $0.daysRemaining, next: $0.next,
                                       ready: $0.ready, total: $0.total, process: $0.process)
                },
                needs: today.needs.map { TodayPage.Need(id: $0.id, kind: $0.kind, severity: $0.severity, headline: $0.headline, detail: $0.detail, line: $0.line, color: $0.color, alert: $0.alertId) },
                ideas: today.ideas.map {
                    TodayPage.Idea(id: $0.id, candidate: $0.candidate, saved: $0.saved, why: $0.why, reasons: $0.reasons, title: $0.title, category: $0.category, emoji: $0.emoji, startsAt: $0.startsAt.flatMap(Self.date(from:)),
                                   endsAt: $0.endsAt.flatMap(Self.date(from:)), timed: $0.timed, untimed: $0.untimed, tomorrow: $0.tomorrow,
                                   price: $0.price, place: $0.place, area: $0.area, language: $0.language)
                },
                ideasPart: today.ideasPart,
                free: today.free.map { f in
                    TodayPage.Free(anchorTitle: f.anchor?.title, anchorArea: f.anchor?.area, anchorEnd: f.anchor?.end, near: f.near, at: f.at,
                                   places: f.places.map { TodayPage.Free.Place(id: $0.id, candidate: $0.candidate, name: $0.name, kind: $0.kind, label: $0.label, km: $0.km, openUntil: $0.openUntil, latitude: $0.lat, longitude: $0.lng) })
                },
                paperwork: today.paperwork.map {
                    TodayPage.Paperwork(processTitle: $0.processTitle, title: $0.title, ready: $0.ready, total: $0.total, process: $0.process)
                },
                continuation: today.continuation.map {
                    TodayPage.Continuation(kind: $0.kind, title: $0.title, lastAt: $0.lastAt.flatMap(Self.date(from:)))
                },
                saved: today.saved.map { s in
                    TodayPage.Saved(count: s.count, items: s.items.map {
                        TodayPage.Saved.Item(type: $0._type, id: $0.id, candidate: $0.candidate, title: $0.title, date: $0.date, place: $0.place, label: $0.label, area: $0.area)
                    })
                },
                tomorrowDate: today.comingUp.tomorrow.date,
                tomorrow: today.comingUp.tomorrow.plans.map { TodayPage.Later(title: $0.title, place: $0.place, start: $0.start) },
                later: today.comingUp.later.map { TodayPage.Later(date: $0.date, title: $0.title, place: $0.place, start: $0.start) }
            )
            page.revision = today.revision
            page.leave?.planId = today.leave?.planId
            page.leave?.ways = today.leave?.ways.enumerated().map { index, way in
                var mapped = TodayPage.Way(id: index, leaveTime: way.leaveTime, arriveTime: way.arriveTime, spareMinutes: way.spareMin, status: way.status, mode: way.mode,
                                           lines: way.lines.map { .init(line: $0.line, color: $0.color) }, departs: way.departs, from: way.from,
                                           walkMinutes: way.walkMin, transfers: way.transfers, minutes: way.minutes, inPlan: way.inPlan, live: way.live)
                mapped.legs = way.legs.compactMap { leg in
                    guard let departAt = Self.date(from: leg.departAt), let arriveAt = Self.date(from: leg.arriveAt) else { return nil }
                    return TodayPage.Trip.Leg(mode: leg.mode, line: leg.line, color: leg.color, headsign: leg.headsign, from: leg.from, to: leg.to,
                                              departAt: departAt, arriveAt: arriveAt, departTime: leg.departTime, arriveTime: leg.arriveTime, minutes: leg.minutes,
                                              stops: leg.stops, polyline: leg.polyline,
                                              turns: leg.turns.map { TodayPage.Turn(direction: $0.direction, street: $0.street, metres: $0.metres, polyline: $0.polyline, levelChange: $0.levelChange) },
                                              stopsOnTheWay: leg.stopsOnTheWay.map { TodayPage.Trip.Leg.Stop(name: $0.name, time: $0.time, latitude: $0.lat, longitude: $0.lng) },
                                              delayMin: leg.delayMin, cancelled: leg.cancelled)
                }
                return mapped
            } ?? []
            page.trip = today.trip.map { trip in
                TodayPage.Trip(to: trip.to, arriveTime: trip.arriveTime, startTime: trip.startTime, legs: trip.legs.compactMap { leg in
                    guard let departAt = Self.date(from: leg.departAt), let arriveAt = Self.date(from: leg.arriveAt) else { return nil }
                    return TodayPage.Trip.Leg(mode: leg.mode, line: leg.line, color: leg.color, headsign: leg.headsign, from: leg.from, to: leg.to,
                                              departAt: departAt, arriveAt: arriveAt, departTime: leg.departTime, arriveTime: leg.arriveTime, minutes: leg.minutes,
                                              stops: leg.stops, polyline: leg.polyline,
                                              turns: leg.turns.map { TodayPage.Turn(direction: $0.direction, street: $0.street, metres: $0.metres, polyline: $0.polyline, levelChange: $0.levelChange) },
                                              stopsOnTheWay: leg.stopsOnTheWay.map { TodayPage.Trip.Leg.Stop(name: $0.name, time: $0.time, latitude: $0.lat, longitude: $0.lng) },
                                              delayMin: leg.delayMin, cancelled: leg.cancelled)
                })
            }
            page.welcome = today.welcome
            page.personalised = today.personalised
            page.paperworkStatus = today.paperworkStatus.rawValue
            page.rankPaused = today.rankPaused.map {
                TodayPage.RankPause(notice: $0.notice, changes: $0.changes, modelChanged: $0.modelChanged, noticeVersion: $0.noticeVersion, providerVersion: $0.providerVersion)
            }
            return page
        case .unauthorized: throw .signedOut
        case let .unprocessableContent(refusal): throw refused(refusal)
        case let .undocumented(status, _): throw failure(status)
        }
    }

    // MARK: - Reading the answers

    private func outcome(_ result: Components.Schemas.SignInResource) throws(APIFailure) -> SignInOutcome {
        if let challenge = result.challenge {
            return .twoFactor(challenge: challenge)
        }
        guard let value = result.token, let user = result.user,
              let expires = result.expiresAt.flatMap(Self.date(from:))
        else {
            throw .unreachable
        }
        return .signedIn(StoredSession(token: AppToken(value: value, expires: expires), account: account(user)))
    }

    private func account(_ user: Components.Schemas.UserResource) -> Account {
        Account(name: user.name, email: user.email, emailVerified: user.emailVerified, hasPassword: user.hasPassword)
    }

    private func refused(_ refusal: Components.Responses.ValidationException) -> APIFailure {
        guard case let .json(payload) = refusal.body else { return .unreachable }
        return .refused(payload.message)
    }

    private func failure(_ status: Int) -> APIFailure {
        switch status {
        case 401: .signedOut
        case 429: .tooManyTries
        default: .unreachable
        }
    }

    private func calling<T>(_ call: () async throws -> T) async throws(APIFailure) -> T {
        do {
            return try await call()
        } catch {
            throw .unreachable
        }
    }

    private func body<T>(_ read: () throws -> T) throws(APIFailure) -> T {
        do {
            return try read()
        } catch {
            throw .unreachable
        }
    }

    static func date(from text: String) -> Date? {
        try? Date.ISO8601FormatStyle(includingFractionalSeconds: false).parse(text)
    }
}

/// A request that got no answer: the connection dropped, timed out or never came up (underground, between
/// cells), or a gateway in front of the server stood in for it. Asking again is safe where asking twice is
/// the same as asking once.
enum Transient {
    /// The pauses before each try again: a dropped connection comes back within a second or two, or not soon.
    static let pauses: [Duration] = [.milliseconds(600), .milliseconds(1500)]

    static func dropped(_ error: any Error) -> Bool {
        if let wrapped = error as? ClientError { return dropped(wrapped.underlyingError) }
        let error = error as NSError
        guard error.domain == NSURLErrorDomain else { return false }
        return [NSURLErrorNetworkConnectionLost, NSURLErrorTimedOut, NSURLErrorCannotConnectToHost, NSURLErrorDNSLookupFailed,
                NSURLErrorCannotFindHost, NSURLErrorNotConnectedToInternet, NSURLErrorDataNotAllowed,
                NSURLErrorInternationalRoamingOff, NSURLErrorSecureConnectionFailed].contains(error.code)
    }

    static func gateway(_ status: Int) -> Bool { [502, 503, 504].contains(status) }
}

/// Every read the generated client makes (Today, the boards, the routes) is tried again when it got no
/// answer, so a dropped connection underground isn't "Times unavailable" or a dead tap.
private struct RetryReads: ClientMiddleware {
    func intercept(
        _ request: HTTPRequest,
        body: HTTPBody?,
        baseURL: URL,
        operationID: String,
        next: @Sendable (HTTPRequest, HTTPBody?, URL) async throws -> (HTTPResponse, HTTPBody?)
    ) async throws -> (HTTPResponse, HTTPBody?) {
        guard request.method == .get || request.method == .head else { return try await next(request, body, baseURL) }
        for pause in Transient.pauses {
            do {
                let answer = try await next(request, body, baseURL)
                guard Transient.gateway(answer.0.status.code) else { return answer }
            } catch where Transient.dropped(error) {}
            try await Task.sleep(for: pause)
        }
        return try await next(request, body, baseURL)
    }
}

private struct BearerToken: ClientMiddleware {
    let token: @Sendable () -> String?

    func intercept(
        _ request: HTTPRequest,
        body: HTTPBody?,
        baseURL: URL,
        operationID: String,
        next: @Sendable (HTTPRequest, HTTPBody?, URL) async throws -> (HTTPResponse, HTTPBody?)
    ) async throws -> (HTTPResponse, HTTPBody?) {
        var request = request
        if let token = token() {
            request.headerFields[.authorization] = "Bearer \(token)"
        }
        return try await next(request, body, baseURL)
    }
}

/// How starting a trip went: on its way, or another trip is running and they're asked first.
public enum TripStart: Equatable, Sendable { case started, running }
