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

    public init(baseURL: URL, device: String, token: @escaping @Sendable () -> String?) {
        let middleware = BearerToken(token: token)
        client = Client(
            serverURL: baseURL,
            configuration: Configuration(dateTranscoder: .iso8601),
            transport: URLSessionTransport(),
            middlewares: [middleware]
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

    public func register(name: String, email: String, password: String) async throws(APIFailure) -> SignInOutcome {
        let output = try await calling {
            try await client.signIn_register(body: .json(.init(name: name, email: email, password: password, passwordConfirmation: password, deviceName: device)))
        }
        switch output {
        case let .ok(ok): return try outcome(try body { try ok.body.json })
        case let .unprocessableContent(refusal): throw refused(refusal)
        case let .undocumented(status, _): throw failure(status)
        }
    }

    public func forgotPassword(email: String) async throws(APIFailure) {
        let output = try await calling {
            try await client.signIn_forgotPassword(body: .json(.init(email: email)))
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
                needs: today.needs.map { TodayPage.Need(id: $0.id, kind: $0.kind, severity: $0.severity, headline: $0.headline, detail: $0.detail) },
                ideas: today.ideas.map {
                    TodayPage.Idea(id: $0.id, candidate: $0.candidate, saved: $0.saved, why: $0.why, reasons: $0.reasons, title: $0.title, category: $0.category, emoji: $0.emoji, startsAt: $0.startsAt.flatMap(Self.date(from:)),
                                   endsAt: $0.endsAt.flatMap(Self.date(from:)), timed: $0.timed, untimed: $0.untimed, tomorrow: $0.tomorrow,
                                   price: $0.price, place: $0.place, area: $0.area)
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
                                              stops: leg.stops, polyline: leg.polyline)
                }
                return mapped
            } ?? []
            page.trip = today.trip.map { trip in
                TodayPage.Trip(to: trip.to, arriveTime: trip.arriveTime, startTime: trip.startTime, legs: trip.legs.compactMap { leg in
                    guard let departAt = Self.date(from: leg.departAt), let arriveAt = Self.date(from: leg.arriveAt) else { return nil }
                    return TodayPage.Trip.Leg(mode: leg.mode, line: leg.line, color: leg.color, headsign: leg.headsign, from: leg.from, to: leg.to,
                                              departAt: departAt, arriveAt: arriveAt, departTime: leg.departTime, arriveTime: leg.arriveTime, minutes: leg.minutes,
                                              stops: leg.stops, polyline: leg.polyline)
                })
            }
            page.welcome = today.welcome
            page.personalised = today.personalised
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
