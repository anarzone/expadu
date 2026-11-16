import Foundation

/// A moment the phone tells the person about with its own notification (GET /api/v1/moments): when
/// to leave, an event they asked to be reminded of, a risk to their plan, the day's brief. Its id is
/// stable, so a moment that moved replaces the one scheduled, and one that went is taken off.
public struct Moment: Identifiable, Equatable, Sendable, Decodable {
    public let id: String
    /// leave, event, plan or brief.
    public let kind: String
    public let at: Date
    public let title: String
    public let body: String
    /// Where a tap opens: /travel, /today, or an event's page.
    public let link: String

    public init(id: String, kind: String, at: Date, title: String, body: String, link: String) {
        self.id = id; self.kind = kind; self.at = at; self.title = title; self.body = body; self.link = link
    }
}
