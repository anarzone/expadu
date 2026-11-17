import EventKit
import SwiftUI

/// Their own calendar on Today (the calendar canvas, owner 17 Nov 2026: "titles stay on phone, today
/// and tomorrow only, build it"). Read on this phone only: Your day shows its entries, and Today's
/// request carries just when they're busy today and tomorrow (Expadu-Busy), never a title, place,
/// note or person. Its own switch on What Expadu knows, off until they turn it on.
@MainActor @Observable
final class MyCalendar {
    static let shared = MyCalendar()

    struct Entry: Identifiable, Equatable {
        let id: String
        let title: String
        let start: Date
        let end: Date
        let calendar: String
        let color: Color
    }

    struct Source: Identifiable, Equatable {
        let id: String
        let title: String
        let color: Color
    }

    private let store = EKEventStore()
    private let defaults = UserDefaults.standard

    /// Turned on here, and allowed by iOS.
    private(set) var on: Bool
    private(set) var status: EKAuthorizationStatus
    /// The calendars it reads (their ids); nil until they choose: all but Birthdays and subscribed ones.
    private(set) var chosen: Set<String>?
    /// Bumped when the calendar changes on the phone, so Today reads it again.
    private(set) var changes = 0

    private init() {
        on = defaults.bool(forKey: "calendar.on")
        status = EKEventStore.authorizationStatus(for: .event)
        chosen = (defaults.array(forKey: "calendar.chosen") as? [String]).map(Set.init)
        NotificationCenter.default.addObserver(forName: .EKEventStoreChanged, object: store, queue: .main) { [weak self] _ in
            MainActor.assumeIsolated { self?.changes += 1 }
        }
    }

    /// On, and iOS lets it read.
    var reading: Bool { on && status == .fullAccess }

    /// iOS was asked and said no: only Settings can change it now.
    var refused: Bool { status == .denied || status == .restricted }

    /// After the one-button Continue: iOS asks; on if they allow.
    func turnOn() async {
        if status != .fullAccess {
            _ = try? await store.requestFullAccessToEvents()
            status = EKEventStore.authorizationStatus(for: .event)
            // Read before they answered: start afresh (EKEventStore.reset, Apple's note).
            store.reset()
        }
        on = status == .fullAccess
        defaults.set(on, forKey: "calendar.on")
        changes += 1
    }

    /// Off at once: nothing was kept anywhere to erase.
    func turnOff() {
        on = false
        defaults.set(false, forKey: "calendar.on")
        changes += 1
    }

    /// Their calendars, as iOS lists them.
    var sources: [Source] {
        guard status == .fullAccess else { return [] }
        return store.calendars(for: .event).map { Source(id: $0.calendarIdentifier, title: $0.title, color: Color(cgColor: $0.cgColor)) }
    }

    func reads(_ source: Source) -> Bool {
        if let chosen { return chosen.contains(source.id) }
        guard let calendar = store.calendar(withIdentifier: source.id) else { return false }
        return Self.byDefault(calendar)
    }

    func toggle(_ source: Source) {
        var next = chosen ?? Set(sources.filter { reads($0) }.map(\.id))
        if next.contains(source.id) { next.remove(source.id) } else { next.insert(source.id) }
        chosen = next
        defaults.set(Array(next), forKey: "calendar.chosen")
        changes += 1
    }

    /// What's still ahead today, for Your day.
    func today(now: Date = .now) -> [Entry] {
        entries(from: now, to: Calendar.current.startOfDay(for: now).addingTimeInterval(86_400))
    }

    /// When they're busy today and tomorrow, as times only: the Expadu-Busy header.
    func busyHeader(now: Date = .now) -> String? {
        let start = Calendar.current.startOfDay(for: now)
        let spans = entries(from: start, to: start.addingTimeInterval(2 * 86_400))
            .map { "\(Self.stamp.string(from: $0.start))/\(Self.stamp.string(from: $0.end))" }
        return spans.isEmpty ? nil : spans.joined(separator: ",")
    }

    private func entries(from: Date, to: Date) -> [Entry] {
        guard reading else { return [] }
        let calendars = store.calendars(for: .event).filter { calendar in
            chosen.map { $0.contains(calendar.calendarIdentifier) } ?? Self.byDefault(calendar)
        }
        guard !calendars.isEmpty else { return [] }
        let predicate = store.predicateForEvents(withStart: from, end: to, calendars: calendars)
        return store.events(matching: predicate)
            // All-day entries and ones marked free don't make them busy.
            .filter { !$0.isAllDay && $0.availability != .free && $0.endDate > from }
            .sorted { $0.startDate < $1.startDate }
            .map { Entry(id: $0.calendarItemIdentifier + "@\($0.startDate.timeIntervalSince1970)", title: $0.title ?? "Busy", start: $0.startDate, end: $0.endDate,
                         calendar: $0.calendar.title, color: Color(cgColor: $0.calendar.cgColor)) }
    }

    /// Birthdays and subscribed calendars (holidays, sports fixtures) start off.
    private static func byDefault(_ calendar: EKCalendar) -> Bool {
        calendar.type != .birthday && calendar.type != .subscription
    }

    /// Cologne's time, as the server reads it.
    private static let stamp: DateFormatter = {
        let format = DateFormatter()
        format.locale = Locale(identifier: "en_US_POSIX")
        format.timeZone = TimeZone(identifier: "Europe/Berlin")
        format.dateFormat = "yyyy-MM-dd'T'HH:mm"
        return format
    }()
}
