import API
import Core
import DesignSystem
import SwiftUI
import UIKit
import UserNotifications

/// Settings › Notifications (canvas "Expadu iPhone: Telling you in time", Settings board; owner's go
/// on 17 Nov): which kinds they get, the brief's time and quiet hours, as the web's Settings keeps
/// them, and whether iOS lets Expadu notify at all. Plain, as iOS's own settings are.
@Observable @MainActor
final class NotificationsModel {
    var settings: NotificationSettings?
    var problem: String?
    var status: UNAuthorizationStatus = .notDetermined

    func load(_ api: AppAPI) async {
        await readStatus()
        do throws(APIFailure) {
            settings = try await api.notificationSettings()
            problem = nil
        } catch {
            problem = Self.words(error)
        }
    }

    func readStatus() async {
        status = await UNUserNotificationCenter.current().notificationSettings().authorizationStatus
    }

    /// One change, shown at once; put back with the reason if the server says no. What the phone has
    /// scheduled is read again after, so a kind turned off stops at once.
    func change(_ api: AppAPI, _ change: NotificationSettings.Change) async {
        guard let before = settings else { return }
        withAnimation(.snappy) { settings = before.with(change) }
        do throws(APIFailure) {
            settings = try await api.saveNotificationSettings(change)
            await Notifier.shared.sync(api)
        } catch {
            withAnimation(.snappy) { settings = before }
            problem = Self.words(error)
        }
    }

    /// Asked here, where it's plain why: iOS's own question.
    func ask(_ api: AppAPI) async {
        _ = try? await UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound, .badge])
        await readStatus()
        await Notifier.shared.sync(api)
    }

    static func words(_ error: APIFailure) -> String {
        switch error {
        case let .refused(text): text
        case .signedOut: "You’re signed out. Sign in again to change this."
        default: "Couldn’t reach Expadu. Check your connection and try again."
        }
    }
}

struct NotificationsView: View {
    @Environment(Session.self) private var session
    @Environment(\.dismiss) private var dismiss
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Environment(\.scenePhase) private var scenePhase
    @State private var model = NotificationsModel()
    @State private var arrived = false

    /// The kinds they choose, in the board's order: its key on the server, its name and what it means.
    private static let kinds: [(key: String, label: String, sub: String)] = [
        ("events", "Events you asked about", "When you tapped Remind me on one"),
        ("transit", "Changes to your plans", "A delay or cancellation that changes what to do, an hour before"),
        ("weather", "Rain on your plans", "Only for something outdoors you planned"),
        ("brief", "Morning brief", "One line about your day, on days with something on"),
    ]
    private static let briefTimes: [String?] = [nil, "07:00", "07:30", "08:00", "08:30", "09:00"]
    private static let quietStarts = ["20:00", "21:00", "21:30", "22:00", "22:30", "23:00", "00:00"]
    private static let quietEnds = ["05:00", "06:00", "06:30", "07:00", "07:30", "08:00", "09:00"]

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                Button { back() } label: {
                    Image(systemName: "chevron.left").font(.system(size: 17, weight: .semibold)).foregroundStyle(Palette.text)
                }
                .buttonStyle(IconTileStyle(fill: Palette.surface, size: 44))
                .accessibilityLabel("Back")
                Text("Notifications")
                    .font(.posterGrotesque(32)).tracking(-0.4).foregroundStyle(Palette.text)
                    .padding(.top, 14).padding(.horizontal, 4).padding(.bottom, 6)
                    .accessibilityAddTraits(.isHeader)
                Text("Only as many as you need: when something you planned needs you to act.")
                    .font(.geist(15)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                    .padding(.horizontal, 4).padding(.bottom, 16)

                permission
                if let settings = model.settings {
                    kinds(settings)
                    quiet(settings)
                } else if let problem = model.problem {
                    Text(problem).font(.geist(15)).foregroundStyle(Palette.conflictText).padding(.horizontal, 4).padding(.top, 10)
                    PaperworkButton(title: "Try again", quiet: true, small: true) { Task { await model.load(session.api) } }.padding(.top, 10)
                } else {
                    ProgressView().frame(maxWidth: .infinity).padding(.top, 40)
                }
            }
            .padding(.horizontal, 16).padding(.top, 8).padding(.bottom, 120)
            .alert("That didn’t go through", isPresented: Binding(get: { model.problem != nil && model.settings != nil }, set: { if !$0 { model.problem = nil } })) {
                Button("OK", role: .cancel) {}
            } message: {
                Text(model.problem ?? "")
            }
        }
        .scrollIndicators(.hidden)
        .background(Palette.canvas)
        .swipeBack { quietly(dismiss) }
        .visualEffect { [arrived] content, proxy in content.offset(x: arrived ? 0 : proxy.size.width) }
        .onAppear { withAnimation(reduceMotion ? nil : .smooth(duration: 0.38)) { arrived = true } }
        .task { await model.load(session.api) }
        // Back from iOS's Settings: what they allowed there shows at once.
        .onChange(of: scenePhase) { _, phase in if phase == .active { Task { await model.readStatus() } } }
    }

    // MARK: Parts

    /// Whether iOS lets Expadu notify: said only when it doesn't.
    @ViewBuilder
    private var permission: some View {
        switch model.status {
        case .denied:
            VStack(alignment: .leading, spacing: 6) {
                Text("Notifications are off for Expadu in your iPhone’s Settings, so none of these can reach you.")
                    .font(.geist(14)).foregroundStyle(Palette.warnText).fixedSize(horizontal: false, vertical: true)
                Button("Open Settings") {
                    if let url = URL(string: UIApplication.openNotificationSettingsURLString) { UIApplication.shared.open(url) }
                }
                .font(.geist(14.5, .bold)).foregroundStyle(Palette.warnText).underline()
                .frame(minHeight: 36)
            }
            .padding(.horizontal, 14).padding(.vertical, 12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Palette.warnBackground, in: .rect(cornerRadius: 16))
            .padding(.bottom, 14)
            .transition(.opacity.combined(with: .move(edge: .top)))
        case .notDetermined:
            HStack(spacing: 12) {
                Text("Expadu hasn’t asked to notify you yet.")
                    .font(.geist(14)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                Spacer(minLength: 4)
                PaperworkButton(title: "Turn on", small: true) { Task { await model.ask(session.api) } }
            }
            .padding(.horizontal, 14).padding(.vertical, 10)
            .background(Palette.quiet, in: .rect(cornerRadius: 16))
            .padding(.bottom, 14)
            .transition(.opacity)
        default:
            EmptyView()
        }
    }

    @ViewBuilder
    private func kinds(_ settings: NotificationSettings) -> some View {
        VStack(spacing: 0) {
            row(label: "When to leave", sub: "For each trip you set Remind me on, 5 min before") {
                Text("On each trip").font(.geist(14)).foregroundStyle(Palette.textSoft)
            }
            ForEach(Self.kinds, id: \.key) { kind in
                Divider().overlay(Palette.border)
                row(label: kind.label, sub: kind.sub) {
                    Toggle(kind.label, isOn: Binding(
                        get: { settings.on(kind.key) },
                        set: { on in Task { await model.change(session.api, .kind(kind.key, on: on)) } }
                    ))
                    .labelsHidden()
                }
            }
            if settings.on("brief") {
                Divider().overlay(Palette.border)
                HStack {
                    Text("Brief at").font(.geist(15.5, .medium)).foregroundStyle(Palette.text)
                    Spacer()
                    Picker("Brief at", selection: Binding(
                        get: { settings.briefAt },
                        set: { at in Task { await model.change(session.api, .briefAt(at)) } }
                    )) {
                        ForEach(Self.briefTimes, id: \.self) { time in Text(time ?? "Before your first thing").tag(time) }
                    }
                    .pickerStyle(.menu).tint(Palette.textSoft).fixedSize()
                }
                .padding(.horizontal, 16).frame(minHeight: 50)
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
        .card(radius: 18)
        .animation(.snappy, value: settings.on("brief"))
    }

    @ViewBuilder
    private func quiet(_ settings: NotificationSettings) -> some View {
        Text("Quiet hours").font(.posterGrotesque(19)).foregroundStyle(Palette.text)
            .padding(.top, 26).padding(.bottom, 8).padding(.horizontal, 4)
            .accessibilityAddTraits(.isHeader)
        VStack(spacing: 0) {
            time("From", value: settings.quietFrom, options: Self.quietStarts) { at in
                Task { await model.change(session.api, .quietFrom(at)) }
            }
            Divider().overlay(Palette.border)
            time("Until", value: settings.quietTo, options: Self.quietEnds) { at in
                Task { await model.change(session.api, .quietTo(at)) }
            }
        }
        .card(radius: 18)
        Text("Only the trips you set Remind me on come through quiet hours.")
            .font(.geist(13)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
            .padding(.horizontal, 4).padding(.top, 8)
        Text("Expadu sets these up on your iPhone each time you open it. A change after that (a cancelled tram) reaches you the next time you open Expadu.")
            .font(.geist(13)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
            .padding(.horizontal, 4).padding(.top, 18)
    }

    private func row<Trailing: View>(label: String, sub: String, @ViewBuilder trailing: () -> Trailing) -> some View {
        HStack(spacing: 12) {
            VStack(alignment: .leading, spacing: 2) {
                Text(label).font(.geist(16, .semibold)).foregroundStyle(Palette.text)
                Text(sub).font(.geist(13)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
            }
            Spacer(minLength: 4)
            trailing()
        }
        .padding(.horizontal, 16).padding(.vertical, 8).frame(minHeight: 60)
    }

    private func time(_ label: String, value: String, options: [String], set: @escaping (String) -> Void) -> some View {
        HStack {
            Text(label).font(.geist(15.5, .medium)).foregroundStyle(Palette.text)
            Spacer()
            Picker(label, selection: Binding(get: { value }, set: set)) {
                // Theirs, if it's not one of ours (set on the web).
                ForEach(options.contains(value) ? options : [value] + options, id: \.self) { Text($0).tag($0) }
            }
            .pickerStyle(.menu).tint(Palette.textSoft)
        }
        .padding(.horizontal, 16).frame(minHeight: 50)
    }

    /// Back: out to the right, then closed.
    private func back() {
        withAnimation(reduceMotion ? nil : .smooth(duration: 0.3)) { arrived = false } completion: { quietly(dismiss) }
    }

    private func quietly(_ close: DismissAction) {
        var quiet = Transaction()
        quiet.disablesAnimations = true
        withTransaction(quiet) { close() }
    }
}

// MARK: - Asked when it helps

/// Asking to notify them, at the moment it helps: right after they asked for a reminder, and only
/// while iOS hasn't asked yet. Apple's HIG on screens before its alert: one button, that opens the
/// alert, titled Continue (never Allow), and no way to close it without seeing the alert.
@Observable @MainActor
final class NotificationAsk {
    static let shared = NotificationAsk()

    /// What the reminder will say, shown as it will arrive.
    struct Preview: Equatable {
        let title: String
        let body: String
        let at: Date?
    }

    var preview: Preview?

    /// After a reminder was set: iOS asked already, so the reminder is scheduled now; or not yet, so
    /// the screen first.
    func afterReminder(_ preview: Preview, _ api: AppAPI) async {
        let status = await UNUserNotificationCenter.current().notificationSettings().authorizationStatus
        if status == .notDetermined {
            self.preview = preview
        } else {
            await Notifier.shared.sync(api)
        }
    }

    /// Continue: iOS's own question, then what they allowed is scheduled.
    func proceed(_ api: AppAPI) async {
        _ = try? await UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound, .badge])
        preview = nil
        await Notifier.shared.sync(api)
    }
}

extension View {
    /// Where a reminder is set: the ask comes over it, one way on (Continue), no swipe away.
    func notificationAsk() -> some View {
        sheet(isPresented: Binding(get: { NotificationAsk.shared.preview != nil }, set: { _ in })) {
            if let preview = NotificationAsk.shared.preview {
                NotificationAskSheet(preview: preview)
                    .presentationDetents([.medium])
                    .presentationBackground(Palette.surface)
                    .presentationCornerRadius(32)
                    .interactiveDismissDisabled()
            }
        }
    }
}

struct NotificationAskSheet: View {
    let preview: NotificationAsk.Preview
    @Environment(Session.self) private var session
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var arrived = false
    @State private var asking = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text("So I can tell you when to leave")
                .font(.posterGrotesque(24)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                .accessibilityAddTraits(.isHeader)
            Text("\(preview.at.map { "At \(TodayPage.clock($0)), even" } ?? "Even") with Expadu closed. Your iPhone asks you next. Only what you ask for comes; the rest you choose in Notifications.")
                .font(.geist(15)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                .padding(.top, 6)
            lockScreen.padding(.top, 14)
            Button {
                asking = true
                Task { await NotificationAsk.shared.proceed(session.api) }
            } label: {
                Text("Continue").font(.geist(16.5, .semibold)).foregroundStyle(Palette.onAction)
                    .frame(maxWidth: .infinity, minHeight: 52)
            }
            .buttonFace(Palette.action, radius: 18)
            .disabled(asking)
            .padding(.top, 16)
        }
        .padding(.horizontal, 20).padding(.top, 24).padding(.bottom, 12)
        .onAppear { withAnimation(reduceMotion ? nil : .spring(duration: 0.55, bounce: 0.3).delay(0.35)) { arrived = true } }
    }

    /// The reminder as their Lock Screen will show it, dropping in.
    private var lockScreen: some View {
        ZStack(alignment: .bottom) {
            LinearGradient(colors: [Color(hex: 0x2B3A55), Color(hex: 0x6B5A7A), Color(hex: 0xC98B6B)], startPoint: .topLeading, endPoint: .bottomTrailing)
            VStack(spacing: 0) {
                Text(preview.at.map { $0.formatted(.dateTime.weekday(.wide).day().month(.wide)) } ?? "")
                    .font(.system(size: 12.5, weight: .semibold)).foregroundStyle(.white.opacity(0.9))
                Text(preview.at.map { $0.formatted(.dateTime.hour().minute()) } ?? "")
                    .font(.system(size: 46, weight: .bold)).foregroundStyle(.white)
                Spacer(minLength: 0)
            }
            .padding(.top, 14).frame(maxWidth: .infinity, maxHeight: .infinity)
            HStack(alignment: .top, spacing: 10) {
                Text("E").font(.posterGrotesque(15)).foregroundStyle(.white)
                    .frame(width: 34, height: 34).background(Palette.text, in: .rect(cornerRadius: 9))
                VStack(alignment: .leading, spacing: 1) {
                    HStack {
                        Text(preview.title).font(.system(size: 14, weight: .semibold)).lineLimit(1)
                        Spacer(minLength: 4)
                        Text("now").font(.system(size: 12)).foregroundStyle(.secondary)
                    }
                    Text(preview.body).font(.system(size: 13.5)).lineLimit(2)
                }
                .foregroundStyle(.black)
            }
            .padding(.horizontal, 12).padding(.vertical, 10)
            .background(.regularMaterial, in: .rect(cornerRadius: 20))
            .environment(\.colorScheme, .light)
            .padding(10)
            .opacity(arrived ? 1 : 0).offset(y: arrived ? 0 : -34).scaleEffect(arrived ? 1 : 0.94)
        }
        .frame(height: 162)
        .clipShape(.rect(cornerRadius: 26))
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Your Lock Screen then: \(preview.title). \(preview.body)")
    }
}
