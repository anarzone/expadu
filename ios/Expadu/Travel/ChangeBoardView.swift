import API
import Core
import DesignSystem
import SwiftUI

/// The change as a live board (the canvas "When your tram is late", owner 18 Nov 2026): every tram on
/// to the next stop, any line, with when it leaves and when you'd get there; a dashed line where you
/// get to the stop, the ones before it faded; yours marked, a faster one tagged. Tap one to take it.
/// Under it, Switch trams for me (on: Expadu moves you, with Undo; off: it suggests, with Take it).
struct ChangeBoardView: View {
    let stop: String
    let live: TodayModel.LiveBoard
    private var ready: Date { live.ready }
    private var board: ChangeBoard { live.board }
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        let yours = board.yours
        let before = board.runs.filter { $0.departAt < ready }
        let after = board.runs.filter { $0.departAt >= ready }
        VStack(alignment: .leading, spacing: 0) {
            Text(live.connection != nil ? "Any of these gets you to your \(live.connection!.line) in time. Tap one to choose it yourself." : "Any of these takes you on. Tap one to choose it yourself.")
                .font(.geist(12.5)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                .padding(.bottom, 6)
            // The last two that leave before they're there: enough to see what they just missed.
            ForEach(before.suffix(2)) { run in row(run, gone: true, yours: yours) }
            HStack(spacing: 6) {
                Text("\(live.boarding ? "You get to the stop at" : "You get here at") \(ready.formatted(.dateTime.hour(.twoDigits(amPM: .omitted)).minute()))")
                    .font(.geist(11.5, .bold)).foregroundStyle(.white).lineLimit(1).fixedSize()
                    .padding(.horizontal, 7).padding(.vertical, 3)
                    .background(Palette.action, in: .rect(cornerRadius: 6))
                Line().stroke(style: StrokeStyle(lineWidth: 1.5, dash: [5, 4])).foregroundStyle(Palette.action).frame(height: 1.5)
            }
            .padding(.vertical, 4)
            .accessibilityElement(children: .combine)
            // Four, and always down to theirs and the one after it.
            ForEach(after.prefix(max(4, (after.firstIndex { $0.id == yours?.id } ?? 0) + 2))) { run in row(run, gone: false, yours: yours) }
            if let suggestion = model.changeSuggestion {
                HStack(spacing: 10) {
                    Text(suggestion.reason == .faster
                         ? "The \(suggestion.run.line) at \(suggestion.run.departTime) gets you there sooner, at \(suggestion.run.arriveTime)."
                         : TodayModel.why(suggestion.reason, yours: yours, stop: stop, connection: live.connection)
                            + " The \(suggestion.run.line) at \(suggestion.run.departTime) " + (board.makes(suggestion.run, live.connection) && live.connection != nil
                                ? "still gets you there at \(live.connection!.thereTime)." : "gets you there at \(suggestion.run.arriveTime)."))
                        .font(.geist(13.5)).foregroundStyle(Palette.warnText).fixedSize(horizontal: false, vertical: true)
                    Spacer(minLength: 4)
                    Button("Take it") { Task { await model.takeRun(session.api, suggestion.run) } }
                        .font(.geist(14, .semibold)).foregroundStyle(Palette.onInk)
                        .padding(.horizontal, 12).frame(minHeight: 40)
                        .background(Palette.text, in: .rect(cornerRadius: 12))
                        .buttonStyle(PressStyle())
                }
                .padding(10)
                .background(Palette.warnBackground, in: .rect(cornerRadius: 12))
                .padding(.top, 6)
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
            Divider().overlay(Palette.border).padding(.top, 8)
            Toggle(isOn: Binding(get: { board.autoSwitch }, set: { on in Task { await model.setAutoSwitch(session.api, on) } })) {
                VStack(alignment: .leading, spacing: 1) {
                    Text("Switch trams for me").font(.geist(14.5, .semibold)).foregroundStyle(Palette.text)
                    Text(board.autoSwitch
                         ? "When your tram runs late or a faster one comes, Expadu moves you to it. You can undo it."
                         : "Expadu only suggests a better tram. You decide.")
                        .font(.geist(12.5)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                }
            }
            .padding(.top, 8)
        }
        .padding(12)
        .background(Palette.surface, in: .rect(cornerRadius: 16))
        .overlay(RoundedRectangle(cornerRadius: 16).stroke(Palette.border))
        .animation(reduceMotion ? nil : .spring(duration: 0.45, bounce: 0.15), value: board)
        .animation(reduceMotion ? nil : .spring(duration: 0.45, bounce: 0.15), value: ready)
    }

    private func row(_ run: ChangeBoard.Run, gone: Bool, yours: ChangeBoard.Run?) -> some View {
        let mine = run.id == yours?.id
        let faster = live.connection == nil && !gone && !mine && !run.cancelled && yours.map { run.arriveAt.addingTimeInterval(60) < $0.arriveAt } == true
        return Button { Task { await model.takeRun(session.api, run) } } label: {
            HStack(spacing: 10) {
                Text(run.line).font(.geist(14, .bold)).foregroundStyle(.white)
                    .frame(minWidth: 30, minHeight: 26).padding(.horizontal, 4)
                    .background(run.color.map { Color(hex: UInt32($0.dropFirst(), radix: 16) ?? 0x6A6253) } ?? Palette.textSoft, in: .rect(cornerRadius: 7))
                VStack(alignment: .leading, spacing: 2) {
                    // Time, lateness and tag on one line when the column has room; otherwise they stack.
                    ViewThatFits(in: .horizontal) {
                        HStack(spacing: 6) { leaves(run); late(run); tag(mine: mine, faster: faster) }
                        VStack(alignment: .leading, spacing: 3) { HStack(spacing: 6) { leaves(run); late(run) }; tag(mine: mine, faster: faster) }
                        VStack(alignment: .leading, spacing: 3) { leaves(run); late(run); tag(mine: mine, faster: faster) }
                    }
                    Text(sub(run, gone: gone))
                        .font(.geist(12.5)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                }
                Spacer(minLength: 0)
            }
            .padding(.vertical, 6).padding(.horizontal, 6)
            .background(mine ? Palette.goodBackground : .clear, in: .rect(cornerRadius: 10))
            .opacity(gone || run.cancelled ? 0.45 : 1)
            .contentShape(.rect)
        }
        .buttonStyle(PressStyle())
        .disabled(gone || run.cancelled || mine)
        .accessibilityLabel("Tram \(run.line), leaves \(run.departTime)" + (run.delayMin.map { $0 > 0 ? ", \($0) minutes late" : "" } ?? "") + ", " + sub(run, gone: gone) + (mine ? ", your tram" : faster ? ", faster" : ""))
    }

    /// Under a tram: gone, or with a ride after it, whether it makes that one and how long the change
    /// is (the room a late tram has); without, when it gets them there.
    private func sub(_ run: ChangeBoard.Run, gone: Bool) -> String {
        if gone { return live.boarding ? "Leaves before you get to the stop" : "Leaves before you get there" }
        guard let connection = live.connection else { return "You’re there at \(run.arriveTime)" }
        guard board.makes(run, connection) else { return "Too late for the \(connection.line) at \(connection.departTime)" }
        let wait = max(0, Int(connection.latest.timeIntervalSince(run.arriveAt) / 60))
        return "There at \(connection.thereTime) · \(wait == 0 ? "just makes the \(connection.line)" : "\(wait) min to change")"
    }

    private func leaves(_ run: ChangeBoard.Run) -> some View {
        Text(run.cancelled ? "Cancelled" : "Leaves \(run.departTime)")
            .font(.geist(14.5, .semibold)).foregroundStyle(Palette.text).lineLimit(1).fixedSize()
    }

    @ViewBuilder private func late(_ run: ChangeBoard.Run) -> some View {
        if !run.cancelled, let late = run.delayMin, late > 0 {
            Text("\(late) min late").font(.geist(12.5, .semibold)).foregroundStyle(Palette.conflictText).lineLimit(1).fixedSize()
        }
    }

    @ViewBuilder private func tag(mine: Bool, faster: Bool) -> some View {
        if mine {
            Text("Your tram").font(.geist(11.5, .bold)).foregroundStyle(.white).lineLimit(1).fixedSize()
                .padding(.horizontal, 7).padding(.vertical, 3).background(Color(hex: 0x17603A), in: .rect(cornerRadius: 6))
        } else if faster {
            Text("Faster").font(.geist(11.5, .bold)).foregroundStyle(Palette.warnText).lineLimit(1).fixedSize()
                .padding(.horizontal, 7).padding(.vertical, 3).background(Palette.warnBackground, in: .rect(cornerRadius: 6))
        }
    }

    private nonisolated struct Line: Shape {
        func path(in rect: CGRect) -> Path {
            var path = Path()
            path.move(to: CGPoint(x: 0, y: rect.midY))
            path.addLine(to: CGPoint(x: rect.maxX, y: rect.midY))
            return path
        }
    }
}
