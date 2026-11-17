import Foundation

/// Their notifications, as the web's Settings keeps them: which kinds, quiet hours, the brief's time.
public struct NotificationSettings: Equatable, Sendable {
    /// Each kind on or off: "events", "transit", "weather", "brief" and the rest.
    public var preferences: [String: Bool]
    /// Quiet hours, "HH:mm"; they may run past midnight.
    public var quietFrom: String
    public var quietTo: String
    /// When the morning brief comes, "HH:mm"; nil: before their first thing of the day.
    public var briefAt: String?

    public init(preferences: [String: Bool], quietFrom: String, quietTo: String, briefAt: String?) {
        self.preferences = preferences
        self.quietFrom = quietFrom
        self.quietTo = quietTo
        self.briefAt = briefAt
    }

    public func on(_ kind: String) -> Bool { preferences[kind] ?? true }

    /// One change to them.
    public enum Change: Sendable, Equatable {
        case kind(String, on: Bool)
        case quietFrom(String)
        case quietTo(String)
        /// nil: before their first thing of the day.
        case briefAt(String?)
    }

    /// Them, with the change made.
    public func with(_ change: Change) -> NotificationSettings {
        var next = self
        switch change {
        case let .kind(kind, on): next.preferences[kind] = on
        case let .quietFrom(at): next.quietFrom = at
        case let .quietTo(at): next.quietTo = at
        case let .briefAt(at): next.briefAt = at
        }
        return next
    }
}
