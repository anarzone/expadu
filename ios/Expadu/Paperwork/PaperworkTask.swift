import API
import Core
import DesignSystem
import SwiftUI

/// A task's own page (the canvas's .pw-page): its ring and stamp, its steps to tick and read,
/// what the task leans on, the appointment, what to bring and the submission. The next thing to do
/// floats in a glass pill. A tick is your checklist only; nothing is sent or submitted.
struct PaperworkTaskPage: View {
    let open: PaperworkOpen
    @Binding var sheet: PaperworkSheet?
    @Environment(Session.self) private var session
    @Environment(\.shell) private var shell
    @Environment(\.dismiss) private var dismiss
    @Environment(PaperworkModel.self) private var model
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var step: String?
    @State private var about: String?
    @State private var doc: String?
    @State private var bar = false
    @State private var stampRound = 0

    var body: some View {
        if let plan = model.plan, let process = plan.process(open.key) {
            page(plan, process)
        } else {
            VStack(spacing: 12) {
                Text("This task is unavailable").font(.posterGrotesque(24)).foregroundStyle(Palette.text)
                Text("Choose another task from your overview.").font(.geist(15)).foregroundStyle(Palette.textSoft)
                PaperworkButton(title: "Back to Paperwork", quiet: true, small: true) { dismiss() }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity).background(Palette.canvas)
        }
    }

    private func page(_ plan: PaperworkPlan, _ process: PaperworkProcess) -> some View {
        let look = PaperworkLook.topic(process.topic)
        let stamp = PaperworkLook.stamp(process)
        // A task kept in their history is read here, never changed.
        let current = plan.processes.contains { $0.occurrenceKey == process.occurrenceKey }
        let editable = current && process.tracked && process.isClosed != true
        return ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                HStack(alignment: .center, spacing: 16) {
                    PaperworkRing(value: process.steps.isEmpty ? 0 : Double(process.done) / Double(process.steps.count), color: look.color, line: 6) {
                        VStack(spacing: 1) {
                            Text("\(process.done)").font(.posterGrotesque(24)).foregroundStyle(Palette.text).contentTransition(.numericText())
                            Text("of \(process.steps.count)").font(.geist(11, .semibold)).foregroundStyle(Palette.textSoft)
                        }
                    }
                    .frame(width: 74, height: 74)
                    VStack(alignment: .leading, spacing: 8) {
                        Text(process.title).font(.posterGrotesque(32)).tracking(-0.4).foregroundStyle(Palette.text)
                            .fixedSize(horizontal: false, vertical: true).accessibilityAddTraits(.isHeader)
                        PaperworkStamp(word: stamp.word, ink: stamp.ink, tilt: stamp.tilt, struck: stamp.struck, size: 18, round: stampRound)
                    }
                }
                .padding(.horizontal, 2).padding(.top, 118)
                if current { banners(plan, process) } else { banner("clock.arrow.circlepath", "This task is in your history. It’s kept as you left it.") }
                head("Steps", "\(process.done)/\(process.steps.count) done")
                PaperworkList {
                    ForEach(Array(process.steps.enumerated()), id: \.element.id) { index, item in
                        if index > 0 { PaperworkDivider() }
                        stepRow(plan, process, item, editable: editable)
                    }
                }
                forTask(plan, process)
                if editable { appointment(plan, process) }
                let papers = plan.papers(for: process)
                if !papers.isEmpty {
                    let required = papers.filter(\.required)
                    head("What to bring", required.isEmpty ? nil : "\(required.filter(\.ready).count) of \(required.count) marked ready")
                        .id("bring")
                    PaperworkList {
                        ForEach(Array(papers.enumerated()), id: \.element.id) { index, requirement in
                            if index > 0 { PaperworkDivider() }
                            PaperworkDocRow(plan: plan, process: process, requirement: requirement, open: $doc, web: { sheet = .web($0) })
                        }
                    }
                }
                if editable { submission(plan, process) }
                Text("Whether a rule applies to you, and any legal date, comes from the authority.")
                    .font(.geist(12.5)).foregroundStyle(Palette.textSoft).frame(maxWidth: .infinity).multilineTextAlignment(.center)
                    .padding(.top, 8)
            }
            .padding(.horizontal, 16).padding(.bottom, 140)
            .animation(reduceMotion ? nil : Motion.ios(0.35), value: process)
        }
        .scrollIndicators(.hidden)
        .scrollEdgeEffectHidden(true, for: .top)
        .ignoresSafeArea(edges: .top)
        .background(Palette.canvas.ignoresSafeArea())
        .onScrollGeometryChange(for: Bool.self) { $0.contentOffset.y + $0.contentInsets.top > 110 } action: { _, on in
            withAnimation(Motion.ios(0.25)) { bar = on }
        }
        .overlay(alignment: .top) {
            if bar {
                Text(process.title).font(.geist(17, .semibold)).foregroundStyle(Palette.text).lineLimit(1)
                    .padding(.horizontal, 70).padding(.top, 14)
                    .frame(maxWidth: .infinity).frame(height: 72)
                    .background(.ultraThinMaterial)
                    .overlay(alignment: .bottom) { Rectangle().fill(Palette.border).frame(height: 0.5) }
                    .transition(.offset(y: -20).combined(with: .opacity))
                    .ignoresSafeArea(edges: .top)
                    .allowsHitTesting(false)
            }
        }
        .overlay(alignment: .bottom) { if current { pill(plan, process) } }
        .navigationBarBackButtonHidden(true)
        .toolbarVisibility(.visible, for: .navigationBar)
        .toolbarBackgroundVisibility(.hidden, for: .navigationBar)
        .toolbar { toolbar(process, editable: editable) }
        .onAppear {
            if step == nil { step = open.step ?? process.steps.first { !$0.done }?.id ?? process.steps.first?.id }
        }
        .onChange(of: process.workflow) { stampRound += 1 }
    }

    // MARK: Top

    @ToolbarContentBuilder
    private func toolbar(_ process: PaperworkProcess, editable: Bool) -> some ToolbarContent {
        ToolbarItem(placement: .topBarLeading) {
            Button { dismiss() } label: {
                Image(systemName: "chevron.left").font(.system(size: 17, weight: .semibold)).foregroundStyle(Palette.text)
            }
            .buttonStyle(RaisedStyle(fill: Palette.surface, size: 44))
            .accessibilityLabel("Back to Paperwork")
        }
        .sharedBackgroundVisibility(.hidden)
        if editable, let options = process.progressOptions?.filter({ $0.allowed != false }), !options.isEmpty {
            ToolbarItem(placement: .topBarTrailing) {
                Menu {
                    Section("Update progress") {
                        ForEach(options, id: \.event) { option in
                            Button(PaperworkWords.change[option.event] ?? option.event) {
                                // A report that carries its day or a note asks for them first, as the web's form does.
                                if option.date != nil || option.note == true { sheet = .progress(process.occurrenceKey, option.event) } else { report(option.event, on: process) }
                            }
                        }
                    }
                } label: {
                    Image(systemName: "ellipsis").font(.system(size: 17, weight: .semibold)).foregroundStyle(Palette.text)
                        .frame(width: 44, height: 44)
                        .background(Palette.surface, in: .circle).raised(Circle())
                }
                .accessibilityLabel("Update progress")
            }
            .sharedBackgroundVisibility(.hidden)
        }
    }

    private func report(_ event: String, on process: PaperworkProcess) {
        Task {
            await model.run("report", session.api, done: "Progress saved. Nothing was sent to the office.") { () async throws(APIFailure) in
                try await session.api.record(event, on: process)
            }
        }
    }

    @ViewBuilder
    private func banners(_ plan: PaperworkPlan, _ process: PaperworkProcess) -> some View {
        if !process.tracked {
            banner("info.circle", "Look around first. Start tracking when you want to save your progress.")
        } else if process.guidanceState == "review_required" {
            VStack(alignment: .leading, spacing: 10) {
                Text("The guidance for this task changed.").font(.geist(15.5, .semibold)).foregroundStyle(Palette.text)
                Text("Read the updated steps below. Your recorded progress stays; confirm to keep going with the new steps.")
                    .font(.geist(14)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                PaperworkButton(title: "Use the updated steps", small: true, working: model.busy == "review") {
                    Task {
                        await model.run("review", session.api, done: "Updated steps confirmed. Your progress is kept.") { () async throws(APIFailure) in
                            try await session.api.review(process)
                        }
                    }
                }
            }
            .padding(16).background(PaperworkLook.warnBack, in: .rect(cornerRadius: 20))
        } else if process.untrackable == true {
            HStack(spacing: 10) {
                Image(systemName: "info.circle").font(.system(size: 14, weight: .semibold))
                Text("Tracking started. Nothing is reported yet.").font(.geist(14, .semibold))
                Spacer(minLength: 4)
                Button("Undo") {
                    Task {
                        await model.run("untrack", session.api, done: "Tracking undone. Nothing was kept for this task.") { () async throws(APIFailure) in
                            try await session.api.record("process_untracked", on: process, reviewed: false)
                        }
                    }
                }
                .font(.geist(14, .semibold)).foregroundStyle(TodayLink.ink).frame(minHeight: 40)
            }
            .foregroundStyle(PaperworkLook.greenInk)
            .padding(.horizontal, 14).frame(minHeight: 44)
            .background(PaperworkLook.greenSoft, in: .rect(cornerRadius: 16))
        }
        if let note = process.state.report?.note {
            banner(process.workflow == "waiting_authority" ? "clock" : "info.circle", note)
        }
    }

    private func banner(_ symbol: String, _ text: String) -> some View {
        Label(text, systemImage: symbol).labelStyle(Tight())
            .font(.geist(14.5, .medium)).foregroundStyle(Palette.text)
            .padding(.horizontal, 14).padding(.vertical, 12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Palette.quiet, in: .rect(cornerRadius: 16))
    }

    private func head(_ title: String, _ count: String?) -> some View {
        HStack(alignment: .firstTextBaseline, spacing: 8) {
            Text(title).font(.posterGrotesque(21)).foregroundStyle(Palette.text).accessibilityAddTraits(.isHeader)
            if let count { Text(count).font(.geist(14, .semibold)).foregroundStyle(Palette.textSoft).contentTransition(.numericText()) }
        }
        .padding(.horizontal, 4).padding(.top, 12)
    }

    // MARK: Steps

    private func stepRow(_ plan: PaperworkPlan, _ process: PaperworkProcess, _ item: PaperworkStep, editable: Bool) -> some View {
        let expanded = step == item.id
        let blocked = item.status == "blocked"
        return VStack(alignment: .leading, spacing: 0) {
            HStack(alignment: .top, spacing: 6) {
                Button { toggle(item, on: process) } label: {
                    ZStack {
                        Circle().strokeBorder(item.done ? PaperworkLook.green : Palette.textSoft.opacity(0.6), lineWidth: 2)
                            .background(Circle().fill(item.done ? PaperworkLook.green : .clear))
                            .frame(width: 26, height: 26)
                        Image(systemName: "checkmark").font(.system(size: 12, weight: .heavy)).foregroundStyle(.white)
                            .scaleEffect(item.done ? 1 : 0.4).opacity(item.done ? 1 : 0)
                    }
                    .frame(width: 44, height: 44).contentShape(.circle)
                    .animation(.spring(duration: 0.35, bounce: 0.3), value: item.done)
                }
                .buttonStyle(PressStyle())
                .disabled(!editable || model.busy != nil)
                .accessibilityLabel((item.done ? "Reopen: " : "Mark done: ") + item.title)
                .sensoryFeedback(.success, trigger: item.done)
                Button { withAnimation(Motion.ios(0.32)) { step = expanded ? nil : item.id; about = nil } } label: {
                    HStack(alignment: .top) {
                        VStack(alignment: .leading, spacing: 3) {
                            Text(item.title).font(.geist(15.5, .semibold)).foregroundStyle(item.done ? Palette.textSoft : Palette.text)
                                .strikethrough(item.done, color: Palette.textSoft.opacity(0.5)).multilineTextAlignment(.leading)
                            Text(stepWords(item, process)).font(.geist(13, blocked ? .semibold : .regular))
                                .foregroundStyle(blocked ? PaperworkLook.red : Palette.textSoft)
                        }
                        Spacer(minLength: 4)
                        Image(systemName: "chevron.down").font(.system(size: 12, weight: .bold)).foregroundStyle(Palette.textSoft)
                            .rotationEffect(.degrees(expanded ? 180 : 0)).padding(.top, 4)
                    }
                    .padding(.top, 10).padding(.bottom, 8).contentShape(.rect)
                }
                .buttonStyle(.plain)
            }
            .padding(.leading, 6).padding(.trailing, 12).padding(.vertical, 6)
            if expanded { stepBody(plan, process, item, editable: editable).transition(.opacity.combined(with: .offset(y: -6))) }
        }
    }

    private func stepWords(_ item: PaperworkStep, _ process: PaperworkProcess) -> String {
        let base = PaperworkWords.stepStatus[item.status] ?? item.status
        if item.status == "blocked", let reason = process.blockingReason?.label { return "\(base) · \(reason)" }
        if item.status == "waiting" { return "Waiting for a reply" }
        return base
    }

    private func stepBody(_ plan: PaperworkPlan, _ process: PaperworkProcess, _ item: PaperworkStep, editable: Bool) -> some View {
        let due = item.done ? nil : item.dates?.first { $0.day != nil }
        let submission = plan.rows(of: process).first { $0.kind == "submission_recorded" }
        let note = process.state.report?.note
        let lead: String = if item.done {
            "You marked this step done. You can review it or change that below."
        } else if item.status == "waiting" {
            "This task is with the office. Review your checklist or update your progress when something changes."
        } else if let submission, item.stepId.hasSuffix("submit.complete") {
            "You recorded your submission on \(PaperworkWords.date(submission.date)). The task waits for the office; mark this step finished when you hear back."
        } else {
            (process.workflow == "blocked" ? "You reported a hold-up\(note.map { ": \($0.prefix(1).lowercased() + $0.dropFirst())" } ?? ""). " : "")
                + (item.hasPapers ? "Check what you have. Mark each document ready once you’ve checked it for this task." : "Read the official information, then tick this step when you’ve done it.")
        }
        let official = item.sources?.official?.first.flatMap { URL(string: $0.url) }
        return VStack(alignment: .leading, spacing: 10) {
            if let due, let day = due.day {
                let soon = PaperworkDay.days(day) <= 3 || due.overdue == true
                Label("\(PaperworkWords.kind[due.kind] ?? "Date") · \(PaperworkDay.near(day))\(due.overdue == true ? " · overdue" : "")", systemImage: "calendar")
                    .labelStyle(Tight()).font(.geist(13.5, .semibold))
                    .foregroundStyle(soon ? Color(light: 0xA25E00, dark: 0xF3C66C) : PaperworkLook.apptInk)
            }
            Text(lead).font(.geist(15)).foregroundStyle(Palette.text).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
            HStack(spacing: 8) {
                if item.hasPapers {
                    PaperworkButton(title: "Documents · \(item.requirements?.ready ?? 0)/\(item.requirements?.total ?? 0)", symbol: "doc.text", small: true, grow: true) {
                        sheet = .documents(process.occurrenceKey)
                    }
                } else if let official {
                    PaperworkButton(title: "Official page", symbol: "arrow.up.right", small: true, grow: true) { sheet = .web(official) }
                }
                PaperworkButton(title: "Help", symbol: "sparkle", quiet: true, small: true) { shell.openComposer() }
                    .accessibilityLabel("Help with this step")
            }
            if let open = item.firstOpenRequirement, !item.done {
                HStack(alignment: .top, spacing: 10) {
                    Image(systemName: "doc.text").font(.system(size: 13, weight: .semibold)).foregroundStyle(PaperworkLook.purpleInk).padding(.top, 2)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(open.conditional == true ? "May be needed · check this document" : "First document to check").font(.geist(12.5)).foregroundStyle(Palette.textSoft)
                        Text(open.label).font(.geist(14, .semibold)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                    }
                }
                .padding(12).frame(maxWidth: .infinity, alignment: .leading)
                .background(PaperworkLook.purpleSoft.opacity(0.6), in: .rect(cornerRadius: 14))
            }
            if let description = item.description, !description.isEmpty {
                let shown = about == item.id
                Button { withAnimation(Motion.ios(0.3)) { about = shown ? nil : item.id } } label: {
                    HStack(spacing: 6) {
                        Text("About this step")
                        Image(systemName: "chevron.down").font(.system(size: 12, weight: .bold)).rotationEffect(.degrees(shown ? 180 : 0))
                    }
                    .font(.geist(14, .semibold)).foregroundStyle(Palette.textSoft).frame(minHeight: 36).contentShape(.rect)
                }
                .buttonStyle(.plain)
                if shown {
                    VStack(alignment: .leading, spacing: 8) {
                        ForEach(PaperworkWords.paragraphs(description), id: \.self) { line in
                            Text(line).font(.geist(14)).foregroundStyle(Palette.text).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
                        }
                        let legal = (item.sources?.legal ?? []).map(\.label).prefix(3).joined(separator: ", ")
                        Label("Checked against the official pages\(item.verifiedAt.map { " on \(PaperworkWords.date($0))" } ?? "")\(legal.isEmpty ? "" : " · \(legal)")",
                              systemImage: "checkmark")
                            .labelStyle(Tight()).font(.geist(12.5, .semibold)).foregroundStyle(PaperworkLook.greenInk)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                    .padding(.horizontal, 14).padding(.vertical, 12)
                    .background(Palette.quiet, in: .rect(cornerRadius: 16))
                    .transition(.opacity.combined(with: .offset(y: -6)))
                }
            }
            if editable {
                Button { toggle(item, on: process) } label: {
                    HStack(spacing: 8) {
                        if model.busy == "step" + item.id { ProgressView().controlSize(.small) } else {
                            Image(systemName: item.done ? "arrow.uturn.backward" : "checkmark").font(.system(size: 15, weight: .semibold))
                        }
                        Text(item.done ? "Reopen step" : "I’ve finished this step").font(.geist(15, .semibold))
                    }
                    .foregroundStyle(item.done ? Palette.text : PaperworkLook.greenInk)
                    .frame(maxWidth: .infinity).frame(height: 44)
                    .background(item.done ? Palette.quiet : PaperworkLook.greenSoft, in: .rect(cornerRadius: 12, style: .continuous))
                }
                .buttonStyle(PressStyle())
                .disabled(model.busy != nil)
                Label("Your checklist only. Nothing is sent or submitted.", systemImage: "info.circle").labelStyle(Tight())
                    .font(.geist(12.5)).foregroundStyle(Palette.textSoft)
            }
        }
        .padding(.leading, 56).padding(.trailing, 14).padding(.bottom, 16)
    }

    private func toggle(_ item: PaperworkStep, on process: PaperworkProcess) {
        let next = process.steps.first { $0.id != item.id && !$0.done }
        Task {
            let done = await model.run("step" + item.id, session.api,
                                       done: item.done ? "Step reopened. Nothing was sent." : next.map { "Step done. Next: \($0.title)." } ?? "All steps done. Update the progress when the office confirms.") { () async throws(APIFailure) in
                try await session.api.record(item.done ? "step_reopened" : "step_completed", on: process, payload: ["step_id": item.stepId])
            }
            if done, !item.done { withAnimation(Motion.ios(0.32)) { step = next?.id } }
        }
    }

    // MARK: For this task

    private func forTask(_ plan: PaperworkPlan, _ process: PaperworkProcess) -> some View {
        let verified = process.steps.first?.verifiedAt
        let rows = plan.rows(of: process)
        let unknown = rows.first { $0.state == "date_unknown" }
        let visa = plan.timeline.first { $0.kind == "document_expiry" && $0.document == "visa" }
        let leansOnVisa = process.steps.contains { $0.dates?.contains { $0.anchorFact == "visa_expires_at" } == true }
        return VStack(alignment: .leading, spacing: 10) {
            head("For this task", nil)
            PaperworkList {
                Button { sheet = .sources(process.definitionId) } label: {
                    PaperworkRow(symbol: "info", tint: Color(light: 0x005B6B, dark: 0x5CCBE0), back: Palette.selectBackground, title: "Official information",
                                 note: verified.map { "Checked against the official pages on \(PaperworkWords.date($0))" } ?? "Current guidance needs checking")
                }
                .buttonStyle(.plain)
                if let unknown {
                    PaperworkDivider()
                    Button { sheet = .situation } label: {
                        PaperworkRow(symbol: "calendar", tint: PaperworkLook.warnInk, back: PaperworkLook.warnBack, title: "Target date",
                                     note: "A date to aim for. It needs \(PaperworkWords.fact[unknown.neededFact ?? ""] ?? "more information"); it isn’t a confirmed legal deadline.")
                    }
                    .buttonStyle(.plain)
                }
                if let visa, leansOnVisa {
                    let urgent = visa.day.map { PaperworkDay.days($0) <= 1 } ?? false
                    PaperworkDivider()
                    Button { sheet = .situation } label: {
                        PaperworkRow(symbol: "calendar", tint: PaperworkLook.red, back: PaperworkLook.blockBack, title: "Visa expiry",
                                     note: visa.day.map { "\(PaperworkDay.weekday($0)) \(PaperworkWords.date(visa.date))" } ?? "Date unknown",
                                     noteInk: urgent ? PaperworkLook.red : Palette.textSoft, end: "pencil")
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    // MARK: Appointment and submission

    private func appointment(_ plan: PaperworkPlan, _ process: PaperworkProcess) -> some View {
        let row = plan.rows(of: process).first { $0.kind == "appointment" }
        return VStack(alignment: .leading, spacing: 10) {
            head("Your appointment", nil)
            PaperworkList {
                if let row, let day = row.day {
                    HStack(spacing: 12) {
                        Image(systemName: "clock").font(.system(size: 14, weight: .semibold)).foregroundStyle(PaperworkLook.apptInk)
                            .frame(width: 34, height: 34).background(PaperworkLook.apptBack, in: .circle)
                        VStack(alignment: .leading, spacing: 2) {
                            Text("\(PaperworkDay.near(day))\(PaperworkDay.clock(row.startsAt).map { ", \($0)" } ?? "")").font(.geist(15.5, .semibold)).foregroundStyle(Palette.text)
                            Text([row.location?.label ?? "Place not recorded", row.durationMinutes.map { "\($0) min" }].compactMap { $0 }.joined(separator: " · "))
                                .font(.geist(13)).foregroundStyle(Palette.textSoft)
                        }
                        Spacer(minLength: 4)
                        if PaperworkDay.days(day) == 0 {
                            Button("On Today") { shell.show(.today) }
                                .font(.geist(14, .semibold)).foregroundStyle(TodayLink.ink).frame(minHeight: 44)
                        }
                        Button { sheet = .appointment(process.occurrenceKey) } label: {
                            Image(systemName: "pencil").font(.system(size: 14, weight: .semibold)).foregroundStyle(Palette.text)
                        }
                        .buttonStyle(RaisedStyle(fill: Palette.quiet, size: 36))
                        .accessibilityLabel("Edit appointment")
                    }
                    .padding(.horizontal, 14).padding(.vertical, 11)
                } else {
                    HStack(spacing: 12) {
                        Image(systemName: "calendar").font(.system(size: 14, weight: .semibold)).foregroundStyle(Palette.text)
                            .frame(width: 34, height: 34).background(Palette.quiet, in: .circle)
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Already have an appointment?").font(.geist(15.5, .semibold)).foregroundStyle(Palette.text)
                            Text("Keep its details here. Nothing is booked.").font(.geist(13)).foregroundStyle(Palette.textSoft)
                        }
                        Spacer(minLength: 4)
                        recordButton("Record it") { sheet = .appointment(process.occurrenceKey) }
                    }
                    .padding(.horizontal, 14).padding(.vertical, 11)
                }
            }
        }
    }

    private func submission(_ plan: PaperworkPlan, _ process: PaperworkProcess) -> some View {
        let row = plan.rows(of: process).first { $0.kind == "submission_recorded" }
        return VStack(alignment: .leading, spacing: 10) {
            head("Submission", nil)
            PaperworkList {
                HStack(spacing: 12) {
                    Image(systemName: row == nil ? "arrow.up.doc" : "checkmark").font(.system(size: 14, weight: .semibold))
                        .foregroundStyle(row == nil ? Palette.text : PaperworkLook.greenInk)
                        .frame(width: 34, height: 34).background(row == nil ? Palette.quiet : PaperworkLook.greenSoft, in: .circle)
                        .contentTransition(.symbolEffect(.replace))
                    VStack(alignment: .leading, spacing: 2) {
                        Text(row.map { "Submitted \(PaperworkWords.date($0.date))" } ?? "Not recorded").font(.geist(15.5, .semibold)).foregroundStyle(Palette.text)
                        Text(row == nil ? "When you hand it in, record it here." : "Keep the confirmation. Nothing was sent anywhere.")
                            .font(.geist(13)).foregroundStyle(Palette.textSoft)
                    }
                    Spacer(minLength: 4)
                    recordButton(row == nil ? "Record it" : "Change") { sheet = .submission(process.occurrenceKey) }
                }
                .padding(.horizontal, 14).padding(.vertical, 11)
            }
        }
    }

    private func recordButton(_ title: String, action: @escaping () -> Void) -> some View {
        PaperworkButton(title: title, plain: true, small: true, action: action)
    }

    // MARK: The pill

    /// The next thing to do, always on screen: start tracking, or finish the next step.
    @ViewBuilder
    private func pill(_ plan: PaperworkPlan, _ process: PaperworkProcess) -> some View {
        let next = process.steps.first { !$0.done }
        if !process.tracked {
            floating(title: "Look around first", note: "Nothing is saved yet") {
                PaperworkButton(title: "Start tracking", symbol: "plus", small: true, working: model.busy == "track") {
                    Task {
                        await model.run("track", session.api, done: "Tracking started. Nothing has been submitted.") { () async throws(APIFailure) in
                            try await session.api.track(process, person: plan.personId, jurisdiction: plan.jurisdiction)
                        }
                    }
                }
            }
        } else if process.isClosed != true, let next {
            floating(title: next.title, note: "Next step · \(process.done) of \(process.steps.count) done") {
                Button { toggle(next, on: process) } label: {
                    Group {
                        if model.busy == "step" + next.id { ProgressView().tint(.white) } else {
                            Image(systemName: "checkmark").font(.system(size: 17, weight: .bold)).foregroundStyle(.white)
                        }
                    }
                }
                .buttonStyle(RaisedStyle(fill: PaperworkLook.green, size: 48))
                .disabled(model.busy != nil)
                .accessibilityLabel("Mark done: \(next.title)")
            }
        }
    }

    private func floating<Action: View>(title: String, note: String, @ViewBuilder action: () -> Action) -> some View {
        HStack(spacing: 12) {
            VStack(alignment: .leading, spacing: 2) {
                Text(title).font(.geist(15, .semibold)).foregroundStyle(Palette.text).lineLimit(2)
                Text(note).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1)
            }
            Spacer(minLength: 6)
            action()
        }
        .padding(.leading, 20).padding(.trailing, 8).padding(.vertical, 8)
        .frame(minHeight: 64)
        .glassEffect(.regular, in: .rect(cornerRadius: 28, style: .continuous))
        .padding(.horizontal, 16).padding(.bottom, 62)
        .transition(.offset(y: 30).combined(with: .opacity))
    }
}
