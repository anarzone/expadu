import API
import Core
import DesignSystem
import SwiftUI

/// The Documents tab: every document the tasks need, by task, filtered by task and by how far
/// along it is. A document goes still to get → you have it → ready (checked for this task); the
/// counts and the rings follow. No file is ever uploaded or sent.
struct PaperworkDocumentsView: View {
    let plan: PaperworkPlan
    let query: String
    @Binding var open: String?
    let openTask: (String) -> Void
    let web: (URL) -> Void
    @State private var task = "all"
    @State private var status = "any"

    var body: some View {
        let shown = plan.tasks.flatMap { process in plan.papers(for: process).map { (process, $0) } }
            .filter { query.isEmpty || PaperworkSearch.matches($0.1, query) }
        let byTask = shown.filter { task == "all" || $0.0.occurrenceKey == task }
        VStack(alignment: .leading, spacing: 18) {
            HStack(spacing: 16) {
                PaperworkFilterMenu(label: "Task", items: [("all", "All tasks", shown.count)] + plan.tasks.filter { !plan.papers(for: $0).isEmpty }.map { process in
                    (process.occurrenceKey, process.title, shown.filter { $0.0.occurrenceKey == process.occurrenceKey }.count)
                }, chosen: $task)
                PaperworkFilterMenu(label: "Status", items: [("any", "Any status", byTask.count)] + [("missing", "Still to get"), ("have", "You have it"), ("ready", "Ready")].map { key, title in
                    (key, title, byTask.filter { Self.status($0.1) == key }.count)
                }, chosen: $status)
                Spacer(minLength: 0)
            }
            .padding(.horizontal, 4)
            let groups = plan.tasks.filter { task == "all" || $0.occurrenceKey == task }.compactMap { process -> (PaperworkProcess, [PaperworkRequirement])? in
                let list = byTask.filter { $0.0.occurrenceKey == process.occurrenceKey && (status == "any" || Self.status($0.1) == status) }.map(\.1)
                return list.isEmpty ? nil : (process, list)
            }
            if groups.isEmpty {
                PaperworkNone(title: "No documents with this status", action: "Show all documents") {
                    withAnimation(.snappy) { task = "all"; status = "any" }
                }
            }
            ForEach(groups, id: \.0.occurrenceKey) { process, list in
                let required = plan.papers(for: process).filter(\.required)
                let look = PaperworkLook.topic(process.topic)
                VStack(alignment: .leading, spacing: 8) {
                    Button { openTask(process.occurrenceKey) } label: {
                        HStack(spacing: 10) {
                            PaperworkRing(value: required.isEmpty ? 0 : Double(required.filter(\.ready).count) / Double(required.count), color: look.color, line: 3) { EmptyView() }
                                .frame(width: 28, height: 28)
                            Text(process.title).font(.posterGrotesque(19)).foregroundStyle(Palette.text).multilineTextAlignment(.leading)
                            Spacer(minLength: 6)
                            Text("\(required.filter(\.ready).count)/\(required.count) ready").font(.geist(13.5, .semibold)).foregroundStyle(Palette.textSoft)
                                .contentTransition(.numericText())
                            Image(systemName: "chevron.right").font(.system(size: 12, weight: .bold)).foregroundStyle(Palette.textSoft)
                        }
                        .padding(.horizontal, 4).frame(minHeight: 44).contentShape(.rect)
                    }
                    .buttonStyle(PressStyle())
                    .accessibilityLabel("\(process.title), \(required.filter(\.ready).count) of \(required.count) ready. Open the task")
                    PaperworkList {
                        ForEach(Array(list.enumerated()), id: \.element.id) { index, requirement in
                            if index > 0 { PaperworkDivider() }
                            PaperworkDocRow(plan: plan, process: process, requirement: requirement, open: $open, web: web)
                        }
                    }
                }
                .transition(.opacity.combined(with: .offset(y: 10)))
            }
            VStack(alignment: .leading, spacing: 8) {
                Label("“Ready” means you have checked it for this task. No files are uploaded or sent.", systemImage: "info.circle")
                Label("Uploading, scanning, translating and letter writing aren’t available yet.", systemImage: "info.circle")
            }
            .font(.geist(13)).foregroundStyle(Palette.textSoft).labelStyle(Tight())
            .padding(.horizontal, 4)
        }
        .animation(Motion.ios(0.35), value: task)
        .animation(Motion.ios(0.35), value: status)
    }

    static func status(_ requirement: PaperworkRequirement) -> String {
        switch requirement.readiness {
        case "confirmed_for_use": "ready"
        case "reported_available", "needs_reconfirmation": "have"
        default: "missing"
        }
    }
}

/// One document: its name (and the German term), how far along it is, and, opened, what to do.
struct PaperworkDocRow: View {
    let plan: PaperworkPlan
    let process: PaperworkProcess
    let requirement: PaperworkRequirement
    @Binding var open: String?
    let web: (URL) -> Void
    @Environment(Session.self) private var session
    @Environment(PaperworkModel.self) private var model

    var body: some View {
        let state = PaperworkDocumentsView.status(requirement)
        let conditional = !requirement.required
        let expanded = open == rowID
        VStack(alignment: .leading, spacing: 0) {
            Button { withAnimation(Motion.ios(0.32)) { open = expanded ? nil : rowID } } label: {
                HStack(alignment: .top, spacing: 12) {
                    Image(systemName: state == "ready" ? "checkmark" : "doc.text")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundStyle(state == "ready" ? .white : state == "have" ? PaperworkLook.greenInk : PaperworkLook.purpleInk)
                        .frame(width: 32, height: 32)
                        .background(state == "ready" ? PaperworkLook.purple : state == "have" ? PaperworkLook.greenSoft : PaperworkLook.purpleSoft, in: .rect(cornerRadius: 9))
                        .contentTransition(.symbolEffect(.replace))
                    VStack(alignment: .leading, spacing: 2) {
                        Text(requirement.label).font(.geist(15, .semibold)).foregroundStyle(Palette.text).multilineTextAlignment(.leading)
                        if let term = requirement.terms?.first, !PaperworkWords.sameLabel(term.german, requirement.label) {
                            Text(term.english.map { "\($0) (\(term.german))" } ?? term.german).font(.geist(12.5)).foregroundStyle(Palette.text.opacity(0.7))
                        }
                        Text((PaperworkWords.readiness[requirement.readiness] ?? requirement.readiness) + (conditional ? " · May be needed" : ""))
                            .font(.geist(12.5, state == "missing" ? .regular : .semibold))
                            .foregroundStyle(state == "ready" ? PaperworkLook.purpleInk : state == "have" ? PaperworkLook.greenInk : Palette.textSoft)
                            .contentTransition(.opacity)
                    }
                    Spacer(minLength: 4)
                    Image(systemName: "chevron.down").font(.system(size: 12, weight: .bold)).foregroundStyle(Palette.textSoft)
                        .rotationEffect(.degrees(expanded ? 180 : 0)).padding(.top, 6)
                }
                .padding(.horizontal, 14).padding(.vertical, 12).frame(minHeight: 58)
                .contentShape(.rect)
            }
            .buttonStyle(.plain)
            .accessibilityHint(expanded ? "Hides what you can do" : "Shows what you can do")
            .overlay(alignment: .bottomTrailing) {
                if model.stamped == requirement.id {
                    PaperworkStamp(word: "Ready", ink: PaperworkLook.purple, tilt: -8, size: 14, round: 1)
                        .padding(.trailing, 44).padding(.bottom, 10)
                        .transition(.scale(scale: 1.6).combined(with: .opacity))
                        .allowsHitTesting(false)
                }
            }
            if expanded {
                VStack(alignment: .leading, spacing: 8) {
                    Text("\(conditional ? "Whether you need this depends on your situation. " : "")You’re updating your checklist for \(process.title). Nothing is uploaded or sent.")
                        .font(.geist(13.5)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                    actions(state: state, conditional: conditional)
                    if let url = officialURL {
                        Button { web(url) } label: {
                            Label("Check official source", systemImage: "arrow.up.right").labelStyle(TrailingIcon())
                                .font(.geist(13.5, .semibold)).foregroundStyle(TodayLink.ink).frame(minHeight: 40)
                        }
                        .buttonStyle(PressStyle())
                    }
                }
                .padding(.leading, 58).padding(.trailing, 14).padding(.bottom, 14)
                .transition(.opacity.combined(with: .offset(y: -6)))
            }
        }
        .animation(Motion.ios(0.35), value: requirement.readiness)
        .animation(.spring(duration: 0.45, bounce: 0.3), value: model.stamped)
    }

    private var rowID: String { process.occurrenceKey + requirement.id }

    @ViewBuilder
    private func actions(state: String, conditional: Bool) -> some View {
        let key = rowID
        if !process.tracked {
            PaperworkButton(title: "Start tracking this task", symbol: "plus", small: true, working: model.busy == key) {
                Task {
                    await model.run(key, session.api, done: "Tracking started. Nothing has been submitted.") { () async throws(APIFailure) in
                        try await session.api.track(process, person: plan.personId, jurisdiction: plan.jurisdiction)
                    }
                }
            }
        } else {
            HStack(spacing: 8) {
                if state == "missing" {
                    PaperworkButton(title: "I have this", symbol: "doc.text", small: true, working: model.busy == key) {
                        Task {
                            await model.run(key, session.api, done: "You have it. Mark it ready after checking it for this task.") { () async throws(APIFailure) in
                                try await session.api.have(requirement, existing: plan.evidence(for: requirement), person: plan.personId)
                            }
                        }
                    }
                } else if state == "have", !conditional, let evidence = evidence {
                    PaperworkButton(title: "Mark as ready", symbol: "checkmark", small: true, working: model.busy == key) {
                        Task {
                            let done = await model.run(key, session.api, done: "Marked ready for this task. Nothing was sent.") { () async throws(APIFailure) in
                                try await session.api.confirm(requirement, evidence: evidence, on: process)
                            }
                            if done { model.stamp(requirement.id) }
                        }
                    }
                } else if state == "ready" {
                    Label("Marked ready by you", systemImage: "checkmark").labelStyle(Tight())
                        .font(.geist(14, .semibold)).foregroundStyle(PaperworkLook.purpleInk).frame(minHeight: 40)
                }
                if state != "missing" {
                    PaperworkButton(title: "I don’t have it", plain: true, small: true, working: model.busy == key + "-not") {
                        Task {
                            await model.run(key + "-not", session.api, done: "Marked as not ready.") { () async throws(APIFailure) in
                                if requirement.evidenceId != nil { try await session.api.unconfirm(requirement, on: process) }
                                let holders = (plan.paperwork?.evidence ?? []).filter { $0.details.requirementRefs?.contains(requirement.id) == true }
                                try await session.api.release(requirement, holders: holders, person: plan.personId)
                            }
                        }
                    }
                }
            }
        }
    }

    /// The record a confirmation points at: the one linked, or the one suggested.
    private var evidence: PaperworkEvidence? {
        let all = plan.paperwork?.evidence ?? []
        let id = requirement.evidenceId ?? requirement.suggestedEvidenceIds?.first
        return id.flatMap { id in all.first { $0.id == id } } ?? plan.evidence(for: requirement)
    }

    private var officialURL: URL? {
        process.steps.lazy.compactMap { $0.sources?.official?.first?.url }.first.flatMap(URL.init(string:))
    }
}

/// An honest empty line with a way back (.pw-none).
struct PaperworkNone: View {
    let title: String
    var note: String?
    let action: String
    let run: () -> Void

    var body: some View {
        VStack(spacing: 8) {
            Text(title).font(.geist(16, .semibold)).foregroundStyle(Palette.text).multilineTextAlignment(.center)
            if let note { Text(note).font(.geist(13.5)).foregroundStyle(Palette.textSoft).multilineTextAlignment(.center) }
            PaperworkButton(title: action, quiet: true, small: true, action: run).padding(.top, 4)
        }
        .frame(maxWidth: .infinity).padding(.vertical, 22).padding(.horizontal, 16)
        .card(radius: 22)
    }
}

/// Search over the record's own words, English and German.
enum PaperworkSearch {
    static func matches(_ requirement: PaperworkRequirement, _ query: String) -> Bool {
        has(([requirement.label, requirement.note ?? ""] + (requirement.terms ?? []).flatMap { [$0.german, $0.english ?? ""] }).joined(separator: " "), query)
    }

    static func matches(_ process: PaperworkProcess, _ query: String) -> Bool {
        has(([process.title, process.topicLabel ?? "", process.blockingReason?.label ?? ""] + process.steps.map(\.title)).joined(separator: " "), query)
    }

    static func has(_ text: String, _ query: String) -> Bool {
        let words = query.lowercased().split(separator: " ")
        let hay = text.lowercased().folding(options: .diacriticInsensitive, locale: nil)
        return words.allSatisfy { hay.contains(String($0).folding(options: .diacriticInsensitive, locale: nil)) }
    }
}
