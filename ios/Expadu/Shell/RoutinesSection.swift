import API
import Core
import DesignSystem
import SwiftUI

/// Your routines, on What Expadu knows (canvas "Expadu iPhone: Routines", Knows board): learning
/// from their trips on or off (its own yes; off erases the trips and routines, asked first), each
/// routine with whether it tells them when to leave and Forget (with Undo), and the trips kept with
/// Forget my trips (permanent, asked first). Plain, as the rest of the page.
struct RoutinesSection: View {
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @State private var switchOn = false
    @State private var asking: Ask?
    @State private var problem: String?
    @State private var busy = false
    /// Forgotten, with Undo for a moment before it goes to the server.
    @State private var forgetting: RoutinesState.Routine?
    @State private var forgetTask: Task<Void, Never>?

    enum Ask: Identifiable {
        case off, trips
        var id: Self { self }
    }

    private var state: RoutinesState? { today.routines }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text("Your routines").font(.posterGrotesque(19)).foregroundStyle(Palette.text).accessibilityAddTraits(.isHeader)
                .padding(.bottom, 8).padding(.horizontal, 4)
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 14) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("Learn from my trips").font(.geist(16, .semibold)).foregroundStyle(Palette.text)
                        Text("Keeps the trips you start for 90 days to spot your usual ones. Never health services or places of worship; of where you start, only whether it’s Home or Work.")
                            .font(.geist(13.5)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                    }
                    Spacer(minLength: 4)
                    Toggle("Learn from my trips", isOn: $switchOn).labelsHidden().disabled(busy || state == nil)
                        .onChange(of: switchOn) { _, on in
                            guard let state, on != state.learning else { return }
                            if on { Task { await turnOn() } } else { asking = .off }
                        }
                }
                .padding(.horizontal, 16).padding(.vertical, 14)

                if let state, state.learning {
                    ForEach(state.routines) { routine in
                        Divider().overlay(Palette.border)
                        row(routine).transition(.opacity.combined(with: .move(edge: .top)))
                    }
                    if let forgetting {
                        Divider().overlay(Palette.border)
                        HStack {
                            Text("Forgot To \(forgetting.toLabel)").font(.geist(14)).foregroundStyle(Palette.textSoft)
                            Spacer()
                            Button("Undo") { undoForget() }.font(.geist(15, .bold)).foregroundStyle(Palette.text).frame(minHeight: 44)
                        }
                        .padding(.horizontal, 16)
                        .transition(.opacity)
                    }
                    if state.routines.isEmpty && forgetting == nil {
                        Divider().overlay(Palette.border)
                        Text("No routines yet. When the same trip comes up three times, Today offers it.")
                            .font(.geist(14)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                            .padding(.horizontal, 16).padding(.vertical, 12)
                    }
                    Divider().overlay(Palette.border)
                    HStack(spacing: 10) {
                        Text(state.tripsKept == 0 ? "No trips kept." : "\(state.tripsKept) trips kept from the last 90 days.")
                            .font(.geist(13.5)).foregroundStyle(Palette.textSoft).monospacedDigit().contentTransition(.numericText())
                        Spacer(minLength: 4)
                        if state.tripsKept > 0 {
                            Button("Forget my trips") { asking = .trips }
                                .font(.geist(14.5, .semibold)).foregroundStyle(Palette.conflictText).frame(minHeight: 40)
                        }
                    }
                    .padding(.horizontal, 16).padding(.vertical, 6)
                }
            }
            .card(radius: 18)
            .animation(.snappy, value: state)
            Text("Turning it off erases the trips kept and your routines.")
                .font(.geist(13)).foregroundStyle(Palette.textSoft).padding(.horizontal, 4).padding(.top, 8)
        }
        .task {
            await today.loadRoutines(session.api)
            switchOn = today.routines?.learning ?? false
        }
        .alert(asking == .off ? "Stop learning routines?" : "Forget your trips?", isPresented: Binding(get: { asking != nil }, set: { if !$0 { asking = nil } }), presenting: asking) { ask in
            Button(ask == .off ? "Erase and turn off" : "Forget my trips", role: .destructive) {
                Task { ask == .off ? await turnOff() : await forgetTrips() }
            }
            Button("Keep them", role: .cancel) { if ask == .off { withAnimation(.snappy) { switchOn = true } } }
        } message: { ask in
            Text(ask == .off ? "The trips kept and your routines are erased. This can’t be undone." : "The trips kept are erased; your routines stay. This can’t be undone.")
        }
        .alert("That didn’t go through", isPresented: Binding(get: { problem != nil }, set: { if !$0 { problem = nil } })) {
            Button("OK", role: .cancel) {}
        } message: {
            Text(problem ?? "")
        }
    }

    private func row(_ routine: RoutinesState.Routine) -> some View {
        HStack(spacing: 12) {
            VStack(alignment: .leading, spacing: 2) {
                Text(routine.toLabel).font(.geist(16, .semibold)).foregroundStyle(Palette.text)
                Text("\(Routines.days(routine.weekdays)) · leaving \(routine.at)").font(.geist(13)).foregroundStyle(Palette.textSoft).monospacedDigit()
            }
            Spacer(minLength: 4)
            Button {
                Task { await remind(routine, !routine.remind) }
            } label: {
                Image(systemName: routine.remind ? "bell.fill" : "bell.slash").font(.system(size: 17, weight: .semibold))
                    .foregroundStyle(routine.remind ? Palette.goodText : Palette.textSoft)
                    .contentTransition(.symbolEffect(.replace))
            }
            .buttonStyle(IconTileStyle(fill: routine.remind ? Palette.goodBackground : Palette.quiet, size: 44))
            .accessibilityLabel(routine.remind ? "Stop telling me when to leave" : "Tell me when to leave")
            Button { forget(routine) } label: {
                Image(systemName: "trash").font(.system(size: 17, weight: .semibold)).foregroundStyle(Palette.conflictText)
            }
            .buttonStyle(IconTileStyle(fill: Palette.surface, size: 44))
            .accessibilityLabel("Forget this routine")
        }
        .padding(.leading, 16).padding(.trailing, 12).padding(.vertical, 10)
    }

    // MARK: Actions

    private func turnOn() async {
        busy = true
        defer { busy = false }
        do throws(APIFailure) {
            try await session.api.setRoutineLearning(true)
            await today.loadRoutines(session.api)
        } catch {
            withAnimation(.snappy) { switchOn = false }
            problem = Self.words(error)
        }
    }

    private func turnOff() async {
        busy = true
        defer { busy = false }
        do throws(APIFailure) {
            try await session.api.setRoutineLearning(false)
            await today.loadRoutines(session.api)
            await Notifier.shared.sync(session.api)
        } catch {
            withAnimation(.snappy) { switchOn = true }
            problem = Self.words(error)
        }
    }

    private func forgetTrips() async {
        do throws(APIFailure) {
            try await session.api.forgetRoutineTrips()
            await today.loadRoutines(session.api)
        } catch {
            problem = Self.words(error)
        }
    }

    private func remind(_ routine: RoutinesState.Routine, _ on: Bool) async {
        guard let i = today.routines?.routines.firstIndex(where: { $0.id == routine.id }) else { return }
        withAnimation(.snappy) { today.routines?.routines[i].remind = on }
        do throws(APIFailure) {
            try await session.api.remindRoutine(routine.id, remind: on)
            if on { await NotificationAsk.shared.afterReminder(.init(title: "Time to leave for \(routine.toLabel)", body: "You usually leave at \(routine.at).", at: nil), session.api) } else { await Notifier.shared.sync(session.api) }
        } catch {
            withAnimation(.snappy) { today.routines?.routines[i].remind = !on }
            problem = Self.words(error)
        }
    }

    /// Gone at once, with Undo; it goes to the server once Undo has passed.
    private func forget(_ routine: RoutinesState.Routine) {
        forgetTask?.cancel()
        if let earlier = forgetting { send(earlier) }
        withAnimation(.snappy) {
            today.routines?.routines.removeAll { $0.id == routine.id }
            forgetting = routine
        }
        forgetTask = Task {
            try? await Task.sleep(for: .seconds(5))
            guard !Task.isCancelled else { return }
            send(routine)
            withAnimation(.snappy) { forgetting = nil }
        }
    }

    private func undoForget() {
        forgetTask?.cancel()
        guard let routine = forgetting else { return }
        withAnimation(.snappy) {
            today.routines?.routines.append(routine)
            today.routines?.routines.sort { $0.at < $1.at }
            forgetting = nil
        }
    }

    private func send(_ routine: RoutinesState.Routine) {
        Task {
            try? await session.api.forgetRoutine(routine.id)
            await Notifier.shared.sync(session.api)
        }
    }

    private static func words(_ error: APIFailure) -> String {
        switch error {
        case let .refused(why): why
        case .signedOut: "You’ve been signed out."
        case .tooManyTries: "Too many tries. Wait a moment."
        case .unreachable: "Couldn’t reach Expadu. Check your connection."
        }
    }
}
