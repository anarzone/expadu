import Foundation

/// Composer's workspace, as the server keeps it (App\Composer\WorkspaceStore::projectState): the
/// same object the web reads. Its conversations, the open one's messages, the plans and their
/// changes. Decoded leniently: a card the app doesn't know, or one it can't read, is left out and
/// the rest of the answer still shows.
public struct ComposerWorkspace: Decodable, Equatable, Sendable {
    public let revision: Int
    public let active: String
    public let focus: String?
    public let plans: [String: ComposerPlan]
    public let changes: [ComposerChange]
    public let conversations: [ComposerConversation]

    public static func decode(_ data: Data) throws -> ComposerWorkspace {
        try ComposerJSON.decoder.decode(ComposerWorkspace.self, from: data)
    }

    enum CodingKeys: String, CodingKey { case revision, active, focus, plans, changes, conversations }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        revision = try c.decode(Int.self, forKey: .revision)
        active = try c.decode(String.self, forKey: .active)
        focus = try c.decodeIfPresent(String.self, forKey: .focus)
        // Plans are keyed by their day; an empty one arrives as [] from PHP.
        plans = (try? c.decode([String: ComposerPlan].self, forKey: .plans)) ?? [:]
        changes = (try? c.decode([Lossy<ComposerChange>].self, forKey: .changes))?.compactMap(\.value) ?? []
        conversations = (try? c.decode([Lossy<ComposerConversation>].self, forKey: .conversations))?.compactMap(\.value) ?? []
    }

    /// The open conversation.
    public var current: ComposerConversation? { conversations.first { $0.id == active } }

    /// Conversations they asked in, newest first (the drawer's Recents).
    public var recents: [ComposerConversation] {
        conversations.filter { $0.asked > 0 }.sorted { $0.lastAt > $1.lastAt }
    }
}

public struct ComposerConversation: Decodable, Equatable, Sendable, Identifiable {
    public let id: String
    public let title: String
    public let createdAt: String?
    public let asked: Int
    public let lastAt: String
    public let messages: [ComposerMessage]

    enum CodingKeys: String, CodingKey { case id, title, createdAt, asked, lastAt, messages }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(String.self, forKey: .id)
        title = (try? c.decode(String.self, forKey: .title)) ?? ""
        createdAt = try? c.decode(String.self, forKey: .createdAt)
        asked = (try? c.decode(Int.self, forKey: .asked)) ?? 0
        lastAt = (try? c.decode(String.self, forKey: .lastAt)) ?? createdAt ?? ""
        messages = (try? c.decode([Lossy<ComposerMessage>].self, forKey: .messages))?.compactMap(\.value) ?? []
    }
}

/// One message: theirs, or Composer's answer with its cards.
public struct ComposerMessage: Decodable, Equatable, Sendable, Identifiable {
    public let id: String
    /// "user" or "assistant".
    public let role: String
    public let text: String
    public let createdAt: String?
    /// plan · change · undone · earlier_draft · bureaucracy · find · journey · trip · checklist · around · failed · refusal …
    public let kind: String?
    /// A plan card's day as it is now (null once cancelled).
    public let plan: ComposerPlan?
    public let date: String?
    public let changeId: String?
    public let rows: [ComposerChangeRow]?
    /// The assistant's checked words; may be empty.
    public let lead: String?
    public let blocks: [ComposerBlock]
    public let title: String?
    public let suggestions: [ComposerSuggestion]
    public let sources: [ComposerLink]
    /// "ai", "code" or "listings": who wrote the sentences.
    public let written: String?
    /// off · limited · unpermitted · failed · unchecked: why the AI didn't write it.
    public let withoutAi: String?
    /// Personalisation is off and would have changed this: invite, once.
    public let invite: Bool?
    /// What the AI was given, in plain words, for the fine print.
    public let lookedAt: [String]

    public var mine: Bool { role == "user" }

    enum CodingKeys: String, CodingKey {
        case id, role, text, createdAt, kind, plan, date, changeId, rows, lead, blocks, title, suggestions, sources, written, withoutAi, invite, lookedAt
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(String.self, forKey: .id)
        role = try c.decode(String.self, forKey: .role)
        text = (try? c.decode(String.self, forKey: .text)) ?? ""
        createdAt = try? c.decode(String.self, forKey: .createdAt)
        kind = try? c.decode(String.self, forKey: .kind)
        plan = try? c.decode(ComposerPlan.self, forKey: .plan)
        date = try? c.decode(String.self, forKey: .date)
        changeId = try? c.decode(String.self, forKey: .changeId)
        rows = try? c.decode([ComposerChangeRow].self, forKey: .rows)
        lead = try? c.decode(String.self, forKey: .lead)
        blocks = (try? c.decode([Lossy<ComposerBlock>].self, forKey: .blocks))?.compactMap(\.value) ?? []
        title = try? c.decode(String.self, forKey: .title)
        suggestions = (try? c.decode([Lossy<ComposerSuggestion>].self, forKey: .suggestions))?.compactMap(\.value) ?? []
        sources = (try? c.decode([Lossy<ComposerLink>].self, forKey: .sources))?.compactMap(\.value) ?? []
        written = try? c.decode(String.self, forKey: .written)
        withoutAi = try? c.decode(String.self, forKey: .withoutAi)
        invite = try? c.decode(Bool.self, forKey: .invite)
        lookedAt = (try? c.decode([String].self, forKey: .lookedAt)) ?? []
    }
}

public struct ComposerSuggestion: Decodable, Equatable, Sendable, Hashable {
    public let label: String
    public let prompt: String?
    public let href: String?
    /// "plans", "saved" or "history": a Composer view to open.
    public let nav: String?
}

public struct ComposerLink: Decodable, Equatable, Sendable, Hashable {
    public let label: String
    public let href: String?
    public let nav: String?
}

public struct ComposerFact: Decodable, Equatable, Sendable, Hashable {
    public let label: String
    public let value: String
    public let known: Bool
}

// MARK: - Plans

public struct ComposerPlan: Decodable, Equatable, Sendable {
    public let date: String
    public let slots: [ComposerSlot]
    public let originLabel: String?

    enum CodingKeys: String, CodingKey { case date, slots, originLabel }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        date = try c.decode(String.self, forKey: .date)
        slots = (try? c.decode([Lossy<ComposerSlot>].self, forKey: .slots))?.compactMap(\.value) ?? []
        originLabel = try? c.decode(String.self, forKey: .originLabel)
    }
}

/// One thing in a plan, or in the catalogue of places and events to add.
public struct ComposerSlot: Decodable, Equatable, Sendable, Identifiable {
    public let id: String
    public let type: String
    public let name: String
    public let subtitle: String?
    public let location: String?
    public let category: String?
    public let veedel: String?
    public let lat: Double?
    public let lng: Double?
    public let startAt: String?
    public let endAt: String?
    public let startTime: String?
    public let endTime: String?
    /// False for an event with no listed end: the end is only the plan's length.
    public let endListed: Bool?
    public let swappable: Bool?
    public let travelMinFromPrevious: Int?
    public let leaveBy: String?
    public let durationLabel: String?
    public let summary: String?
    public let saved: Bool?
    public let available: Bool?
    public let availabilityMessage: String?
    public let isAppointment: Bool?
    /// In the catalogue: "Café", its kind and group ("Cafés"), the day's hours.
    public let label: String?
    public let kind: String?
    public let group: String?
    public let hours: String?

    /// "13:00–14:00", or the start alone when its end isn't listed.
    public var span: String {
        guard let start = startTime, !start.isEmpty else { return "" }
        if let end = endTime, !end.isEmpty, endListed != false { return "\(start)–\(end)" }
        return start
    }
}

public struct ComposerChange: Decodable, Equatable, Sendable, Identifiable {
    public let id: String
    public let date: String
    public let label: String
    public let rows: [ComposerChangeRow]
    public let createdAt: String?
    public let undoneAt: String?
    public let undoable: Bool
}

/// What a change did to one item: moved (old → new time), added, removed, or in place of another.
public struct ComposerChangeRow: Decodable, Equatable, Sendable, Hashable {
    public let kind: String
    public let id: String
    public let title: String
    public let type: String?
    public let from: String?
    public let to: String?
    public let was: String?
}

// MARK: - Cards

/// One card of an answer (App\Composer\Assistant\Ask::message), in the order it came.
public enum ComposerBlock: Decodable, Equatable, Sendable {
    case place(Where)
    case departures(Station)
    case trip(Trip)
    case choices(text: String)
    case events([Event])
    case places([Place])
    case checklist(Checklist)
    case note(text: String)
    case facts([ComposerFact])
    case guidance(query: String)
    case done(Done)
    case undone(id: String, text: String)
    case proposal(Proposal)
    case record(Record)
    case planChanges(PlanChanges)
    case handoff(Handoff)
    case permission(Permission)

    enum Kind: String, CodingKey { case type }
    enum Keys: String, CodingKey { case text, board, events, places, facts, query, id }

    public init(from decoder: Decoder) throws {
        let type = try decoder.container(keyedBy: Kind.self).decode(String.self, forKey: .type)
        let c = try decoder.container(keyedBy: Keys.self)
        switch type {
        case "where": self = .place(try Where(from: decoder))
        case "departures": self = .departures(try c.decode(Station.self, forKey: .board))
        case "trip": self = .trip(try Trip(from: decoder))
        case "choices": self = .choices(text: try c.decode(String.self, forKey: .text))
        case "events": self = .events((try c.decode([Lossy<Event>].self, forKey: .events)).compactMap(\.value))
        case "places": self = .places((try c.decode([Lossy<Place>].self, forKey: .places)).compactMap(\.value))
        case "checklist": self = .checklist(try Checklist(from: decoder))
        case "note": self = .note(text: try c.decode(String.self, forKey: .text))
        case "facts": self = .facts(try c.decode([ComposerFact].self, forKey: .facts))
        case "guidance": self = .guidance(query: try c.decode(String.self, forKey: .query))
        case "done": self = .done(try Done(from: decoder))
        case "undone": self = .undone(id: try c.decode(String.self, forKey: .id), text: try c.decode(String.self, forKey: .text))
        case "proposal": self = .proposal(try Proposal(from: decoder))
        case "record": self = .record(try Record(from: decoder))
        case "plan_changes": self = .planChanges(try PlanChanges(from: decoder))
        case "handoff": self = .handoff(try Handoff(from: decoder))
        case "permission": self = .permission(try Permission(from: decoder))
        default: throw DecodingError.dataCorruptedError(forKey: .type, in: try decoder.container(keyedBy: Kind.self), debugDescription: "Unknown card \(type)")
        }
    }

    /// Where they are, as the answer knew it.
    public struct Where: Decodable, Equatable, Sendable {
        public struct Stop: Decodable, Equatable, Sendable {
            public let name: String
            public let walkMin: Int
            public let lines: [String]
        }

        public let area: String?
        /// browser · chosen · saved_place · area · confirmed · last_shared · home_area · unknown
        public let basis: String?
        public let stop: Stop?

        /// How the location card's label says where they are known to be.
        public var basisWords: String {
            switch basis {
            case "browser", "confirmed": "Where you are"
            case "chosen": "Where you start"
            case "saved_place": "Your saved place"
            case "last_shared": "Where you last were"
            case "area": "The area you chose"
            case "home_area": "Your home Veedel"
            default: "Your area"
            }
        }
    }

    /// A stop's departures (Travel's own board).
    public struct Station: Decodable, Equatable, Sendable {
        public struct Line: Decodable, Equatable, Sendable {
            public let line: String
            public let mode: String
            /// `#RRGGBB`, the line's colour as Travel's board shows it.
            public let color: String?
            public let dirs: [Dir]
        }

        public struct Dir: Decodable, Equatable, Sendable {
            public let id: String
            public let dest: String
            public let via: [String]?
            public let next: Run?
            public let later: [Run]?
            public let cancelled: Bool?
            public let disrupted: Bool?
        }

        public struct Run: Decodable, Equatable, Sendable {
            public let id: String
            public let at: String
            public let time: String
            public let minutes: Int
            public let delay: Int?
            public let cancelled: Bool?

            /// When it leaves; the minutes counted from it stay right as time goes on.
            public var date: Date? { TravelJSON.date(at) }
        }

        public struct Notice: Decodable, Equatable, Sendable {
            public let id: String
            public let title: String
            public let severity: String?
            public let lines: [String]?
        }

        public let name: String
        public let timeBasis: String?
        public let isStale: Bool?
        public let lines: [Line]
        public let notices: [Notice]?
    }

    /// The way to somewhere (App\Composer\Answers\TripAnswers): the journey and where it leads.
    public struct Trip: Decodable, Equatable, Sendable {
        public struct Answer: Decodable, Equatable, Sendable {
            public let journey: Journey
            public let `for`: For
            public let city: String?
            /// "plan" (plan this way) or "trip" (the trip under way).
            public let link: String?
            public let label: String?
            /// "live" or "scheduled".
            public let basis: String?
            /// "How far is it?": the line says it; the way waits, one tap to open.
            public let folded: Bool?
        }

        public struct For: Decodable, Equatable, Sendable {
            public let place: String
            public let label: String?
            public let start: String?
            public let kind: String?
        }

        public struct Major: Decodable, Equatable, Sendable {
            public let title: String
            public let text: String?
            public let todo: String?
            public let lines: [String]?
            public let url: String?
        }

        public let title: String?
        public let text: String
        public let trip: Answer
        public let major: Major?
        public let `private`: Bool?
    }

    public struct Journey: Decodable, Equatable, Sendable {
        public struct Leg: Decodable, Equatable, Sendable {
            public struct Point: Decodable, Equatable, Sendable {
                public let name: String
                public let lat: Double
                public let lng: Double
            }

            public let mode: String
            public let line: String?
            public let headsign: String?
            public let from: Point
            public let to: Point
            public let departAt: String
            public let arriveAt: String
            public let departTime: String
            public let arriveTime: String
            public let durationMin: Int
            public let stops: Int?
            public let polyline: String?
            public let color: String?
            public let delayMin: Int?
            public let cancelled: Bool?
        }

        /// transit · bike · walk
        public let mode: String
        public let departAt: String
        public let arriveAt: String
        public let departTime: String
        public let arriveTime: String
        public let durationMin: Int
        public let transfers: Int
        public let legs: [Leg]
    }

    /// An event as the answer lists it (Events' poster fields).
    public struct Event: Decodable, Equatable, Sendable, Identifiable {
        public struct Venue: Decodable, Equatable, Sendable {
            public let name: String?
            public let veedel: String?
        }

        public let id: Int
        /// Composer's candidate id, which adding it to a plan names.
        public let candidate: String
        public let date: String
        public let plannable: Bool
        public let because: String?
        public let occurrenceStart: String
        public let occurrenceEnd: String?
        public let title: String
        public let timeListed: Bool?
        public let recurrenceText: String?
        public let category: String
        public let priceText: String?
        public let language: String?
        public let venue: Venue
    }

    /// A place as the answer lists it.
    public struct Place: Decodable, Equatable, Sendable, Identifiable {
        public let id: Int
        public let name: String
        public let kind: String
        /// "1.2 km" in a straight line; nil when the start isn't known.
        public let distance: String?
        public let area: String?
        public let street: String?
        /// "Open until 22:00", "Closed now", "Hours not listed".
        public let hours: String?
        public let because: String?
        public let part: String?
        /// Around here's own row for it (where it is, saved, its features), when the answer had one.
        public let row: Row?

        public struct Row: Decodable, Equatable, Sendable {
            public struct Photo: Decodable, Equatable, Sendable { public let url: String }
            public struct Laptop: Decodable, Equatable, Sendable {
                /// People's verdict, by count: "yes", "mixed" or "no".
                public struct People: Decodable, Equatable, Sendable {
                    public let value: String
                    public let yes: Int
                    public let total: Int
                }

                public let people: People?
            }

            public let lat: Double?
            public let lng: Double?
            public let saved: Bool?
            public let icon: String?
            public let label: String?
            public let photo: Photo?
            public let wheelchair: String?
            public let outdoorSeating: String?
            public let laptop: Laptop?

            public init(from decoder: Decoder) throws {
                let c = try decoder.container(keyedBy: CodingKeys.self)
                lat = try? c.decode(Double.self, forKey: .lat)
                lng = try? c.decode(Double.self, forKey: .lng)
                saved = try? c.decode(Bool.self, forKey: .saved)
                icon = try? c.decode(String.self, forKey: .icon)
                label = try? c.decode(String.self, forKey: .label)
                photo = try? c.decode(Photo.self, forKey: .photo)
                wheelchair = try? c.decode(String.self, forKey: .wheelchair)
                outdoorSeating = try? c.decode(String.self, forKey: .outdoorSeating)
                laptop = try? c.decode(Laptop.self, forKey: .laptop)
            }

            enum CodingKeys: String, CodingKey { case lat, lng, saved, icon, label, photo, wheelchair, outdoorSeating, laptop }
        }

        enum CodingKeys: String, CodingKey { case id, name, kind, distance, area, street, hours, because, part, row }

        public init(from decoder: Decoder) throws {
            let c = try decoder.container(keyedBy: CodingKeys.self)
            id = try c.decode(Int.self, forKey: .id)
            name = try c.decode(String.self, forKey: .name)
            kind = (try? c.decode(String.self, forKey: .kind)) ?? ""
            distance = try? c.decode(String.self, forKey: .distance)
            area = try? c.decode(String.self, forKey: .area)
            street = try? c.decode(String.self, forKey: .street)
            hours = try? c.decode(String.self, forKey: .hours)
            because = try? c.decode(String.self, forKey: .because)
            part = try? c.decode(String.self, forKey: .part)
            row = try? c.decode(Row.self, forKey: .row)
        }

        /// A card is its place and, for a large park, its part.
        public var key: String { "\(id)#\(part ?? "")" }

        /// What it is, with what it's known for: "Café · Outdoor seating · Step-free".
        public var features: [String] {
            guard let row else { return [] }
            var list: [String] = []
            if let seating = row.outdoorSeating, seating != "no" { list.append("Outdoor seating") }
            if row.wheelchair == "yes" { list.append("Step-free") } else if row.wheelchair == "limited" { list.append("Limited step-free") }
            return list
        }

        /// People's laptop verdict: "Good for laptop work · 9 of 11".
        public var laptopWords: String? {
            guard let people = row?.laptop?.people else { return nil }
            let words = ["yes": "Good for laptop work", "no": "Not good for laptop work", "mixed": "Mixed reports"][people.value] ?? "Laptop reports"
            return "\(words) · \(people.yes) of \(people.total)"
        }
    }

    public struct Checklist: Decodable, Equatable, Sendable {
        public struct Record: Decodable, Equatable, Sendable {
            public struct Document: Decodable, Equatable, Sendable, Hashable {
                public let label: String
                public let ready: Bool
                public let state: String?
            }

            public let title: String
            public let prepared: Int
            public let total: Int
            public let documents: [Document]
        }

        public let text: String
        public let title: String
        public let record: Record
        public let facts: [ComposerFact]?
        public let `private`: Bool?
    }

    public struct Link: Decodable, Equatable, Sendable {
        public let label: String
        public let href: String?
        public let nav: String?
    }

    /// A change Composer made, with its Undo (24 hours).
    public struct Done: Decodable, Equatable, Sendable {
        public struct Undo: Decodable, Equatable, Sendable {
            public let token: String
            public let until: String
        }

        public struct Row: Decodable, Equatable, Sendable, Hashable {
            public let time: String
            public let what: String
            public let new: Bool
        }

        public let id: String
        /// "everyday" or "paperwork".
        public let area: String?
        public let text: String
        public let sub: String?
        public let undo: Undo?
        public let link: Link?
        public let day: [Row]?
    }

    /// A change Composer proposes; Apply makes it.
    public struct Proposal: Decodable, Equatable, Sendable {
        public let id: String
        public let title: String
        public let from: String?
        public let to: String?
        public let sub: String?
        public let token: String?
        public let area: String?
        public let text: String?
        public let until: String?
        public let factKey: String?
    }

    /// Paperwork's rows, each changeable in place.
    public struct Record: Decodable, Equatable, Sendable {
        public struct Row: Decodable, Equatable, Sendable, Hashable {
            public let key: String
            public let label: String
            public let value: String
            public let note: String?
        }

        public let title: String?
        public let rows: [Row]
        public let link: Link?
    }

    public struct PlanChanges: Decodable, Equatable, Sendable {
        public struct Change: Decodable, Equatable, Sendable, Hashable {
            public let occurrenceKey: String
            public let title: String?
            /// added · removed · due_moved · step_added · step_removed · state_changed
            public let change: String
            public let from: String?
            public let to: String?
        }

        public let changes: [Change]
        public let link: Link?
    }

    /// Where the person does it themselves.
    public struct Handoff: Decodable, Equatable, Sendable {
        public struct Place: Decodable, Equatable, Sendable {
            public let crumb: String
            public let description: String?
            public let actions: [String]
            public let here: String
            public let label: String
            public let href: String?
            public let nav: String?
        }

        public let text: String
        public let place: Place
        public let note: String?
    }

    /// Composer may read their Paperwork record, with Allow.
    public struct Permission: Decodable, Equatable, Sendable {
        public let purpose: String
        public let title: String
        public let sub: String?
        public let disclosure: ComposerDisclosure
    }
}

/// What a processing purpose sends and to whom (GET privacy/processing/{purpose}).
public struct ComposerDisclosure: Decodable, Equatable, Sendable {
    public let purpose: String
    public let available: Bool?
    public let noticeVersion: String
    public let providerVersion: String
    public let processorName: String?
    public let processorPrivacyUrl: String?
    public let notice: [String]?
    public let neverSent: [String]?
}

/// How far the assistant has got with a request (GET composer/progress/{id}).
public struct ComposerProgress: Decodable, Equatable, Sendable {
    /// "reading", "writing" or nil.
    public let step: String?
    public let looked: [String]

    /// "Looking at places…", in the canvas's words.
    public var words: String {
        if let last = looked.last {
            switch last {
            case "listings": return "Looking at what’s on…"
            case "places": return "Looking at places…"
            case "trip", "planner", "your_trip": return "Looking at the trip planner…"
            case "plans", "your_plans": return "Looking at your plans…"
            case "taste": return "Looking at what you like…"
            case "location": return "Looking at where you are…"
            case "paperwork_record", "paperwork": return "Looking at your Paperwork…"
            default: break
            }
        }
        return step == "writing" ? "Putting it together…" : "Reading your request…"
    }
}

// MARK: - Decoding

enum ComposerJSON {
    static let decoder: JSONDecoder = {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        return decoder
    }()
}

/// One element of a list that is left out, not fatal, when it can't be read.
struct Lossy<Value: Decodable & Sendable>: Decodable, Sendable {
    let value: Value?

    init(from decoder: Decoder) throws {
        value = try? Value(from: decoder)
    }
}

public extension ComposerProgress {
    static func decode(_ data: Data) throws -> ComposerProgress {
        try ComposerJSON.decoder.decode(ComposerProgress.self, from: data)
    }
}

public extension ComposerDisclosure {
    static func decode(_ data: Data) throws -> ComposerDisclosure {
        try ComposerJSON.decoder.decode(ComposerDisclosure.self, from: data)
    }
}

/// The go-ahead to send one message on (the web's ai-notice-read): a new request id each time, with
/// the notice and provider versions they were shown. Lasts for that request only.
public struct ComposerProcessing: Sendable {
    public let requestID: String
    public let noticeVersion: String
    public let providerVersion: String

    public init(disclosure: ComposerDisclosure, requestID: String = UUID().uuidString.lowercased()) {
        self.requestID = requestID
        noticeVersion = disclosure.noticeVersion
        providerVersion = disclosure.providerVersion
    }

    public var json: [String: Any] {
        ["consent": true, "request_id": requestID, "notice_version": noticeVersion, "provider_version": providerVersion]
    }
}

public extension JSONDecoder {
    /// Composer's own: snake_case keys.
    static var composer: JSONDecoder { ComposerJSON.decoder }
}

// MARK: - Saved and Starting point

/// A saved event (the web's own list): its title always; its details while it's still on.
public struct ComposerSavedEvent: Decodable, Equatable, Sendable, Identifiable {
    public struct Event: Decodable, Equatable, Sendable {
        public struct Venue: Decodable, Equatable, Sendable {
            public let name: String?
            public let veedel: String?
        }

        public let categoryLabel: String?
        public let venue: Venue?
    }

    public let eventId: Int
    public let occurrenceStart: String
    public let title: String
    public let available: Bool?
    public let event: Event?

    public var id: String { "\(eventId):\(occurrenceStart)" }

    enum CodingKeys: String, CodingKey { case eventId, occurrenceStart, title, available, event }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        eventId = try c.decode(Int.self, forKey: .eventId)
        occurrenceStart = try c.decode(String.self, forKey: .occurrenceStart)
        title = try c.decode(String.self, forKey: .title)
        available = try? c.decode(Bool.self, forKey: .available)
        event = try? c.decode(Event.self, forKey: .event)
    }

    public static func list(_ data: Data) throws -> [ComposerSavedEvent] {
        struct List: Decodable { let data: [Lossy<ComposerSavedEvent>] }
        return try ComposerJSON.decoder.decode(List.self, from: data).data.compactMap(\.value)
    }
}

/// One of their own places, to plan from (Home, Work…).
public struct ComposerOrigin: Decodable, Equatable, Sendable, Identifiable {
    public let id: Int
    public let name: String
    public let category: String?
    public let address: String?

    public static func list(_ data: Data) throws -> [ComposerOrigin] {
        struct List: Decodable { let places: [Lossy<ComposerOrigin>] }
        return try ComposerJSON.decoder.decode(List.self, from: data).places.compactMap(\.value)
    }
}

/// An address or place the search found.
public struct ComposerGeoHit: Decodable, Equatable, Sendable, Hashable {
    public let name: String
    public let street: String?
    public let city: String?
    public let lat: Double
    public let lng: Double

    /// "Venloer Straße 40, Köln", without repeating the name.
    public var address: String? {
        let parts = [street == name ? nil : street, city].compactMap { $0 }.filter { !$0.isEmpty }
        return parts.isEmpty ? nil : parts.joined(separator: ", ")
    }

    public static func list(_ data: Data) throws -> [ComposerGeoHit] {
        try ComposerJSON.decoder.decode([Lossy<ComposerGeoHit>].self, from: data).compactMap(\.value)
    }
}

/// Where Composer plans new days from: a point, or one of their own places, with its name. None:
/// where the app knows they are.
public struct ComposerStart: Equatable, Sendable {
    public let label: String
    public let lat: Double?
    public let lng: Double?
    public let place: Int?

    public init(label: String, lat: Double? = nil, lng: Double? = nil, place: Int? = nil) {
        self.label = label
        self.lat = lat
        self.lng = lng
        self.place = place
    }
}
