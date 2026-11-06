import Foundation

/// Today, as the server reads it: the weather, the day's plans and when to leave for the next,
/// the notes that need the person, ideas for the time they have, and tomorrow.
public struct TodayPage: Equatable, Sendable {
    public struct Weather: Equatable, Sendable {
        public let temperature: Int
        public let condition: String
        public let icon: String

        public init(temperature: Int, condition: String, icon: String) {
            self.temperature = temperature
            self.condition = condition
            self.icon = icon
        }
    }

    public struct Plan: Equatable, Sendable, Identifiable {
        public let id: String
        public let title: String
        public let subtitle: String?
        public let start: String?
        public let end: String?
        public let place: String?
        /// "upcoming", "ongoing" or "past".
        public let state: String
        public let completed: Bool

        public init(id: String, title: String, subtitle: String?, start: String?, end: String?, place: String?, state: String, completed: Bool) {
            self.id = id
            self.title = title
            self.subtitle = subtitle
            self.start = start
            self.end = end
            self.place = place
            self.state = state
            self.completed = completed
        }

        public var isPast: Bool { completed || state == "past" }
    }

    /// When to leave for the next plan, and the line that takes them.
    public struct Leave: Equatable, Sendable {
        public let to: String
        public let start: String?
        /// "fits", "tight", "late" or "unavailable".
        public let status: String
        public let leaveTime: String?
        public let leaveInMinutes: Int?
        public let arriveTime: String?
        public let spareMinutes: Int?
        public let line: String?
        public let color: String?
        /// When the line itself goes, from `from`.
        public let departs: String?
        public let from: String?
        public let live: Bool

        public init(to: String, start: String?, status: String, leaveTime: String?, leaveInMinutes: Int?, arriveTime: String?, spareMinutes: Int?, line: String?, color: String?, departs: String?, from: String?, live: Bool) {
            self.to = to
            self.start = start
            self.status = status
            self.leaveTime = leaveTime
            self.leaveInMinutes = leaveInMinutes
            self.arriveTime = arriveTime
            self.spareMinutes = spareMinutes
            self.line = line
            self.color = color
            self.departs = departs
            self.from = from
            self.live = live
        }

        /// Only a worked-out way gets the card; otherwise the plan stands alone on the line.
        public var isKnown: Bool { leaveTime != nil && arriveTime != nil }
    }

    public struct Need: Equatable, Sendable, Identifiable {
        public let id: String
        public let kind: String
        public let severity: String
        public let headline: String
        public let detail: String?

        public init(id: String, kind: String, severity: String, headline: String, detail: String?) {
            self.id = id
            self.kind = kind
            self.severity = severity
            self.headline = headline
            self.detail = detail
        }
    }

    public struct Idea: Equatable, Sendable, Identifiable {
        public let id: Int
        public let title: String
        public let meta: String
        public let category: String
        public let emoji: String?
        public let price: String?
        public let place: String?
        public let area: String?

        public init(id: Int, title: String, meta: String, category: String, emoji: String?, price: String?, place: String?, area: String?) {
            self.id = id
            self.title = title
            self.meta = meta
            self.category = category
            self.emoji = emoji
            self.price = price
            self.place = place
            self.area = area
        }
    }

    public struct Later: Equatable, Sendable {
        public let title: String
        public let place: String?
        public let start: String?

        public init(title: String, place: String?, start: String?) {
            self.title = title
            self.place = place
            self.start = start
        }
    }

    /// `yyyy-MM-dd`, in Cologne's time.
    public let date: String
    public let now: Date
    public let weather: Weather?
    public let plans: [Plan]
    public let leave: Leave?
    public let needs: [Need]
    public let ideas: [Idea]
    /// "morning", "afternoon", "evening"…: which part of the day the ideas are for.
    public let ideasPart: String
    public let tomorrow: [Later]

    public init(date: String, now: Date, weather: Weather?, plans: [Plan], leave: Leave?, needs: [Need], ideas: [Idea], ideasPart: String, tomorrow: [Later]) {
        self.date = date
        self.now = now
        self.weather = weather
        self.plans = plans
        self.leave = leave
        self.needs = needs
        self.ideas = ideas
        self.ideasPart = ideasPart
        self.tomorrow = tomorrow
    }

    /// The greeting the canvas uses, by Cologne's clock.
    public static func greeting(at date: Date, name: String) -> String {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TimeZone(identifier: "Europe/Berlin")!
        let hour = calendar.component(.hour, from: date)
        let first = name.split(separator: " ").first.map(String.init) ?? name
        let hello = hour < 11 ? "Guten Morgen" : hour < 18 ? "Hallo" : "Guten Abend"
        return first.isEmpty ? hello : "\(hello), \(first)"
    }

    /// "Friday, 6 November", in Cologne's time.
    public var dateLine: String {
        let parse = DateFormatter()
        parse.calendar = Calendar(identifier: .gregorian)
        parse.timeZone = TimeZone(identifier: "Europe/Berlin")
        parse.dateFormat = "yyyy-MM-dd"
        guard let day = parse.date(from: date) else { return date }
        let say = DateFormatter()
        say.locale = Locale(identifier: "en_GB")
        say.timeZone = parse.timeZone
        say.dateFormat = "EEEE, d MMMM"
        return say.string(from: day)
    }

    /// The ideas' heading: "Ideas for this evening".
    public var ideasTitle: String {
        switch ideasPart {
        case "morning": "Ideas for this morning"
        case "afternoon": "Ideas for this afternoon"
        case "evening", "night": "Ideas for this evening"
        case "tomorrow": "Ideas for tomorrow"
        default: "Ideas for today"
        }
    }
}
