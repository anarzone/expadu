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
