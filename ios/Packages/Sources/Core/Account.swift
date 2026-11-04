import Foundation

/// The signed-in person, as /api/v1/me says.
public struct Account: Codable, Equatable, Sendable {
    public let name: String
    public let email: String
    public let emailVerified: Bool
    public let hasPassword: Bool

    public init(name: String, email: String, emailVerified: Bool, hasPassword: Bool) {
        self.name = name
        self.email = email
        self.emailVerified = emailVerified
        self.hasPassword = hasPassword
    }

    public var initial: String {
        String(name.trimmingCharacters(in: .whitespaces).prefix(1)).uppercased()
    }
}

/// A token and when the server stops accepting it.
public struct AppToken: Codable, Equatable, Sendable {
    public let value: String
    public let expires: Date

    public init(value: String, expires: Date) {
        self.value = value
        self.expires = expires
    }

    /// Long before it ends, so a phone left in a drawer for weeks still comes back signed in.
    public func wantsRefresh(now: Date = .now) -> Bool {
        expires.timeIntervalSince(now) < 30 * 24 * 60 * 60
    }
}
