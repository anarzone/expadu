import API
import Core
import DesignSystem
import SwiftUI

/// The answers, on a sheet of paper over the wall: each question in large type, what Composer is
/// looking at while it works, then its words and cards, and what to ask next.
struct ComposerPaper: View {
    let ask: (String) -> Void
    @Environment(ComposerModel.self) private var model
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var position = ScrollPosition(edge: .bottom)

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                let exchanges = Self.exchanges(model.messages)
                ForEach(Array(exchanges.enumerated()), id: \.element.id) { index, exchange in
                    if index > 0 {
                        Line().stroke(Palette.border, style: StrokeStyle(lineWidth: 2, dash: [6, 5])).frame(height: 2)
                            .padding(.horizontal, -18).padding(.vertical, 22)
                    }
                    ExchangeView(exchange: exchange, last: index == exchanges.count - 1 && model.pending == nil, ask: ask)
                        .id(exchange.id)
                }
                if let pending = model.pending {
                    if !exchanges.isEmpty, exchanges.last?.question != pending.text {
                        Line().stroke(Palette.border, style: StrokeStyle(lineWidth: 2, dash: [6, 5])).frame(height: 2)
                            .padding(.horizontal, -18).padding(.vertical, 22)
                    }
                    VStack(alignment: .leading, spacing: 12) {
                        if exchanges.last?.question != pending.text {
                            Text(pending.text).font(.posterGrotesque(25)).tracking(-0.3).foregroundStyle(Palette.text)
                        }
                        HStack(spacing: 12) {
                            ProgressView()
                            Text(pending.words).font(.geist(15.5, .medium)).foregroundStyle(Palette.textSoft)
                                .contentTransition(.opacity)
                                .id(pending.words)
                                .transition(.opacity.combined(with: .offset(y: 6)))
                        }
                        .frame(minHeight: 52)
                        .animation(reduceMotion ? nil : Motion.ios(0.3), value: pending.words)
                    }
                    .id("pending")
                    .transition(.opacity.combined(with: .offset(y: 10)))
                }
                Color.clear.frame(height: 120)
            }
            .padding(.horizontal, 18).padding(.top, 22)
            .animation(reduceMotion ? nil : Motion.ios(0.4), value: model.messages.map(\.id))
        }
        .scrollPosition($position)
        .scrollIndicators(.hidden)
        .scrollDismissesKeyboard(.immediately)
        .background(Palette.canvas, in: UnevenRoundedRectangle(topLeadingRadius: 32, topTrailingRadius: 32, style: .continuous))
        .shadow(color: Color(hex: 0x19140C, opacity: 0.3), radius: 20, y: -10)
        .ignoresSafeArea(edges: .bottom)
        .onChange(of: model.messages.count) { withAnimation(Motion.ios(0.4)) { position.scrollTo(edge: .bottom) } }
        .onChange(of: model.pending) { withAnimation(Motion.ios(0.4)) { position.scrollTo(edge: .bottom) } }
    }

    struct Exchange: Identifiable {
        let id: String
        let question: String?
        var answers: [ComposerMessage]
    }

    /// Each question with what came back for it. A day's plan shows once, where it last changed.
    static func exchanges(_ messages: [ComposerMessage]) -> [Exchange] {
        var anchors: [String: String] = [:]
        for message in messages where message.kind == "plan" {
            if let date = message.date { anchors[date] = message.id }
        }
        var list: [Exchange] = []
        for message in messages where !(message.kind == "plan" && message.date.map { anchors[$0] != message.id } == true) {
            if message.mine {
                list.append(Exchange(id: message.id, question: message.text, answers: []))
            } else if list.isEmpty {
                list.append(Exchange(id: message.id, question: nil, answers: [message]))
            } else {
                list[list.count - 1].answers.append(message)
            }
        }
        return list
    }
}

/// One question and its answers.
private struct ExchangeView: View {
    let exchange: ComposerPaper.Exchange
    let last: Bool
    let ask: (String) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            if let question = exchange.question {
                Text(question).font(.posterGrotesque(25)).tracking(-0.3).foregroundStyle(Palette.text)
                    .fixedSize(horizontal: false, vertical: true)
                    .accessibilityAddTraits(.isHeader)
            }
            ForEach(exchange.answers) { answer in
                AnswerView(message: answer, question: exchange.question, last: last, ask: ask)
            }
        }
    }
}

/// Composer's answer: its words, its cards in order, what to ask next, and where it came from.
private struct AnswerView: View {
    let message: ComposerMessage
    let question: String?
    let last: Bool
    let ask: (String) -> Void
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            switch message.kind {
            case "failed":
                VStack(alignment: .leading, spacing: 8) {
                    Text(message.text.isEmpty ? "That didn’t go through." : message.text).font(.geist(16, .bold)).foregroundStyle(Palette.text)
                    if let question, last {
                        Button { Task { await model.retry(question, api: session.api, place: today.place, label: nil) } } label: {
                            Label("Ask again", systemImage: "arrow.counterclockwise").labelStyle(Tight())
                                .font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                                .padding(.horizontal, 14).frame(height: 40)
                                .buttonFace(Palette.quiet)
                        }
                        .buttonStyle(PressStyle())
                    }
                }
                .padding(14).frame(maxWidth: .infinity, alignment: .leading)
                .background(Palette.conflictBackground, in: .rect(cornerRadius: 18, style: .continuous))
            case "plan":
                if let plan = message.plan { PlanCard(plan: plan) }
            case "change":
                ChangeCard(message: message)
            case "undone":
                Label(message.text, systemImage: "arrow.uturn.backward").labelStyle(Tight())
                    .font(.geist(14.5, .semibold)).foregroundStyle(Palette.textSoft)
            default:
                let words = (message.lead?.isEmpty == false ? message.lead : nil) ?? (message.blocks.isEmpty ? message.text : nil)
                if let words, !words.isEmpty {
                    Text(words).font(.geist(17)).foregroundStyle(Palette.text).lineSpacing(3)
                        .fixedSize(horizontal: false, vertical: true)
                        .textSelection(.enabled)
                }
                ForEach(Array(message.blocks.enumerated()), id: \.offset) { _, block in
                    ComposerCard(block: block, showText: message.lead?.isEmpty != false, question: question)
                }
                if !message.suggestions.isEmpty, last {
                    FlowChips(suggestions: message.suggestions, ask: ask)
                }
                if !message.lookedAt.isEmpty {
                    Text("Composer looked at \(ListFormatter.localizedString(byJoining: message.lookedAt)).")
                        .font(.geist(12.5)).foregroundStyle(Palette.textSoft)
                }
            }
        }
    }
}

/// What to ask next, as tappable rows of words (no pills).
private struct FlowChips: View {
    let suggestions: [ComposerSuggestion]
    let ask: (String) -> Void
    @Environment(\.dismiss) private var dismiss
    @Environment(\.shell) private var shell

    var body: some View {
        ScrollView(.horizontal) {
            HStack(spacing: 7) {
                ForEach(suggestions, id: \.self) { suggestion in
                    Button {
                        if let prompt = suggestion.prompt { ask(prompt) } else if let tab = ComposerLinks.tab(suggestion.href) { dismiss(); shell.show(tab) }
                    } label: {
                        Text(suggestion.label).font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                            .padding(.horizontal, 14).frame(height: 40)
                            .buttonFace(Palette.quiet)
                    }
                    .buttonStyle(PressStyle())
                }
            }
            .padding(.vertical, 2)
        }
        .scrollIndicators(.hidden)
    }
}

/// The web's links in an answer, as the app's own places.
enum ComposerLinks {
    static func tab(_ href: String?) -> AppTab? {
        guard let href else { return nil }
        if href.hasPrefix("/paperwork") { return .paperwork }
        if href.hasPrefix("/travel") { return .travel }
        if href.hasPrefix("/today") || href.hasPrefix("/dashboard") { return .today }
        return nil
    }
}

/// A day's plan as Composer made it: each stop with its time.
private struct PlanCard: View {
    let plan: ComposerPlan

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack {
                Text(TodayPage.shortDay(plan.date)).font(.geist(16, .bold)).foregroundStyle(Palette.text)
                Spacer()
                if let origin = plan.originLabel { Text("From \(origin)").font(.geist(13)).foregroundStyle(Palette.textSoft) }
            }
            .padding(.horizontal, 14).padding(.vertical, 12)
            ForEach(plan.slots) { slot in
                PaperworkDivider()
                HStack(alignment: .top, spacing: 12) {
                    Text(slot.startTime ?? "").font(.geist(15, .bold)).monospacedDigit().foregroundStyle(Palette.text).frame(width: 48, alignment: .leading)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(slot.name).font(.geist(15.5, .semibold)).foregroundStyle(Palette.text)
                        Text([slot.label ?? slot.subtitle, slot.veedel, slot.durationLabel].compactMap { $0 }.joined(separator: " · "))
                            .font(.geist(13)).foregroundStyle(Palette.textSoft)
                        if let travel = slot.travelMinFromPrevious, travel > 0 {
                            Label("\(travel) min to get there", systemImage: "figure.walk").labelStyle(Tight())
                                .font(.geist(12.5)).foregroundStyle(Palette.textSoft)
                        }
                    }
                    Spacer(minLength: 0)
                }
                .padding(.horizontal, 14).padding(.vertical, 11)
            }
            Text("Walking times are straight-line estimates. Check opening hours on the day.")
                .font(.geist(12.5)).foregroundStyle(Palette.textSoft).padding(14)
        }
        .card(radius: 22)
    }
}

/// What a change did, with its rows, and Undo while it can still be undone.
private struct ChangeCard: View {
    let message: ComposerMessage
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session

    var body: some View {
        let record = model.workspace?.changes.first { $0.id == message.changeId }
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: record?.undoneAt != nil ? "arrow.uturn.backward" : "checkmark").font(.system(size: 13, weight: .bold))
                .foregroundStyle(record?.undoneAt != nil ? Palette.textSoft : Color(hex: 0x17603A))
                .frame(width: 24, height: 24)
                .background(record?.undoneAt != nil ? Palette.quiet : Color(light: 0xE4F4E8, dark: 0x1F3A28), in: .circle)
            VStack(alignment: .leading, spacing: 6) {
                if !message.text.isEmpty {
                    Text(message.text).font(.geist(15.5, .bold)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                }
                ForEach(message.rows ?? [], id: \.self) { row in
                    HStack(alignment: .firstTextBaseline, spacing: 8) {
                        Text(Self.word(row.kind)).font(.geist(12.5, .bold)).foregroundStyle(row.kind == "removed" ? Palette.conflictText : Color(hex: 0x17603A))
                            .padding(.horizontal, 7).padding(.vertical, 2)
                            .background(row.kind == "removed" ? Palette.conflictBackground : Color(light: 0xE4F4E8, dark: 0x1F3A28), in: .rect(cornerRadius: 6, style: .continuous))
                        Text(row.title + (row.from != nil && row.to != nil ? ", \(row.from!) → \(row.to!)" : row.to.map { ", \($0)" } ?? ""))
                            .font(.geist(14.5)).foregroundStyle(Palette.text)
                    }
                }
            }
            Spacer(minLength: 0)
            if let record, record.undoable, record.undoneAt == nil {
                PaperworkButton(title: "Undo", quiet: true, small: true, working: model.busy == "undo-change-\(record.id)") {
                    Task { await model.undoChange(record.id, api: session.api) }
                }
                .transition(.opacity)
            }
        }
        .padding(14).frame(maxWidth: .infinity, alignment: .leading)
        .card(radius: 18)
        .animation(Motion.ios(0.3), value: record?.undoneAt)
    }

    static func word(_ kind: String) -> String {
        switch kind {
        case "moved": "Moved"
        case "added": "Added"
        case "removed": "Removed"
        case "replaced": "Swapped"
        default: kind.capitalized
        }
    }
}
