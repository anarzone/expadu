import Foundation

/// What's on for one day (All events on Today): its listings, the days ahead, and the kinds on it.
/// The sheet searches and filters these itself, as the web's panel does.
public struct WhatsOn: Equatable, Sendable {
    public struct Day: Equatable, Sendable, Identifiable {
        public let date: String
        public let count: Int
        public var id: String { date }

        public init(date: String, count: Int) {
            self.date = date
            self.count = count
        }
    }

    public struct Category: Equatable, Sendable, Identifiable {
        public let id: String
        public let label: String

        public init(id: String, label: String) {
            self.id = id
            self.label = label
        }
    }

    public struct Listing: Equatable, Sendable, Identifiable {
        public var idea: TodayPage.Idea
        public let category: String
        public let free: Bool
        /// Why a guest needs no German, or nil.
        public let noGerman: String?
        public let photo: URL?
        public var id: String { idea.candidate }

        public init(idea: TodayPage.Idea, category: String, free: Bool, noGerman: String?, photo: URL?) {
            self.idea = idea
            self.category = category
            self.free = free
            self.noGerman = noGerman
            self.photo = photo
        }

        /// Search reads its title, place, area and kind, and "free" and "english".
        public func matches(_ query: String) -> Bool {
            let words = query.lowercased().split(separator: " ")
            guard !words.isEmpty else { return true }
            let text = [idea.title, idea.place, idea.area, idea.category, free ? "free" : nil, noGerman == nil ? nil : "english no german"]
                .compactMap { $0 }.joined(separator: " ").lowercased()
            return words.allSatisfy { text.contains($0) }
        }
    }

    /// A time on the canvas's pills: "On now", "19:00", or "All day".
    public struct Group: Equatable, Sendable, Identifiable {
        public let title: String
        public let now: Bool
        public let listings: [Listing]
        public var id: String { title }
    }

    public let date: String
    public let today: String
    /// How many of today's listings have already ended.
    public let ended: Int
    public let days: [Day]
    public let categories: [Category]
    public var listings: [Listing]

    public init(date: String, today: String, ended: Int, days: [Day], categories: [Category], listings: [Listing]) {
        self.date = date
        self.today = today
        self.ended = ended
        self.days = days
        self.categories = categories
        self.listings = listings
    }

    public var isToday: Bool { date == today }

    /// "Today · 7 Nov", "Sun 8 Nov".
    public func label(for day: String) -> String {
        day == today ? "Today · " + (TodayPage.day(day, format: "d MMM") ?? day) : TodayPage.shortDay(day)
    }

    /// The canvas's groups: On now first, then each start time, All day last.
    public func groups(_ listings: [Listing], at now: Date) -> [Group] {
        var keyed: [String: [Listing]] = [:]
        for listing in listings {
            let idea = listing.idea
            let key: String
            if !idea.timed || idea.startsAt == nil {
                key = "All day"
            } else if let start = idea.startsAt, isToday, start <= now {
                key = "On now"
            } else {
                key = TodayPage.clock(idea.startsAt!)
            }
            keyed[key, default: []].append(listing)
        }
        return keyed.keys.sorted { a, b in
            if a == "On now" || b == "All day" { return a != b }
            if b == "On now" || a == "All day" { return false }
            return a < b
        }
        .map { Group(title: $0, now: $0 == "On now", listings: keyed[$0] ?? []) }
    }
}
