import Foundation

/// Paperwork's record, as the server's v2 plan entry gives it (docs/contracts/bureaucracy-v2.md):
/// the same plan the web reads. Decoded leniently: what the app doesn't use is left out, and a
/// field it doesn't know never breaks the page. No date or rule is worked out here; every date
/// and every state comes from the plan.
public struct PaperworkEntry: Decodable, Equatable, Sendable {
    /// "ready", "setup_required", "processing_stopped", "erased" or "record_unavailable".
    public let state: String
    public let stoppedAt: String?
    public let plan: PaperworkPlan?

    public static func decode(_ data: Data) throws -> PaperworkEntry {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        return try decoder.decode(PaperworkEntry.self, from: data)
    }
}

public struct PaperworkPlan: Decodable, Equatable, Sendable {
    public let personId: Int
    public let jurisdiction: String
    public let processes: [PaperworkProcess]
    public let history: [PaperworkProcess]?
    public let timeline: [PaperworkDate]
    public let questions: PaperworkQuestions?
    public let paperwork: PaperworkPapers?
    public let progress: PaperworkProgress?
    public let coverage: PaperworkCoverage?
    public let scopes: [String]?
}

public struct PaperworkProcess: Decodable, Equatable, Sendable, Identifiable {
    public struct State: Decodable, Equatable, Sendable {
        public let workflow: String
    }

    public struct Reason: Decodable, Equatable, Sendable {
        public let label: String?
    }

    public struct Option: Decodable, Equatable, Sendable {
        public let event: String
        public let allowed: Bool?
        public let reason: String?
    }

    /// Null until they start tracking it (a proposed task).
    public let id: Int?
    public let definitionId: String
    public let topic: String?
    public let topicLabel: String?
    public let title: String
    public let version: Int
    public let occurrenceKey: String
    public let reviewToken: String?
    /// "current", "review_required" or "history_only".
    public let guidanceState: String?
    public let state: State
    public let steps: [PaperworkStep]
    public let isClosed: Bool?
    public let closedOn: String?
    public let blockingReason: Reason?
    public let untrackable: Bool?
    public let progressOptions: [Option]?

    /// The plan's own key for it: stable whether tracked or not.
    public var key: String { occurrenceKey }
    public var tracked: Bool { id != nil }
    public var workflow: String { state.workflow }
    public var done: Int { steps.filter { $0.status == "completed" }.count }

    /// To do, waiting or blocked, as the filters group them (the web's workflowTone).
    public var tone: String {
        switch workflow {
        case "submitted", "waiting_authority": "waiting"
        case "action_required", "blocked": "blocked"
        case "completed", "cancelled": "done"
        default: "todo"
        }
    }

    /// "Tracking" once started with nothing reported, as the web says it.
    public var stateWords: String {
        tracked && workflow == "not_started" ? "Tracking" : PaperworkWords.workflow[workflow] ?? workflow
    }
}

public struct PaperworkStep: Decodable, Equatable, Sendable, Identifiable {
    public struct Sources: Decodable, Equatable, Sendable {
        public struct Official: Decodable, Equatable, Sendable {
            public let url: String
            public let purpose: String?
        }

        public struct Legal: Decodable, Equatable, Sendable {
            public let kind: String?
            public let label: String
            public let url: String?
        }

        public let official: [Official]?
        public let legal: [Legal]?
    }

    public struct Counts: Decodable, Equatable, Sendable {
        public let ready: Int
        public let total: Int
    }

    public let id: String
    public let stepId: String
    public let title: String
    public let description: String?
    /// "todo", "blocked", "waiting", "completed" or "cancelled".
    public let status: String
    public let position: Int?
    public let verifiedAt: String?
    public let sources: Sources?
    public let requirements: Counts?
    public let dates: [PaperworkDate]?

    public var done: Bool { status == "completed" }
}

public struct PaperworkDate: Decodable, Equatable, Sendable, Identifiable {
    public struct Place: Decodable, Equatable, Sendable {
        public let label: String?
    }

    public let id: String
    /// "document_expiry", "legal_due", "preparation_target", "authority_follow_up", "appointment",
    /// "submission_recorded".
    public let kind: String
    public let date: String?
    /// "dated", "date_unknown", …
    public let state: String?
    public let overdue: Bool?
    public let conditional: Bool?
    public let neededFact: String?
    public let factKey: String?
    public let occurrenceKey: String?
    public let stepId: String?
    public let document: String?
    public let startsAt: String?
    public let location: Place?

    /// The day it falls on, when it has one.
    public var day: Date? { date.flatMap(PaperworkWords.day) }
}

public struct PaperworkPapers: Decodable, Equatable, Sendable {
    public let requirements: [PaperworkRequirement]?
    public let evidence: [PaperworkEvidence]?
}

public struct PaperworkRequirement: Decodable, Equatable, Sendable, Identifiable {
    public struct Term: Decodable, Equatable, Sendable {
        public let german: String
        public let english: String?
    }

    public let id: String
    public let label: String
    public let note: String?
    public let terms: [Term]?
    /// "required" or "conditional".
    public let applicability: String
    public let semanticHash: String
    public let processId: Int?
    public let occurrenceKey: String?
    /// "missing", "reported_available", "confirmed_for_use" or "needs_reconfirmation".
    public let readiness: String
    public let evidenceId: String?
    public let suggestedEvidenceIds: [String]?

    public var ready: Bool { readiness == "confirmed_for_use" }
    public var required: Bool { applicability == "required" }
}

public struct PaperworkEvidence: Decodable, Equatable, Sendable, Identifiable {
    public struct Details: Decodable, Equatable, Sendable {
        public let label: String
        public let kind: String
        public let reportedAvailable: Bool
        public let expiresOn: String?
        public let requirementRefs: [String]?
    }

    public let id: String
    public let version: Int
    public let details: Details
}

public struct PaperworkQuestions: Decodable, Equatable, Sendable {
    public let sessionId: Int?
    public let question: PaperworkQuestion?
    public let remainingInformationCount: Int?
    public let status: String?
}

public struct PaperworkQuestion: Decodable, Equatable, Sendable {
    public struct Schema: Decodable, Equatable, Sendable {
        /// "enum", "boolean", "date", "integer".
        public let type: String
        public let options: [String]?
        public let dateSemantics: String?
        public let allowsNotApplicable: Bool?
    }

    public let factKey: String
    public let question: String
    public let why: String?
    public let answerSchema: Schema
    /// Only on a question offered in a session: what answering sends back.
    public let id: Int?
    public let token: String?
    public let canSkip: Bool?
    public let canAnswerUnknown: Bool?

    /// Its choices in the registry's order, worded as the web words them.
    public var choices: [(value: PaperworkAnswer, label: String)] {
        switch answerSchema.type {
        case "boolean": [true, false].map { (.bool($0), PaperworkWords.option(factKey, $0 ? "true" : "false")) }
        case "enum": (answerSchema.options ?? []).map { (.text($0), PaperworkWords.option(factKey, $0)) }
        default: []
        }
    }
}

/// A question offered in a session, or why none is.
public struct PaperworkOffer: Decodable, Equatable, Sendable {
    /// "offered", "no_more_questions", "paused", "refresh_required", …
    public let status: String
    public let sessionId: Int
    public let question: PaperworkQuestion?
}

public enum PaperworkAnswer: Equatable, Sendable {
    case text(String)
    case bool(Bool)
    case number(Int)
    case none

    public var json: Any {
        switch self {
        case let .text(value): value
        case let .bool(value): value
        case let .number(value): value
        case .none: NSNull()
        }
    }
}

public struct PaperworkProgress: Decodable, Equatable, Sendable {
    public struct Group: Decodable, Equatable, Sendable {
        public let count: Int
    }

    public let total: Int
    public let completed: Group?
}

public struct PaperworkCoverage: Decodable, Equatable, Sendable {
    public struct Unit: Decodable, Equatable, Sendable {
        public struct URLs: Decodable, Equatable, Sendable {
            public let official: [String]?
        }

        public let definitionId: String?
        public let title: String?
        public let verifiedAt: String?
        public let state: String?
        public let sourceUrls: URLs?
    }

    public let state: String?
    public let units: [Unit]?
}

// MARK: - What the page shows, from the plan

public extension PaperworkPlan {
    /// Open tasks first (to do, then blocked, then waiting), closed ones after.
    var tasks: [PaperworkProcess] {
        let rank = ["todo": 0, "blocked": 1, "waiting": 2, "done": 3]
        return processes.enumerated().sorted { a, b in
            let ra = rank[a.element.tone] ?? 4, rb = rank[b.element.tone] ?? 4
            return ra == rb ? a.offset < b.offset : ra < rb
        }.map(\.element)
    }

    var stepsDone: Int { processes.reduce(0) { $0 + $1.done } }
    var stepsTotal: Int { processes.reduce(0) { $0 + $1.steps.count } }

    /// The documents a task needs, from Paperwork's record: required first.
    func papers(for process: PaperworkProcess) -> [PaperworkRequirement] {
        (paperwork?.requirements ?? []).filter { $0.occurrenceKey == process.occurrenceKey || ($0.processId != nil && $0.processId == process.id) }
            .sorted { ($0.required ? 0 : 1) < ($1.required ? 0 : 1) }
    }

    var papersRequired: [PaperworkRequirement] { (paperwork?.requirements ?? []).filter(\.required) }
    var papersReady: Int { papersRequired.filter(\.ready).count }

    /// What needs them: the open question, and tasks reported blocked or needing their action.
    var needsYou: Int { (questions?.question == nil ? 0 : 1) + processes.filter { $0.tone == "blocked" }.count }

    func process(_ key: String) -> PaperworkProcess? { processes.first { $0.occurrenceKey == key } }

    /// Dates and appointments, soonest first; one with no date yet (it waits for an answer) last.
    var dates: [PaperworkDate] {
        timeline.sorted { a, b in
            switch (a.date, b.date) {
            case let (x?, y?): x < y
            case (_?, nil): true
            default: false
            }
        }
    }

    /// The next dated ones from today, for the tiles.
    func coming(from today: Date, limit: Int = 2) -> [PaperworkDate] {
        let start = Calendar(identifier: .gregorian).startOfDay(for: today)
        return dates.filter { ($0.day ?? .distantPast) >= start }.prefix(limit).map { $0 }
    }

    /// The evidence record for a document, when they've told Paperwork they have one.
    func evidence(for requirement: PaperworkRequirement) -> PaperworkEvidence? {
        let all = paperwork?.evidence ?? []
        if let id = requirement.evidenceId, let found = all.first(where: { $0.id == id }) { return found }
        return all.first { $0.details.requirementRefs?.contains(requirement.id) == true }
            ?? all.first { PaperworkWords.sameLabel($0.details.label, requirement.label) }
    }
}

/// The web's own words for Paperwork (resources/js/components/paperwork/format.ts and facts.ts):
/// UI copy only. Applicability, order and what an answer changes stay with the server.
public enum PaperworkWords {
    public static let workflow = [
        "not_started": "Not started", "preparing": "Preparing", "blocked": "Blocked", "action_required": "Needs your action",
        "submitted": "Submitted", "waiting_authority": "Waiting for the office", "completed": "Completion recorded",
        "cancelled": "Cancelled", "untracked": "Not started",
    ]

    public static let stepStatus = ["todo": "To do", "blocked": "Blocked", "waiting": "Waiting", "completed": "Done", "cancelled": "Cancelled"]

    public static let readiness = [
        "missing": "Still to get", "reported_available": "You have it · not checked yet",
        "confirmed_for_use": "Checked and ready", "needs_reconfirmation": "Check again",
    ]

    /// What each "Update progress" choice says.
    public static let change = [
        "preparation_started": "Back to preparing", "blocked_reported": "Something is holding me up",
        "submission_recorded": "I’ve submitted it", "waiting_reported": "Waiting for the office",
        "action_required_reported": "The office needs something from me", "completion_reported": "Completed",
        "cancellation_reported": "Cancelled", "process_reopened": "Reopen this task",
    ]

    public static let kind = [
        "document_expiry": "Document expiry", "legal_due": "Legal deadline", "preparation_target": "Target date",
        "authority_follow_up": "Follow-up", "appointment": "Appointment", "submission_recorded": "Submission recorded",
    ]

    /// What a fact is called when a date waits for it.
    public static let fact = [
        "moved_in_at": "your move-in date", "visa_expires_at": "your visa expiry", "arrival_date": "your arrival date",
        "residence_title_expires_at": "your residence permit expiry",
    ]

    public static let coverage = [
        "complete": "Reviewed criteria", "partial": "Preparation guidance only", "not_covered": "Outside its review window",
        "withdrawn": "Withdrawn", "unconfirmed": "Not confirmed right now",
    ]

    static let residenceTitles = [
        "national_d_visa": "National D visa", "standard_work_permit": "Work permit", "blue_card_pending": "Blue Card applied for",
        "blue_card": "EU Blue Card", "student_permit": "Student permit", "chancenkarte": "Chancenkarte",
        "self_employment_permit": "Self-employment permit", "family_reunification": "Family reunification",
        "settlement_permit_9": "Settlement permit · §9", "settlement_permit_18c": "Settlement permit · §18c",
        "settlement_permit_unknown": "Settlement permit · type unknown", "other": "Another title", "none": "No residence title yet",
    ]

    static let yesNoUnsure = ["yes": "Yes", "no": "No", "unsure": "I’m not sure yet"]

    /// Each answer's name and its choices (facts.ts `fields`).
    public static let fields: [String: (label: String, options: [String: String])] = [
        "citizenship_group": ("Citizenship group", ["eu": "EU", "non_eu": "Non-EU"]),
        "purpose": ("Purpose of stay", ["employment": "Employment", "study": "Study", "freelance": "Freelance", "family": "Family",
                                        "digital_nomad": "Digital nomad", "other": "Something else"]),
        "arrival_planned": ("Your move", ["true": "Yes, still planning", "false": "No, I have arrived"]),
        "entry_mode": ("How you entered", ["d_visa": "With a national D visa", "visa_free": "Without a visa", "has_permit": "With a residence permit"]),
        "current_residence_title": ("Current visa or permit", residenceTitles),
        "residence_card_expires_at": ("Residence card expiry", [:]),
        "case_goal": ("What you want to do next", ["blue_card": "Get an EU Blue Card", "family_reunification_permit": "Join my family",
                                                   "renew_current_title": "Renew my current permit", "settlement_permit": "Get a settlement permit",
                                                   "understand_options": "Understand my options"]),
        "permit_track": ("Work permit route", ["standard": "Standard work permit", "blue_card": "EU Blue Card", "chancenkarte": "Opportunity Card (Chancenkarte)"]),
        "housing_provider_confirmation": ("Housing provider’s confirmation", ["available": "I have it", "requested": "I asked for it", "not_available": "I don’t have it"]),
        "registration_status": ("Address registration", ["registered": "Yes, registered", "not_registered": "Not yet"]),
        "health_coverage_confirmed": ("Health insurance", ["true": "Confirmed with my insurer", "false": "Not yet"]),
        "tax_id_available": ("Tax ID", ["true": "I have it", "false": "Not yet"]),
        "bank_account_help_needed": ("Help with a bank account", ["true": "Yes, please", "false": "No, thanks"]),
        "german_level": ("German level", ["none": "None yet", "a1": "A1", "a2": "A2", "b1": "B1", "b2": "B2", "c1": "C1", "c2": "C2"]),
        "blue_card_qualifying_months": ("Months of Blue Card employment", [:]),
        "livelihood_secured": ("Livelihood secured", yesNoUnsure),
        "housing_sufficient": ("Enough living space", yesNoUnsure),
        "legal_social_knowledge_proved": ("Knowledge of life in Germany", yesNoUnsure),
        "family_residence_permit_held_since": ("Family permit held since", [:]),
        "has_children": ("Children with you in Germany", ["true": "Yes", "false": "No"]),
        "marital_household_continues": ("Living together as a married household", ["true": "Yes", "false": "No"]),
        "sponsor": ("Family member you’re joining", ["german": "German citizen", "eu_citizen": "EU citizen", "non_eu": "Non-EU citizen"]),
        "sponsor_current_title": ("Your spouse’s residence status", residenceTitles),
        "sponsor_title_at_entry": ("Your sponsor’s status when you joined", residenceTitles),
    ]

    public static func label(_ key: String) -> String {
        fields[key]?.label ?? key.replacingOccurrences(of: "_", with: " ").capitalizedFirst
    }

    public static func option(_ key: String, _ value: String) -> String {
        if let own = fields[key]?.options[value] { return own }
        if value == "true" { return "Yes" }
        if value == "false" { return "No" }
        return value.replacingOccurrences(of: "_", with: " ").capitalizedFirst
    }

    /// "29 Nov 2026".
    public static func date(_ text: String?) -> String {
        guard let text, let day = day(text) else { return "Date unknown" }
        return day.formatted(.dateTime.day().month(.abbreviated).year().locale(Locale(identifier: "en_GB")))
    }

    static func day(_ text: String) -> Date? {
        guard text.count >= 10 else { return nil }
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TimeZone(identifier: "Europe/Berlin")!
        let parts = text.prefix(10).split(separator: "-").compactMap { Int($0) }
        guard parts.count == 3 else { return nil }
        return calendar.date(from: DateComponents(year: parts[0], month: parts[1], day: parts[2], hour: 12))
    }

    /// Catalogue text wraps at a fixed width: blank lines are paragraphs, "- " lines are list items.
    public static func paragraphs(_ text: String) -> [String] {
        text.components(separatedBy: "\n\n")
            .flatMap { block -> [String] in
                var out: [String] = []
                for line in block.components(separatedBy: "\n") {
                    let trimmed = line.trimmingCharacters(in: .whitespaces)
                    if trimmed.hasPrefix("- ") { out.append("• " + trimmed.dropFirst(2)) } else if trimmed.isEmpty { continue } else if let last = out.last, !last.hasPrefix("• ") {
                        out[out.count - 1] = last + " " + trimmed
                    } else { out.append(trimmed) }
                }
                return out
            }
    }

    /// One record per document name, as the web matches them.
    public static func sameLabel(_ a: String, _ b: String) -> Bool {
        a.trimmingCharacters(in: .whitespaces).lowercased() == b.trimmingCharacters(in: .whitespaces).lowercased()
    }
}

extension String {
    var capitalizedFirst: String { prefix(1).uppercased() + dropFirst() }
}
