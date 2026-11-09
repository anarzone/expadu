import Foundation

/// Today, as the server reads it: the weather, the day's plans and when to leave for the next,
/// the notes that need the person, ideas and places for the time they have, Paperwork's next
/// step, what they saved, and what's coming up.
public struct TodayPage: Equatable, Sendable {
    public struct Weather: Equatable, Sendable {
        public struct Hour: Equatable, Sendable {
            public let hour: String
            public let temperature: Int?
            public let icon: String?
            /// Chance of rain, 0–100.
            public let rain: Int?
            public let condition: String?

            public init(hour: String, temperature: Int?, icon: String?, rain: Int? = nil, condition: String? = nil) {
                self.hour = hour
                self.temperature = temperature
                self.icon = icon
                self.rain = rain
                self.condition = condition
            }
        }

        /// A day ahead: its low and high, the afternoon's sky, the most rain likely.
        public struct Day: Equatable, Sendable, Identifiable {
            public let date: String
            public let low: Int?
            public let high: Int?
            public let icon: String?
            public let rain: Int?
            public var id: String { date }

            public init(date: String, low: Int?, high: Int?, icon: String?, rain: Int?) {
                self.date = date
                self.low = low
                self.high = high
                self.icon = icon
                self.rain = rain
            }
        }

        public let temperature: Int
        public let condition: String
        public let icon: String
        /// "Dry until 21:00", or when the rain comes and goes.
        public let summary: String?
        /// "Good for cycling", "Poor — rain from 15:00".
        public let cycling: String?
        public let feelsLike: Int?
        public let high: Int?
        public let low: Int?
        /// The next twelve hours from this one.
        public let hours: [Hour]
        /// Today hour by hour, for each plan's hour.
        public let today: [Hour]
        public let days: [Day]

        public init(temperature: Int, condition: String, icon: String, summary: String?, cycling: String?, feelsLike: Int? = nil, high: Int? = nil, low: Int? = nil,
                    hours: [Hour], today: [Hour] = [], days: [Day] = []) {
            self.temperature = temperature
            self.condition = condition
            self.icon = icon
            self.summary = summary
            self.cycling = cycling
            self.feelsLike = feelsLike
            self.high = high
            self.low = low
            self.hours = hours
            self.today = today
            self.days = days
        }

        /// "High 14° · low 8° · feels like 11°", from what is known.
        public var range: String? {
            let parts = [high.map { "High \($0)°" }, low.map { "low \($0)°" }, feelsLike.map { "feels like \($0)°" }].compactMap { $0 }
            return parts.isEmpty ? nil : parts.joined(separator: " · ")
        }

        /// Today's hour that holds a plan's start ("18:30" → the 18:00 hour).
        public func at(_ time: String) -> Hour? {
            today.first { $0.hour.prefix(2) == time.prefix(2) }
        }
    }

    public struct Plan: Equatable, Sendable, Identifiable {
        /// What to bring, for an appointment from Paperwork: "1 of 5 documents ready".
        public struct Bring: Equatable, Sendable {
            public let title: String?
            public let ready: Int
            public let total: Int
            public let process: String?

            public init(title: String?, ready: Int, total: Int, process: String?) {
                self.title = title
                self.ready = ready
                self.total = total
                self.process = process
            }
        }

        public let id: String
        public let title: String
        public let subtitle: String?
        public let start: String?
        public let end: String?
        public let place: String?
        /// "upcoming", "ongoing" or "past".
        public let state: String
        public let completed: Bool
        /// Plans the person made; Paperwork's appointments can't be edited here.
        public let editable: Bool
        public let bring: Bring?

        public init(id: String, title: String, subtitle: String?, start: String?, end: String?, place: String?, state: String, completed: Bool, editable: Bool = false, bring: Bring? = nil) {
            self.id = id
            self.title = title
            self.subtitle = subtitle
            self.start = start
            self.end = end
            self.place = place
            self.state = state
            self.completed = completed
            self.editable = editable
            self.bring = bring
        }

        public var isPast: Bool { completed || state == "past" || state == "elapsed" }

        /// "Stadtgarten, Venloer Str." reads "Stadtgarten" where room is short.
        public var shortPlace: String? { place?.split(separator: ",").first.map { String($0).trimmingCharacters(in: .whitespaces) } }
    }

    /// The way between two plans, as the web's Your day words it.
    public struct Link: Equatable, Sendable {
        public enum Tone: Equatable, Sendable { case ok, tight, conflict, unknown }

        public let from: String
        public let to: String
        public let reason: String?
        public let basis: String?
        public let travelMinutes: Int?
        public let gapMinutes: Int?

        public init(from: String, to: String, reason: String?, basis: String?, travelMinutes: Int?, gapMinutes: Int?) {
            self.from = from
            self.to = to
            self.reason = reason
            self.basis = basis
            self.travelMinutes = travelMinutes
            self.gapMinutes = gapMinutes
        }

        public var words: (line: String, tone: Tone) {
            let spare = gapMinutes.flatMap { gap in travelMinutes.map { gap - $0 } } ?? 0
            let trip = basis == "walk" ? "About \(travelMinutes ?? 0) min on foot" : "About \(travelMinutes ?? 0) min"
            switch reason {
            case "same_place": return ("Same place", .ok)
            case "walk_fits", "transit_fits": return ("\(trip) · \(spare) min to spare", .ok)
            case "only_tight": return ("\(trip) · only \(spare) min to spare", .tight)
            case "overlap": return ("These plans overlap", .conflict)
            case "no_connection_in_time":
                guard let travelMinutes, let gapMinutes else { return ("No connection gets you there in time", .conflict) }
                return ("Needs about \(travelMinutes) min, you have \(max(0, gapMinutes))", .conflict)
            case "end_unknown": return ("End time not recorded · travel not checked", .unknown)
            case "location_unknown": return ("No exact place to route from · travel not checked", .unknown)
            case "routing_unavailable": return ("Travel times unavailable right now", .unknown)
            default:
                if let gapMinutes, gapMinutes > 0 { return ("\(gapMinutes) min between plans · travel time not checked", .unknown) }
                return ("Travel not checked", .unknown)
            }
        }
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
        public let walkMinutes: Int?
        public let transfers: Int?
        public let live: Bool
        /// The way the card shows (0), then Other ways.
        public var ways: [Way] = []
        /// The plan it's for.
        public var planId: String?

        public init(to: String, start: String?, status: String, leaveTime: String?, leaveInMinutes: Int?, arriveTime: String?, spareMinutes: Int?, line: String?, color: String?, departs: String?, from: String?, walkMinutes: Int? = nil, transfers: Int? = nil, live: Bool) {
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
            self.walkMinutes = walkMinutes
            self.transfers = transfers
            self.live = live
        }

        /// Only a worked-out way gets the card; otherwise the plan stands alone on the line.
        public var isKnown: Bool { leaveTime != nil && arriveTime != nil }

        /// "4 min walk to the platform · no change".
        public var note: String? {
            guard line != nil else { return nil }
            let walk = walkMinutes.map { "\($0) min walk to the platform" }
            let change: String? = switch transfers {
            case 0: "no change"
            case 1: "1 change"
            case let .some(count): "\(count) changes"
            case nil: nil
            }
            let parts = [walk, change].compactMap { $0 }
            return parts.isEmpty ? nil : parts.joined(separator: " · ")
        }
    }

    /// One way to the plan, for Other ways.
    public struct Way: Equatable, Sendable, Identifiable {
        public struct Line: Equatable, Sendable {
            public let line: String
            public let color: String

            public init(line: String, color: String) {
                self.line = line
                self.color = color
            }
        }

        /// Its place among the ways Today sent: what the server takes it by.
        public let id: Int
        public let leaveTime: String
        public let arriveTime: String
        public let spareMinutes: Int
        public let status: String
        public let mode: String
        public let lines: [Line]
        public let departs: String?
        public let from: String?
        public let walkMinutes: Int
        public let transfers: Int
        public let minutes: Int
        public let inPlan: Bool
        public let live: Bool
        /// Journey details on its ticket.
        public var legs: [Trip.Leg] = []

        public init(id: Int, leaveTime: String, arriveTime: String, spareMinutes: Int, status: String, mode: String, lines: [Line], departs: String?, from: String?,
                    walkMinutes: Int, transfers: Int, minutes: Int, inPlan: Bool, live: Bool) {
            self.id = id
            self.leaveTime = leaveTime
            self.arriveTime = arriveTime
            self.spareMinutes = spareMinutes
            self.status = status
            self.mode = mode
            self.lines = lines
            self.departs = departs
            self.from = from
            self.walkMinutes = walkMinutes
            self.transfers = transfers
            self.minutes = minutes
            self.inPlan = inPlan
            self.live = live
        }

        /// "12 min spare", "Only 2 min spare", "8 min late".
        public var spareWords: String {
            spareMinutes < 0 ? "\(-spareMinutes) min late" : spareMinutes <= 2 ? "Only \(spareMinutes) min spare" : "\(spareMinutes) min spare"
        }

        /// "4 min walk to the platform · no change", "On foot all the way".
        public var note: String {
            if lines.isEmpty { return mode == "bike" ? "By bike all the way" : "On foot all the way · \(minutes) min" }
            let change = transfers == 0 ? "no change" : transfers == 1 ? "1 change" : "\(transfers) changes"
            return "\(walkMinutes) min walking · \(change)"
        }
    }

    /// The trip they're on: each leg, so the card can say where they are by the clock.
    public struct Trip: Equatable, Sendable {
        public struct Leg: Equatable, Sendable {
            public let mode: String
            public let line: String?
            public let color: String?
            public let headsign: String?
            public let from: String
            public let to: String
            public let departAt: Date
            public let arriveAt: Date
            public let departTime: String
            public let arriveTime: String
            public let minutes: Int
            public let stops: Int?
            /// The way it goes, encoded (MOTIS, precision 7): `Polyline.decode`.
            public let polyline: String?
            /// On foot: our router's turns.
            public var turns: [Turn]

            public init(mode: String, line: String?, color: String?, headsign: String?, from: String, to: String, departAt: Date, arriveAt: Date,
                        departTime: String, arriveTime: String, minutes: Int, stops: Int?, polyline: String? = nil, turns: [Turn] = []) {
                self.polyline = polyline
                self.turns = turns
                self.mode = mode
                self.line = line
                self.color = color
                self.headsign = headsign
                self.from = from
                self.to = to
                self.departAt = departAt
                self.arriveAt = arriveAt
                self.departTime = departTime
                self.arriveTime = arriveTime
                self.minutes = minutes
                self.stops = stops
            }

            public var rides: Bool { mode != "walk" && mode != "bike" }

            /// Where the leg ends, from its route line.
            public var end: Point? {
                polyline.flatMap { Polyline.decode($0).last }.map { Point(latitude: $0.latitude, longitude: $0.longitude) }
            }

            /// "Tram 1", "Bus 146", "S-Bahn S6".
            public var name: String {
                let kind: String = switch mode {
                case "tram": "Tram"
                case "bus": "Bus"
                case "rail", "regional", "train": "Train"
                case "suburban": "S-Bahn"
                case "subway": "U-Bahn"
                case "bike": "Bike"
                default: "Walk"
                }
                return [kind, line].compactMap { $0 }.joined(separator: " ")
            }
        }

        public let to: String
        public let arriveTime: String?
        public let startTime: String?
        public let legs: [Leg]

        public init(to: String, arriveTime: String?, startTime: String?, legs: [Leg]) {
            self.to = to
            self.arriveTime = arriveTime
            self.startTime = startTime
            self.legs = legs
        }

        /// The canvas's steps for this trip: walk, ride, get off, you're there.
        public var steps: [Step] { Step.of(legs, to: to, start: startTime) }

        /// The live card at this moment: the step they're on by the clock, or the one tapped.
        public func live(at date: Date, picked: Int? = nil, guided: Int? = nil) -> Live {
            let steps = steps
            // Started well before leaving (from an event's Getting there): the card waits for the
            // leave time instead of saying "walk now" hours early.
            if picked == nil, (guided ?? 0) == 0, let first = steps.first, first.at.timeIntervalSince(date) > 60 {
                let ride = steps.first { $0.kind == .ride }
                return Live(index: 0, current: 0, label: "Leave at \(first.time)", step: first,
                            big: Self.span(first.at.timeIntervalSince(date)),
                            bigNote: ride.map { "\($0.name) at \($0.time)" } ?? "there at \(steps[steps.count - 1].time)", waiting: true)
            }
            // On foot the phone says where they are (walking pace varies); on a ride the timetable
            // does, unless the phone is already further on. Without the phone, the clock.
            let clock = Self.current(steps, at: date)
            let current = guided.map { min($0, steps.count - 1) }.map { steps[$0].kind == .walk ? $0 : max($0, clock) } ?? clock
            let k = min(picked ?? current, steps.count - 1)
            let step = steps[k]
            let last = k == steps.count - 1
            let next = last ? nil : steps[k + 1]
            let from = k == current ? max(date, step.at) : step.at
            let minutes = next.map { max(1, Int(($0.at.timeIntervalSince(from) / 60).rounded(.up))) } ?? 0
            let note: String
            if last {
                let early = startTime.flatMap { start in step.time.isEmpty ? nil : TodayPage.minutes(from: step.time, to: start) }
                note = early.map { $0 > 0 ? "\($0) min early" : "Enjoy it" } ?? "Enjoy it"
            } else if step.kind == .walk, let next, next.kind == .ride {
                note = "\(next.name) at \(next.time)"
            } else if step.kind == .ride, let next {
                note = "off at \(next.time)"
            } else {
                note = "there at \(steps[steps.count - 1].time)"
            }
            return Live(index: k, current: current,
                        label: last ? "Arrived" : k == 0 ? "Now" : "Step \(k + 1) of \(steps.count)",
                        step: step, big: last ? "There" : "\(minutes) min", bigNote: note)
        }

        /// "28 min", "2 h 28 min".
        static func span(_ seconds: TimeInterval) -> String {
            let minutes = max(1, Int((seconds / 60).rounded(.up)))
            return minutes < 60 ? "\(minutes) min" : minutes % 60 == 0 ? "\(minutes / 60) h" : "\(minutes / 60) h \(minutes % 60) min"
        }

        /// The last step whose time has come; the first before any has.
        static func current(_ steps: [Step], at date: Date) -> Int {
            steps.lastIndex { $0.at <= date } ?? 0
        }
    }

    /// One step of a way, as the canvas lists it (Steps, Journey details) and the bars count it.
    public struct Step: Equatable, Sendable {
        public enum Kind: Equatable, Sendable { case walk, ride, getOff, there }

        public let kind: Kind
        public let title: String
        public let sub: String
        public let at: Date
        public let time: String
        /// `#RRGGBB`: the line's, or nil for walking (the ink).
        public let color: String?
        /// "Tram 1", for "Tram 1 at 23:58".
        public let name: String
        /// The leg this step is on (the walk after the last ride counts as getting off).
        public var leg: Int?

        /// Walk to the stop, each ride, get off (with the walk on), and you're there.
        public static func of(_ legs: [Trip.Leg], to: String, start: String?) -> [Step] {
            var steps: [Step] = []
            for (index, leg) in legs.enumerated() {
                let last = index == legs.count - 1
                let before = index > 0 ? legs[index - 1] : nil
                if leg.rides {
                    let sub = ["From \(leg.from)", leg.headsign.map { "towards \($0)" }, leg.stops.map { "\($0) stops" }].compactMap { $0 }.joined(separator: " · ")
                    steps.append(Step(kind: .ride, title: "\(leg.name) to \(leg.to)", sub: sub, at: leg.departAt, time: leg.departTime, color: leg.color, name: leg.name, leg: index))
                    if last {
                        steps.append(Step(kind: .getOff, title: "Get off at \(leg.to)", sub: "Then you’re there", at: leg.arriveAt, time: leg.arriveTime, color: leg.color, name: leg.name))
                    }
                } else if last, let before, before.rides {
                    steps.append(Step(kind: .getOff, title: "Get off at \(before.to)", sub: "\(leg.minutes) min walk to \(to)", at: before.arriveAt, time: before.arriveTime, color: before.color, name: before.name,
                                      leg: index))
                } else {
                    let place = last || leg.to.isEmpty ? to : leg.to
                    steps.append(Step(kind: .walk, title: leg.mode == "bike" ? "Cycle to \(place)" : "Walk to \(place)", sub: "\(leg.minutes) min", at: leg.departAt, time: leg.departTime, color: nil, name: leg.name,
                                      leg: index))
                }
            }
            if let end = legs.last {
                steps.append(Step(kind: .there, title: "You’re there", sub: start.map { "Starts at \($0)" } ?? "", at: end.arriveAt, time: end.arriveTime, color: "#E6421F", name: ""))
            }
            return steps
        }
    }

    /// One turn of a walk, as our router (MOTIS) gives it.
    public struct Turn: Equatable, Sendable {
        public let direction: String
        public let street: String
        public let metres: Int
        /// The stretch this turn covers, encoded (precision 7).
        public let polyline: String?
        /// A change of floor: a lift or stairs in a station.
        public let levelChange: Bool

        public init(direction: String, street: String, metres: Int, polyline: String?, levelChange: Bool) {
            self.direction = direction
            self.street = street
            self.metres = metres
            self.polyline = polyline
            self.levelChange = levelChange
        }

        /// Where the turn starts: the first point of its stretch.
        public var start: Point? {
            polyline.flatMap { Polyline.decode($0).first }.map { Point(latitude: $0.latitude, longitude: $0.longitude) }
        }

        /// "Turn left onto Breite Straße", "Take the lift", "Continue".
        public var words: String {
            if levelChange { return "Change level: lift or stairs" }
            let onto = street.isEmpty ? "" : " onto \(street)"
            switch direction {
            case "depart": return street.isEmpty ? "Start walking" : "Start on \(street)"
            case "left", "hard_left": return "Turn left\(onto)"
            case "right", "hard_right": return "Turn right\(onto)"
            case "slightly_left": return "Bear left\(onto)"
            case "slightly_right": return "Bear right\(onto)"
            case "uturn_left", "uturn_right": return "Turn around\(onto)"
            case "circle_clockwise", "circle_counterclockwise": return "At the roundabout, go\(onto.isEmpty ? " on" : onto)"
            case "elevator": return "Take the lift"
            case "stairs": return "Take the stairs"
            default: return street.isEmpty ? "Continue" : "Continue on \(street)"
            }
        }

        /// The SF Symbol for the turn's arrow.
        public var symbol: String {
            if levelChange || direction == "elevator" { return "arrow.up.arrow.down" }
            switch direction {
            case "left", "hard_left": return "arrow.turn.up.left"
            case "right", "hard_right": return "arrow.turn.up.right"
            case "slightly_left": return "arrow.up.left"
            case "slightly_right": return "arrow.up.right"
            case "uturn_left", "uturn_right": return "arrow.uturn.down"
            case "circle_clockwise", "circle_counterclockwise": return "arrow.triangle.turn.up.right.circle"
            case "stairs": return "figure.stairs"
            default: return "arrow.up"
            }
        }
    }

    /// A place on the map.
    public struct Point: Equatable, Sendable {
        public let latitude: Double
        public let longitude: Double

        public init(latitude: Double, longitude: Double) {
            self.latitude = latitude
            self.longitude = longitude
        }
    }

    /// The live card's step: "Now", "Step 2 of 4" or "Arrived", how long it has left, and what comes next.
    public struct Live: Equatable, Sendable {
        public let index: Int
        /// The step they're on by the clock (the bars before it are done).
        public let current: Int
        public let label: String
        public let step: Step
        public let big: String
        public let bigNote: String
        /// Started early: nothing to do until the leave time, so no step is "now" yet.
        public var waiting = false
    }

    /// Paperwork's most urgent date.
    public struct Deadline: Equatable, Sendable {
        public let kind: String
        public let document: String?
        public let title: String
        public let date: String
        public let daysRemaining: Int
        public let next: String?
        public let ready: Int?
        public let total: Int?
        public let process: String?

        public init(kind: String, document: String?, title: String, date: String, daysRemaining: Int, next: String?, ready: Int?, total: Int?, process: String?) {
            self.kind = kind
            self.document = document
            self.title = title
            self.date = date
            self.daysRemaining = daysRemaining
            self.next = next
            self.ready = ready
            self.total = total
            self.process = process
        }

        /// "Your visa expires tomorrow", "Legal deadline on 13 Nov 2026".
        public var headline: String {
            let when: String = switch daysRemaining {
            case 0: "today"
            case 1: "tomorrow"
            default: "on \(TodayPage.day(date, format: "d MMM yyyy") ?? date)"
            }
            if kind == "document_expiry" {
                let name: String = switch document {
                case "visa": "visa"
                case "residence_title": "residence permit"
                case "passport": "passport"
                default: "document"
                }
                return "Your \(name) \(daysRemaining < 0 ? "expired" : "expires") \(when)"
            }
            let label: String = switch kind {
            case "legal_due": "Legal deadline"
            case "preparation_target": "Target date"
            case "authority_follow_up": "Follow-up"
            default: "Deadline"
            }
            return "\(label) \(when)"
        }
    }

    public struct Need: Equatable, Sendable, Identifiable {
        public let id: String
        public let kind: String
        public let severity: String
        public let headline: String
        public let detail: String?
        /// A disruption or a delay: the line, in KVB's colour, and the bell's card for it.
        public let line: String?
        public let color: String?
        public let alert: Int?

        public init(id: String, kind: String, severity: String, headline: String, detail: String?, line: String? = nil, color: String? = nil, alert: Int? = nil) {
            self.id = id
            self.kind = kind
            self.severity = severity
            self.headline = headline
            self.detail = detail
            self.line = line
            self.color = color
            self.alert = alert
        }

        /// A line running late or disrupted, as Cologne today's line row shows it.
        public var isLine: Bool { line != nil && (kind == "disruption" || kind == "delay") }
    }

    public struct Idea: Equatable, Sendable, Identifiable {
        public let id: Int
        /// What Add to a plan sends.
        public let candidate: String
        public var saved: Bool
        /// Why their own order put it here; nil in the usual order.
        public let why: String?
        /// Which of their leanings it matches (Why am I seeing this?).
        public let reasons: [String]
        public let title: String
        public let category: String
        public let emoji: String?
        public let startsAt: Date?
        public let endsAt: Date?
        public let timed: Bool
        /// The series ("Daily until Thu 5 Nov") for a listing with no time.
        public let untimed: String?
        public let tomorrow: Bool
        public let price: String?
        public let place: String?
        public let area: String?
        /// "en", "de", "mixed" or "none_needed", only when the listing's own text says so.
        public let language: String?

        public init(id: Int, candidate: String = "", saved: Bool = false, why: String? = nil, reasons: [String] = [], title: String, category: String, emoji: String?, startsAt: Date?, endsAt: Date?, timed: Bool, untimed: String?, tomorrow: Bool, price: String?, place: String?, area: String?, language: String? = nil) {
            self.language = language
            self.id = id
            self.candidate = candidate
            self.saved = saved
            self.why = why
            self.reasons = reasons
            self.title = title
            self.category = category
            self.emoji = emoji
            self.startsAt = startsAt
            self.endsAt = endsAt
            self.timed = timed
            self.untimed = untimed
            self.tomorrow = tomorrow
            self.price = price
            self.place = place
            self.area = area
        }

        /// The poster's corner: its start, "Now" once it's begun, or "All day".
        public func posterLabel(at now: Date) -> String {
            guard timed, let startsAt else { return "All day" }
            if startsAt <= now, (endsAt ?? startsAt.addingTimeInterval(3600)) > now { return "Now" }
            return TodayPage.clock(startsAt)
        }

        /// Today's row, beside a poster that already shows the start: how long, where, and the
        /// language when it's known. "Until 21:00 · Kreuzblume · In German".
        public func rowLine(at now: Date) -> String {
            var when: String?
            if !timed || startsAt == nil {
                when = untimed ?? "All day"
            } else if let startsAt, startsAt <= now {
                when = endsAt.map { "On now · until \(TodayPage.clock($0))" } ?? "On now"
            } else {
                let until = endsAt.map { "until \(TodayPage.clock($0))" }
                when = tomorrow ? (["Tomorrow", until].compactMap { $0 }.joined(separator: " ")) : until.map { $0.prefix(1).uppercased() + $0.dropFirst() }
            }
            let words: String? = switch language {
            case "en": "In English"
            case "de": "In German"
            case "mixed": "English and German"
            case "none_needed": "No German needed"
            default: nil
            }
            return [when, place ?? area, words].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: " · ")
        }

        /// "Tonight 19:00–23:00 · Café Central", "Daily until Thu 5 Nov · Stadtgebiet".
        public func whenLine(at now: Date) -> String {
            let when: String
            if !timed || startsAt == nil {
                when = untimed ?? "All day"
            } else if let startsAt, startsAt <= now {
                when = endsAt.map { "On now · until \(TodayPage.clock($0))" } ?? "On now"
            } else if let startsAt {
                let range = endsAt.map { "\(TodayPage.clock(startsAt))–\(TodayPage.clock($0))" } ?? TodayPage.clock(startsAt)
                when = tomorrow ? "Tomorrow \(range)" : (TodayPage.hour(startsAt) >= 18 ? "Tonight \(range)" : "Today \(range)")
            } else {
                when = ""
            }
            return [when, place ?? area].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: " · ")
        }
    }

    /// The free time before the next plan, or near home: everyday places open then.
    public struct Free: Equatable, Sendable {
        public struct Place: Equatable, Sendable, Identifiable {
            public let id: Int
            public let candidate: String
            public let name: String
            public let kind: String
            public let label: String
            public let km: Double?
            public let openUntil: String?
            public let latitude: Double?
            public let longitude: Double?

            public init(id: Int, candidate: String = "", name: String, kind: String, label: String, km: Double?, openUntil: String?, latitude: Double? = nil, longitude: Double? = nil) {
                self.id = id
                self.candidate = candidate
                self.latitude = latitude
                self.longitude = longitude
                self.name = name
                self.kind = kind
                self.label = label
                self.km = km
                self.openUntil = openUntil
            }

            /// "Bakery · 50 m · open until 18:30".
            public var line: String {
                let distance = km.map { $0 < 1 ? "\(Int(($0 * 100).rounded()) * 10) m" : String(format: "%.1f km", $0) }
                return [label, distance, openUntil.map { "open until \($0)" }].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: " · ")
            }
        }

        public let anchorTitle: String?
        public let anchorArea: String?
        public let anchorEnd: String?
        public let near: String?
        public let at: String
        public let places: [Place]

        public init(anchorTitle: String?, anchorArea: String?, anchorEnd: String?, near: String?, at: String, places: [Place]) {
            self.anchorTitle = anchorTitle
            self.anchorArea = anchorArea
            self.anchorEnd = anchorEnd
            self.near = near
            self.at = at
            self.places = places
        }

        /// "Near Wiener Platz, after EU Blue Card appointment", or "Open now near Ehrenfeld".
        public var heading: String {
            if let anchorArea, let anchorTitle { return "Near \(anchorArea), after \(anchorTitle)" }
            return "Open now near \(near ?? "you")"
        }
    }

    public struct Paperwork: Equatable, Sendable {
        public let processTitle: String?
        public let title: String
        public let ready: Int?
        public let total: Int?
        public let process: String?

        public init(processTitle: String?, title: String, ready: Int?, total: Int?, process: String?) {
            self.processTitle = processTitle
            self.title = title
            self.ready = ready
            self.total = total
            self.process = process
        }
    }

    /// The last Composer conversation worth going back to.
    public struct Continuation: Equatable, Sendable {
        public let kind: String
        public let title: String
        public let lastAt: Date?

        public init(kind: String, title: String, lastAt: Date?) {
            self.kind = kind
            self.title = title
            self.lastAt = lastAt
        }
    }

    public struct Saved: Equatable, Sendable {
        public struct Item: Equatable, Sendable, Identifiable {
            public let type: String
            public let id: Int
            public let candidate: String
            public let title: String
            public let date: String?
            public let place: String?
            public let label: String?
            public let area: String?

            public init(type: String, id: Int, candidate: String = "", title: String, date: String?, place: String?, label: String?, area: String?) {
                self.type = type
                self.id = id
                self.candidate = candidate
                self.title = title
                self.date = date
                self.place = place
                self.label = label
                self.area = area
            }

            /// "Museum · Altstadt", "Sat 7 Nov · Stadtgarten".
            public var line: String {
                if type == "place" { return [label, area].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: " · ") }
                return [date.map(TodayPage.shortDay), place].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: " · ")
            }
        }

        public let count: Int
        public let items: [Item]

        public init(count: Int, items: [Item]) {
            self.count = count
            self.items = items
        }
    }

    public struct Later: Equatable, Sendable {
        public let date: String?
        public let title: String
        public let place: String?
        public let start: String?

        public init(date: String? = nil, title: String, place: String?, start: String?) {
            self.date = date
            self.title = title
            self.place = place
            self.start = start
        }
    }

    /// `yyyy-MM-dd`, in Cologne's time.
    public let date: String
    public let now: Date
    /// The plan's revision a change to the day sends.
    public var revision: Int = 0
    /// A first visit: the page leads with Paperwork's way in.
    public var welcome = false
    /// Personalised suggestions are on (How we rank says so).
    public var personalised = false
    /// Their own order waits for a yes to a changed notice.
    public var rankPaused: RankPause?

    /// The notice that changed since their yes, for the Agree sheet.
    public struct RankPause: Equatable, Sendable {
        public let notice: [String]
        public let changes: [String]?
        public let modelChanged: Bool
        public let noticeVersion: String
        public let providerVersion: String

        public init(notice: [String], changes: [String]?, modelChanged: Bool, noticeVersion: String, providerVersion: String) {
            self.notice = notice
            self.changes = changes
            self.modelChanged = modelChanged
            self.noticeVersion = noticeVersion
            self.providerVersion = providerVersion
        }

        /// What changed, in words; the model's change last.
        public var changed: [String] {
            (changes ?? []) + (modelChanged ? ["The AI service’s model or its instructions changed."] : [])
        }
    }

    /// What the page puts first (the web's priority.ts): the day while plans are ahead; on a first
    /// visit, or with no ideas left, Paperwork's way in; any other free day, the ideas.
    public enum Lead: Equatable, Sendable { case day, ideas, paperwork }

    public var lead: Lead {
        // A trip under way, or one they're about to leave for, is the day.
        if !ahead.isEmpty || trip != nil || leave != nil { return .day }
        return welcome || (ideas.isEmpty && free == nil) ? .paperwork : .ideas
    }

    /// What keeps the lead steady: it moves only when plans or whether there are ideas change, never on the clock.
    public var leadKey: String {
        plans.map { "\($0.id):\($0.completed)" }.joined(separator: "|") + "#\(!ideas.isEmpty || free != nil)#\(welcome)#\(trip?.to ?? "")#\(leave != nil)"
    }
    public let weather: Weather?
    public let plans: [Plan]
    public let links: [Link]
    public var leave: Leave?
    public let deadline: Deadline?
    public let needs: [Need]
    public var ideas: [Idea]
    /// "morning", "afternoon", "evening"…: which part of the day the ideas are for.
    public let ideasPart: String
    /// The trip they're on, when they started one.
    public var trip: Trip?
    public let free: Free?
    public let paperwork: Paperwork?
    public let continuation: Continuation?
    public let saved: Saved?
    public let tomorrowDate: String?
    public let tomorrow: [Later]
    public let later: [Later]

    public init(date: String, now: Date, weather: Weather?, plans: [Plan], links: [Link] = [], leave: Leave?, deadline: Deadline? = nil, needs: [Need], ideas: [Idea], ideasPart: String, free: Free? = nil, paperwork: Paperwork? = nil, continuation: Continuation? = nil, saved: Saved? = nil, tomorrowDate: String? = nil, tomorrow: [Later], later: [Later] = []) {
        self.date = date
        self.now = now
        self.weather = weather
        self.plans = plans
        self.links = links
        self.leave = leave
        self.deadline = deadline
        self.needs = needs
        self.ideas = ideas
        self.ideasPart = ideasPart
        self.free = free
        self.paperwork = paperwork
        self.continuation = continuation
        self.saved = saved
        self.tomorrowDate = tomorrowDate
        self.tomorrow = tomorrow
        self.later = later
    }

    /// The greeting the canvas uses, by Cologne's clock.
    public static func greeting(at date: Date, name: String) -> String {
        let hour = Self.hour(date)
        let first = name.split(separator: " ").first.map(String.init) ?? name
        let hello = hour < 11 ? "Guten Morgen" : hour < 18 ? "Hallo" : "Guten Abend"
        return first.isEmpty ? hello : "\(hello), \(first)"
    }

    /// "Friday, 6 November", in Cologne's time.
    public var dateLine: String {
        Self.day(date, format: "EEEE, d MMMM") ?? date
    }

    /// The ideas' heading: "Ideas for this evening".
    public var ideasTitle: String {
        "Ideas for \(ideasWhen == "Today" ? "today" : ideasWhen == "Tomorrow" ? "tomorrow" : ideasWhen.lowercased())"
    }

    /// The chip under the greeting: "This evening".
    public var ideasWhen: String {
        switch ideasPart {
        case "morning": "This morning"
        case "afternoon": "This afternoon"
        case "evening", "night": "This evening"
        case "tomorrow": "Tomorrow"
        default: "Today"
        }
    }

    /// How the ideas sit with the day: "After Language class" when every plan left ends before
    /// their part of the day begins, "Nothing in your plan yet" with none left; tomorrow, its day.
    public var ideasLead: String? {
        if ideasPart == "tomorrow" { return tomorrowDate.flatMap { Self.day($0, format: "EEEE") } }
        if ahead.isEmpty, let leave = tripLeave { return "Before you leave at \(leave.time)" }
        if ahead.isEmpty, trip != nil { return nil }
        guard let last = ahead.last else { return "Nothing in your plan yet" }
        let begins = ["morning": "06:00", "afternoon": "12:00", "evening": "17:00", "night": "21:00"][ideasPart]
        guard let begins, let ends = last.end ?? last.start, ends <= begins else { return nil }
        return "After \(last.title)"
    }

    /// The trip they've set but not left for yet: when it leaves, and where to.
    public var tripLeave: (time: String, to: String)? {
        guard let trip, let first = trip.legs.first, first.departAt.timeIntervalSince(now) > 60 else { return nil }
        return (first.departTime, trip.to)
    }

    /// The plans still ahead today, in order.
    public var ahead: [Plan] { plans.filter { !$0.isPast } }

    /// The link that follows a plan on the line, if there's a later one.
    public func link(after plan: Plan) -> Link? { links.first { $0.from == plan.id } }

    /// The deadline belongs under the appointment it's for, as the canvas puts it.
    public func deadline(for plan: Plan) -> Deadline? {
        guard let deadline, let process = plan.bring?.process, process == deadline.process else { return nil }
        return deadline
    }

    /// The free time the places are for: "3 hours free · 15:00–18:00", or "Free from 15:00".
    public var freeLine: String? {
        guard let free else { return nil }
        // A trip set for later: the time is theirs until they leave.
        if let leave = tripLeave, let minutes = Self.minutes(from: free.anchorEnd ?? free.at, to: leave.time), minutes > 0 {
            return "\(Trip.span(Double(minutes) * 60)) free · until you leave at \(leave.time)"
        }
        let from = free.anchorEnd ?? free.at
        let next = ahead.compactMap(\.start).sorted().first { $0 > from }
        guard let next, let minutes = Self.minutes(from: from, to: next), minutes >= 60 else { return "Free from \(from)" }
        let hours = minutes / 60
        let words = minutes % 60 == 0 ? (hours == 1 ? "1 hour" : "\(hours) hours") : "\(minutes) min"
        return "\(words) free · \(from)–\(next)"
    }

    /// "Tomorrow · Saturday 7 November".
    public var tomorrowLine: String? {
        tomorrowDate.flatMap { Self.day($0, format: "EEEE d MMMM") }.map { "Tomorrow · \($0)" }
    }

    static let berlin = TimeZone(identifier: "Europe/Berlin")!

    static func hour(_ date: Date) -> Int {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = berlin
        return calendar.component(.hour, from: date)
    }

    /// "16:02" on the day of `date`, in Cologne's time.
    public static func today(_ clock: String, on date: Date) -> Date? {
        let parts = clock.split(separator: ":").compactMap { Int($0) }
        guard parts.count == 2 else { return nil }
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = berlin
        return calendar.date(bySettingHour: parts[0], minute: parts[1], second: 0, of: date)
    }

    /// "19:00", in Cologne's time.
    public static func clock(_ date: Date) -> String {
        let say = DateFormatter()
        say.locale = Locale(identifier: "en_GB")
        say.timeZone = berlin
        say.dateFormat = "HH:mm"
        return say.string(from: date)
    }

    /// "Sat 7 Nov", for a `yyyy-MM-dd`.
    public static func shortDay(_ date: String) -> String {
        day(date, format: "EEE d MMM") ?? date
    }

    static func day(_ date: String, format: String) -> String? {
        let parse = DateFormatter()
        parse.calendar = Calendar(identifier: .gregorian)
        parse.timeZone = berlin
        parse.dateFormat = "yyyy-MM-dd"
        guard let day = parse.date(from: date) else { return nil }
        let say = DateFormatter()
        say.locale = Locale(identifier: "en_GB")
        say.timeZone = berlin
        say.dateFormat = format
        return say.string(from: day)
    }

    static func minutes(from: String, to: String) -> Int? {
        func value(_ clock: String) -> Int? {
            let parts = clock.split(separator: ":").compactMap { Int($0) }
            return parts.count == 2 ? parts[0] * 60 + parts[1] : nil
        }
        guard let start = value(from), let end = value(to) else { return nil }
        return end - start
    }
}

/// What a change to the day did: the new revision, and the words for it with an Undo when it has one.
public struct DayChange: Equatable, Sendable {
    public let revision: Int
    public let id: String?
    public let label: String?
    public let undoable: Bool

    public init(revision: Int, id: String?, label: String?, undoable: Bool) {
        self.revision = revision
        self.id = id
        self.label = label
        self.undoable = undoable
    }
}

/// What only the app sees, for personalisation: an idea shown at a place in the list, or turned down.
public struct Signal: Equatable, Sendable {
    public enum Action: String, Sendable { case impression, notForMe = "not_for_me", lessLikeThis = "less_like_this" }

    public let action: Action
    public let event: Int
    public let position: Int?

    public init(action: Action, event: Int, position: Int? = nil) {
        self.action = action
        self.event = event
        self.position = position
    }
}
