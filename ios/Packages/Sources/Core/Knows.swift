import Foundation

/// What Expadu knows about the person (GET /api/v1/memory): what they told Composer or added
/// themselves, what personalisation learned they like and skip, and their situation and German
/// level. Everything here is theirs to change; with personalisation off it's empty.
public struct Knows: Equatable, Sendable, Decodable {
    public var personalised: Bool
    /// On under the wording shown now; false means turning it on again agrees to what it says now.
    public var current: Bool
    public var facts: [Fact]
    public var taste: Taste?
    public var situation: String?
    public var germanLevel: String?
    public var limit: Int
    /// The kinds of event they picked themselves; nil when personalisation isn't on under its wording.
    public var picks: Picks?
    /// Every kind they can pick, in order.
    public var pickKinds: [PickKind] = []

    public struct Picks: Equatable, Sendable, Decodable {
        public var kinds: [String]
        public var done: Bool
    }

    public struct PickKind: Equatable, Sendable, Decodable, Hashable {
        public var key: String
        public var label: String
    }

    public struct Fact: Identifiable, Equatable, Sendable, Codable {
        public var id: Int
        public var text: String
        /// told: said to Composer; added: typed on this screen.
        public var source: String
        /// As the server keeps it, to the millisecond: Undo of a forget puts it back in its place.
        public var createdAt: String

        public init(id: Int, text: String, source: String, createdAt: String) {
            self.id = id; self.text = text; self.source = source; self.createdAt = createdAt
        }

        enum CodingKeys: String, CodingKey { case id, text, source, createdAt = "created_at" }
    }

    public struct Taste: Equatable, Sendable, Decodable {
        public var categories: [Leaning]
        public var veedels: [Leaning]
        public var dayparts: [String]
        public var week: String?
        public var signals: Int
    }

    /// A kind or a Veedel, and how much: strong, some, or avoid.
    public struct Leaning: Equatable, Sendable, Decodable, Hashable {
        public var name: String
        public var level: String
    }

    enum CodingKeys: String, CodingKey {
        case personalised, current = "personalisation_current", facts, taste, situation, germanLevel = "german_level", limit, picks, pickKinds = "pick_kinds"
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        personalised = try c.decode(Bool.self, forKey: .personalised)
        current = try c.decode(Bool.self, forKey: .current)
        facts = try c.decode([Fact].self, forKey: .facts)
        taste = try c.decodeIfPresent(Taste.self, forKey: .taste)
        situation = try c.decodeIfPresent(String.self, forKey: .situation)
        germanLevel = try c.decodeIfPresent(String.self, forKey: .germanLevel)
        limit = try c.decode(Int.self, forKey: .limit)
        picks = try c.decodeIfPresent(Picks.self, forKey: .picks)
        pickKinds = try c.decodeIfPresent([PickKind].self, forKey: .pickKinds) ?? []
    }

    public init(personalised: Bool, current: Bool, facts: [Fact], taste: Taste?, situation: String?, germanLevel: String?, limit: Int) {
        self.personalised = personalised; self.current = current; self.facts = facts; self.taste = taste
        self.situation = situation; self.germanLevel = germanLevel; self.limit = limit
    }

    /// The situations the profile knows, by key, as the profile names them.
    public static let situations: [(key: String, label: String)] = [
        ("non_eu_employee", "Non-EU employee"), ("eu_employee", "EU employee"), ("student", "Student"), ("freelancer", "Freelancer"),
        ("family_reunification", "Family reunification"), ("digital_nomad", "Digital nomad"), ("other", "Other"),
    ]

    /// German levels, by key.
    public static let germanLevels: [(key: String, label: String)] = [
        ("none", "None yet"), ("a1", "A1"), ("a2", "A2"), ("b1", "B1"), ("b2", "B2"), ("c1", "C1"), ("c2", "C2"),
    ]

    /// One line per leaning, in the screen's words: "Live music · Likes a lot".
    public var leanings: [(name: String, level: String, avoid: Bool)] {
        guard let taste else { return [] }
        let kinds = taste.categories.map { (name: Self.label($0.name), level: $0.level == "strong" ? "Likes a lot" : $0.level == "avoid" ? "Tends to skip" : "Likes", avoid: $0.level == "avoid") }
        let areas = taste.veedels.map { (name: $0.name, level: $0.level == "strong" ? "Goes a lot" : $0.level == "avoid" ? "Rarely goes" : "Goes", avoid: $0.level == "avoid") }
        var when: [(name: String, level: String, avoid: Bool)] = []
        if !taste.dayparts.isEmpty {
            let parts = taste.dayparts.map { $0.capitalized }
            when.append((name: ListFormatter.localizedString(byJoining: parts) + (taste.week == "weekend" ? ", mostly weekends" : taste.week == "weekday" ? ", mostly weekdays" : ""), level: "Usually out", avoid: false))
        }
        return [kinds.filter { !$0.avoid }, areas.filter { !$0.avoid }, when, kinds.filter(\.avoid), areas.filter(\.avoid)].flatMap { $0 }
    }

    /// A kind as people say it: "fast_food" is "Fast food".
    static func label(_ name: String) -> String {
        let words = name.replacingOccurrences(of: "_", with: " ")
        return words.prefix(1).uppercased() + words.dropFirst()
    }
}
