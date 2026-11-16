import API
import Core
import Foundation
import UserNotifications

/// The phone's own notifications from the server's moments (track, week 2): without the paid Apple
/// account there's no push, so the next 36 hours are scheduled here, on every open and every return
/// to the app, each under its moment's id: one that moved replaces the one scheduled, one that went
/// is taken off. It only schedules once the person has allowed notifications; asking them is done in
/// context, never here. A tap opens where the moment links.
@Observable @MainActor
final class Notifier: NSObject, UNUserNotificationCenterDelegate {
    static let shared = Notifier()

    /// Where the last tapped notification links (/travel, /today, an event's page), for the shell to open.
    var opened: String?

    /// Ours, among the app's pending notifications.
    private static let prefix = "moment."
    /// iOS keeps at most 64 pending; some room stays for the rest of the app.
    private static let most = 60

    private let center = UNUserNotificationCenter.current()

    /// Set once at launch: taps come here, and a moment due while the app is open still shows.
    func start() {
        center.delegate = self
    }

    /// The moments read again and scheduled. Quiet when notifications aren't allowed, or the server can't be reached.
    func sync(_ api: AppAPI) async {
        let settings = await center.notificationSettings()
        guard [.authorized, .provisional, .ephemeral].contains(settings.authorizationStatus) else { return }
        guard let moments = try? await api.moments() else { return }
        let ahead = moments.filter { $0.at > .now }.prefix(Self.most)
        let wanted = Set(ahead.map { Self.prefix + $0.id })
        let scheduled = await center.pendingNotificationRequests().map(\.identifier).filter { $0.hasPrefix(Self.prefix) }
        center.removePendingNotificationRequests(withIdentifiers: scheduled.filter { !wanted.contains($0) })
        for moment in ahead {
            // The same id replaces what was scheduled, words and time.
            try? await center.add(Self.request(moment))
        }
    }

    /// Signed out: nothing of theirs stays scheduled on the phone.
    func clear() async {
        let ours = await center.pendingNotificationRequests().map(\.identifier).filter { $0.hasPrefix(Self.prefix) }
        center.removePendingNotificationRequests(withIdentifiers: ours)
        center.removeDeliveredNotifications(withIdentifiers: ours)
    }

    static func request(_ moment: Moment) -> UNNotificationRequest {
        let content = UNMutableNotificationContent()
        content.title = moment.title
        content.body = moment.body
        content.sound = .default
        content.threadIdentifier = moment.kind
        content.userInfo = ["link": moment.link]
        // When to leave matters most; the brief can wait for the summary.
        content.relevanceScore = moment.kind == "leave" ? 1 : moment.kind == "brief" ? 0.2 : 0.6
        content.interruptionLevel = moment.kind == "brief" ? .passive : .active
        let when = Calendar.current.dateComponents([.year, .month, .day, .hour, .minute, .second], from: moment.at)
        return UNNotificationRequest(identifier: prefix + moment.id, content: content,
                                     trigger: UNCalendarNotificationTrigger(dateMatching: when, repeats: false))
    }

    // MARK: UNUserNotificationCenterDelegate

    nonisolated func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification) async -> UNNotificationPresentationOptions {
        [.banner, .sound, .list]
    }

    nonisolated func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse) async {
        let link = response.notification.request.content.userInfo["link"] as? String
        await MainActor.run { self.opened = link ?? "/today" }
    }
}
