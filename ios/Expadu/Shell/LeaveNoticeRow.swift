import API
import Core
import DesignSystem
import SwiftUI

/// "Notice when I leave home or work", under "Learn from my trips" on What Expadu knows (the canvas
/// "When you leave", Knows board): its switch, what it noticed lately (kept on this phone), what
/// Expadu gets, and the way to Settings when location isn't "Always".
struct LeaveNoticeRow: View {
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    private var watcher: LeaveWatcher { LeaveWatcher.shared }
    @State private var switchOn = LeaveWatcher.shared.on
    @State private var asking = false
    @State private var problem: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 14) {
                VStack(alignment: .leading, spacing: 2) {
                    Text("Notice when I leave home or work").font(.geist(16, .semibold)).foregroundStyle(Palette.text)
                    Text("Then Expadu can tell you when your tram leaves, right as you step out the door. It also learns from trips you don’t start in the app.")
                        .font(.geist(13.5)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                }
                Spacer(minLength: 4)
                Toggle("Notice when I leave home or work", isOn: $switchOn).labelsHidden()
                    .onChange(of: switchOn) { _, on in
                        guard on != watcher.on else { return }
                        if on { asking = true } else { Task { await turnOff() } }
                    }
            }
            .padding(.horizontal, 16).padding(.vertical, 14)

            if watcher.on && !watcher.always {
                Divider().overlay(Palette.border)
                Button { if let url = URL(string: UIApplication.openSettingsURLString) { UIApplication.shared.open(url) } } label: {
                    Text("Expadu can only notice when you leave if location is set to “Always”. You can change this in Settings › Expadu › Location.")
                        .font(.geist(13.5)).foregroundStyle(Palette.textSoft).multilineTextAlignment(.leading)
                        .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 16).padding(.vertical, 12)
                }
                .buttonStyle(PressStyle())
            } else if watcher.on {
                Divider().overlay(Palette.border)
                VStack(alignment: .leading, spacing: 0) {
                    Text("WHAT EXPADU NOTICED LATELY").font(.geist(12.5, .bold)).tracking(0.3).foregroundStyle(Palette.textSoft)
                        .padding(.bottom, 4)
                    if watcher.crossings.isEmpty {
                        Text("Nothing yet. The next time you leave home or work, it shows here.")
                            .font(.geist(14)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true).padding(.vertical, 6)
                    }
                    ForEach(watcher.crossings) { crossing in
                        HStack {
                            Text("\(crossing.left ? "Left" : "Arrived at") \(crossing.place)").font(.geist(15)).foregroundStyle(Palette.text)
                            Spacer()
                            Text(crossing.at.formatted(.dateTime.weekday(.wide).hour(.twoDigits(amPM: .omitted)).minute()))
                                .font(.geist(14)).foregroundStyle(Palette.textSoft).monospacedDigit()
                        }
                        .padding(.vertical, 7)
                    }
                    Text("Your iPhone tells Expadu just one thing: when you arrive at or leave home or work. Never where you are or where you go. Trips between them are kept for 90 days to learn your habits; turn this off and they’re deleted.")
                        .font(.geist(13)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true).padding(.top, 8)
                }
                .padding(.horizontal, 16).padding(.vertical, 12)
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
            if let problem {
                Text(problem).font(.geist(13.5)).foregroundStyle(Palette.conflictText).padding(.horizontal, 16).padding(.bottom, 10)
            }
        }
        .sheet(isPresented: $asking, onDismiss: { switchOn = watcher.on }) {
            LeaveAskSheet { await turnOn() }
                .presentationDetents([.medium])
                .presentationBackground(Palette.canvas)
                .interactiveDismissDisabled()
        }
    }

    private func turnOn() async {
        do throws(APIFailure) {
            try await watcher.turnOn()
            problem = nil
            await today.loadRoutines(session.api)
        } catch {
            problem = "That didn’t work. Please try again."
        }
        asking = false
    }

    private func turnOff() async {
        do throws(APIFailure) {
            try await watcher.turnOff()
            problem = nil
            await today.loadRoutines(session.api)
        } catch {
            switchOn = true
            problem = "That didn’t work. Please try again."
        }
    }
}

/// Before iOS asks for "Always": what it's for and what it never knows, and one button (Apple's HIG).
struct LeaveAskSheet: View {
    let proceed: () async -> Void
    @State private var asking = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text("Get a heads-up as you leave")
                .font(.posterGrotesque(24)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                .accessibilityAddTraits(.isHeader)
            Text("On days you usually go to work, Expadu can tell you when your tram leaves as soon as you step out the door. For this, your iPhone will ask if Expadu may use your location “Always”, even when the app is closed.")
                .font(.geist(15)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true).padding(.top, 8)
            Text("Expadu only learns when you arrive at or leave home or work. It never knows where you are.")
                .font(.geist(14)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true).padding(.top, 6)
            Spacer(minLength: 12)
            Button {
                asking = true
                Task { await proceed() }
            } label: {
                Text("Continue").font(.geist(16.5, .semibold)).foregroundStyle(Palette.onAction)
                    .frame(maxWidth: .infinity, minHeight: 52)
            }
            .buttonFace(Palette.action, radius: 18)
            .disabled(asking)
        }
        .padding(.horizontal, 20).padding(.top, 24).padding(.bottom, 12)
    }
}
