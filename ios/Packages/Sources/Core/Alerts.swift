import Foundation

/// The bell's full record: everything Today showed, and what it left out.
public struct BellCard: Equatable, Sendable, Identifiable {
    /// The canvas's chips: "lines", "weather", "paper" or "city".
    public let id: Int
    public let category: String
    /// "danger", "warn", "success" or "info".
    public let severity: String
    public let title: String
    public let body: String?
    /// Who says so: "KVB live", "City notices".
    public let source: String
    public let at: Date?
    public var read: Bool
    /// KVB's own page for a notice.
    public let url: URL?
    public let lines: [String]
    public let stops: [String]

    public init(id: Int, category: String, severity: String, title: String, body: String?, source: String, at: Date?, read: Bool, url: URL?, lines: [String], stops: [String]) {
        self.id = id
        self.category = category
        self.severity = severity
        self.title = title
        self.body = body
        self.source = source
        self.at = at
        self.read = read
        self.url = url
        self.lines = lines
        self.stops = stops
    }

    /// "12:58" today, "Yesterday", or the day.
    public func when(now: Date = .now) -> String {
        guard let at else { return "" }
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TodayPage.berlin
        if calendar.isDate(at, inSameDayAs: now) { return TodayPage.clock(at) }
        if let yesterday = calendar.date(byAdding: .day, value: -1, to: now), calendar.isDate(at, inSameDayAs: yesterday) { return "Yesterday" }
        let format = DateFormatter()
        format.locale = Locale(identifier: "en_GB")
        format.timeZone = TodayPage.berlin
        format.dateFormat = "d MMM"
        return format.string(from: at)
    }
}

/// What to bring to a Paperwork appointment, from their Paperwork record; ticking here ticks it there.
public struct BringList: Equatable, Sendable {
    public struct Document: Equatable, Sendable, Identifiable {
        public let id: String
        public let title: String
        public var ready: Bool
        public let note: String?

        public init(id: String, title: String, ready: Bool, note: String?) {
            self.id = id
            self.title = title
            self.ready = ready
            self.note = note
        }
    }

    public let title: String?
    public let ready: Int
    public let total: Int
    public var documents: [Document]

    public init(title: String?, ready: Int, total: Int, documents: [Document]) {
        self.title = title
        self.ready = ready
        self.total = total
        self.documents = documents
    }
}
