import API
import Core
import DesignSystem
import SwiftUI

/// A Paperwork sheet's frame (.td-sheet.pws): title, line, close, and its body scrolling.
struct PaperworkSheetFrame<Content: View>: View {
    let title: String
    let subtitle: String
    var note: String?
    @ViewBuilder let content: Content
    @Environment(\.dismiss) private var dismiss
    @Environment(PaperworkModel.self) private var model
    @State private var headHeight: CGFloat = 0
    @State private var bodyHeight: CGFloat = 0

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 3) {
                    Text(title).font(.posterGrotesque(26)).foregroundStyle(Palette.text)
                    Text(subtitle).font(.geist(14)).foregroundStyle(Palette.textSoft)
                }
                Spacer()
                CloseButton { dismiss() }
            }
            .padding(.horizontal, 20).padding(.top, 22).padding(.bottom, 14)
            .onGeometryChange(for: CGFloat.self) { $0.size.height } action: { headHeight = $0 }
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    content
                    if let note {
                        Text(note).font(.geist(12.5)).foregroundStyle(Palette.textSoft).frame(maxWidth: .infinity).multilineTextAlignment(.center)
                            .padding(.top, 4)
                    }
                }
                .padding(.horizontal, 16).padding(.bottom, 10)
            }
            .scrollIndicators(.hidden)
            .onScrollGeometryChange(for: CGFloat.self) { $0.contentSize.height } action: { _, now in bodyHeight = now }
        }
        .background(Palette.canvas)
        // What just changed shows here too: the page's own line is behind the sheet.
        .overlay(alignment: .bottom) {
            if let note = model.note {
                PaperworkNoteBar(note: note).padding(.horizontal, 16).padding(.bottom, 12)
                    .transition(.offset(y: 30).combined(with: .opacity)).id(note.id)
            }
        }
        .animation(.spring(duration: 0.5, bounce: 0.3), value: model.note)
        .fittedSheet(headHeight + bodyHeight)
    }
}

// MARK: - Your situation

/// Their answers, each changeable in place; and their record: stop or resume, and where download
/// and delete happen (on the web, behind the password).
struct PaperworkSituationSheet: View {
    let web: (URL) -> Void
    @Environment(Session.self) private var session
    @Environment(PaperworkModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @State private var facts: PaperworkFacts?
    @State private var schema: [PaperworkFactDefinition] = []
    @State private var editing: PaperworkFactDefinition?
    @State private var problem: String?
    @State private var pending = false

    var body: some View {
        PaperworkSheetFrame(title: "Your situation", subtitle: "Your answers decide your steps and documents",
                            note: "Change an answer and your tasks update. Nothing is sent to any office.") {
            if let editing, let facts {
                PaperworkAnswerForm(question: editing.asQuestion, pending: pending, current: facts.values[editing.key]?.raw) { answer, state in
                    Task { await change(editing, answer, state, facts) }
                }
                if let problem { Text(problem).font(.geist(14, .semibold)).foregroundStyle(PaperworkLook.red) }
                PaperworkButton(title: "Back to your answers", quiet: true, grow: true) { withAnimation(Motion.ios(0.32)) { self.editing = nil; problem = nil } }
            } else if let facts {
                Text("Your answers").font(.posterGrotesque(19)).foregroundStyle(Palette.text).padding(.horizontal, 4)
                let keys = facts.states.keys.sorted { order($0) < order($1) }
                if keys.isEmpty {
                    Text("No answers yet.").font(.geist(14)).foregroundStyle(Palette.textSoft).padding(.horizontal, 4)
                } else {
                    PaperworkList {
                        ForEach(Array(keys.enumerated()), id: \.element) { index, key in
                            if index > 0 { PaperworkDivider() }
                            let definition = schema.first { $0.key == key }
                            Button { if let definition { withAnimation(Motion.ios(0.32)) { editing = definition } } } label: {
                                PaperworkRow(symbol: symbol(key), title: PaperworkWords.label(key), note: words(key, facts),
                                             noteInk: facts.states[key] == "conflict" || facts.states[key] == "needs_reconfirmation" ? PaperworkLook.red : Palette.textSoft,
                                             end: definition == nil ? nil : "chevron.right")
                            }
                            .buttonStyle(.plain)
                            .disabled(definition == nil)
                        }
                    }
                }
                Text("Your record").font(.posterGrotesque(19)).foregroundStyle(Palette.text).padding(.horizontal, 4).padding(.top, 6)
                PaperworkList {
                    Button { Task { await stop() } } label: {
                        PaperworkRow(symbol: "stop.circle", title: "Stop processing", note: "Keep the record, stop using it", end: model.busy == "stop" ? nil : "chevron.right")
                    }
                    .buttonStyle(.plain)
                    PaperworkDivider()
                    Button { web(session.api.site.appending(path: "paperwork")) } label: {
                        PaperworkRow(symbol: "arrow.down.doc", title: "Download or delete your record",
                                     note: "On expadu.com, after you confirm it’s you", end: "arrow.up.right")
                    }
                    .buttonStyle(.plain)
                }
            } else if let problem {
                PaperworkNone(title: problem, action: "Try again") { Task { await read() } }
            } else {
                ProgressView().frame(maxWidth: .infinity).padding(.top, 40)
            }
        }
        .animation(Motion.ios(0.32), value: editing)
        .task { await read() }
    }

    private func read() async {
        guard let person = model.plan?.personId else { return }
        problem = nil
        do {
            async let answers = session.api.facts(person: person)
            async let all = session.api.factSchema()
            facts = try await answers
            schema = (try? await all) ?? []
        } catch {
            problem = PaperworkModel.words(error)
        }
    }

    private func change(_ definition: PaperworkFactDefinition, _ answer: PaperworkAnswer, _ state: String, _ facts: PaperworkFacts) async {
        guard let person = model.plan?.personId else { return }
        pending = true
        defer { pending = false }
        do {
            try await session.api.changeFact(person: person, key: definition.key, value: answer, state: state, revision: facts.revision)
        } catch {
            problem = PaperworkModel.words(error)
            return
        }
        await read()
        await model.load(session.api)
        model.say("Saved. Your tasks and dates follow it.")
        withAnimation(Motion.ios(0.32)) { editing = nil }
    }

    private func stop() async {
        guard let person = model.plan?.personId else { return }
        let done = await model.run("stop", session.api, done: "Processing stopped. Your record is kept.") { () async throws(APIFailure) in
            try await session.api.setPaperworkStopped(true, person: person)
        }
        if done { dismiss() }
    }

    private func words(_ key: String, _ facts: PaperworkFacts) -> String {
        switch facts.states[key] {
        case "unknown": return "Not known yet"
        case "declined": return "Prefer not to say"
        case "not_applicable": return "Doesn’t apply"
        case "conflict": return "Two answers disagree · check it"
        case "needs_reconfirmation": return "Check this again"
        default:
            guard let value = facts.values[key] else { return "Not answered" }
            let type = schema.first { $0.key == key }?.type
            return type == "date" ? PaperworkWords.date(value.raw) : PaperworkWords.option(key, value.raw)
        }
    }

    private func symbol(_ key: String) -> String {
        if key.hasSuffix("_at") || key.hasSuffix("_since") { return "calendar" }
        if key.contains("title") || key.contains("permit") || key.contains("visa") { return "doc.text" }
        if key.contains("registration") || key.contains("housing") || key.contains("moved") { return "mappin" }
        return "info"
    }

    /// The web's own order; the rest after, by name.
    private func order(_ key: String) -> String {
        let index = PaperworkWords.order.firstIndex(of: key) ?? 999
        return String(format: "%04d", index) + key
    }
}

// MARK: - Where this comes from

struct PaperworkSourcesSheet: View {
    let only: String?
    let web: (URL) -> Void
    @Environment(PaperworkModel.self) private var model

    var body: some View {
        let plan = model.plan
        let ids = Set((plan?.processes ?? []).map(\.definitionId))
        let units = (plan?.coverage?.units ?? []).filter { unit in
            guard let id = unit.definitionId else { return false }
            return only.map { $0 == id } ?? ids.contains(id)
        }
        PaperworkSheetFrame(title: "Where this comes from", subtitle: "Checked against official sources",
                            note: "Whether a rule applies to you, and any legal date, comes from the authority.") {
            PaperworkList {
                PaperworkRow(symbol: "checkmark", tint: PaperworkLook.greenInk, back: PaperworkLook.greenSoft,
                             title: "Each step says when it was last checked", note: PaperworkWords.coverage[plan?.coverage?.state ?? ""], end: nil)
            }
            if units.isEmpty {
                Text("No sources to show for this yet.").font(.geist(14)).foregroundStyle(Palette.textSoft).padding(.horizontal, 4)
            } else {
                Text("Sources").font(.posterGrotesque(19)).foregroundStyle(Palette.text).padding(.horizontal, 4).padding(.top, 4)
                PaperworkList {
                    ForEach(Array(units.enumerated()), id: \.offset) { index, unit in
                        if index > 0 { PaperworkDivider() }
                        let url = unit.sourceUrls?.official?.first.flatMap(URL.init(string:))
                        Button { if let url { web(url) } } label: {
                            PaperworkRow(symbol: "building.columns", tint: Color(light: 0x005B6B, dark: 0x5CCBE0), back: Palette.selectBackground,
                                         title: unit.state == "unconfirmed" ? "Guidance not confirmed right now" : unit.title ?? "Guidance",
                                         note: [url?.host(), unit.verifiedAt.map { "checked \(PaperworkWords.date($0))" }, unit.state.flatMap { PaperworkWords.coverage[$0] }]
                                             .compactMap { $0 }.joined(separator: " · "),
                                         end: url == nil ? nil : "arrow.up.right")
                        }
                        .buttonStyle(.plain)
                        .disabled(url == nil)
                    }
                }
            }
        }
    }
}

// MARK: - History

struct PaperworkHistorySheet: View {
    let open: (String) -> Void
    @Environment(PaperworkModel.self) private var model

    var body: some View {
        let rows = (model.plan?.history ?? []) + (model.plan?.processes ?? []).filter { $0.isClosed == true }
        let count = model.plan?.historyCount ?? rows.count
        PaperworkSheetFrame(title: "Your history", subtitle: "\(count) \(count == 1 ? "record" : "records") in your own record",
                            note: "Records of your own reports. They don’t establish an office’s decision.") {
            if rows.isEmpty {
                Text("No records yet. Finished and cancelled tasks are kept here.").font(.geist(14)).foregroundStyle(Palette.textSoft).padding(.horizontal, 4)
            } else {
                PaperworkList {
                    ForEach(Array(rows.enumerated()), id: \.offset) { index, process in
                        if index > 0 { PaperworkDivider() }
                        Button { if process.tracked { open(process.occurrenceKey) } } label: {
                            PaperworkRow(symbol: process.workflow == "cancelled" ? "xmark" : "checkmark", tint: PaperworkLook.greenInk, back: PaperworkLook.greenSoft,
                                         title: process.title,
                                         note: (process.workflow == "cancelled" ? "Cancellation recorded" : "Completion recorded") + " · " + (process.closedOn.map { PaperworkWords.date($0) } ?? "date not given"),
                                         end: process.tracked ? "chevron.right" : nil)
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
    }
}

// MARK: - All steps

struct PaperworkStepsSheet: View {
    let open: (PaperworkOpen) -> Void
    @Environment(PaperworkModel.self) private var model
    @State private var filter = "all"

    var body: some View {
        let all = (model.plan?.tasks ?? []).flatMap { process in process.steps.map { (process, $0) } }
        let group = { (step: PaperworkStep) -> String in
            switch step.status {
            case "completed": "done"
            case "blocked": "blocked"
            case "waiting": "waiting"
            default: "todo"
            }
        }
        PaperworkSheetFrame(title: "All steps", subtitle: "\(all.count) steps across your tasks") {
            HStack {
                PaperworkFilterMenu(label: "Status", items: [("all", "All steps"), ("todo", "To do"), ("blocked", "Blocked"), ("waiting", "Waiting"), ("done", "Done")].map { key, title in
                    (key, title, all.filter { key == "all" || group($0.1) == key }.count)
                }, chosen: $filter)
                Spacer()
            }
            .padding(.horizontal, 4)
            let shown = all.filter { filter == "all" || group($0.1) == filter }
            if shown.isEmpty {
                Text("No steps with this status.").font(.geist(14)).foregroundStyle(Palette.textSoft).padding(.horizontal, 4)
            } else {
                PaperworkList {
                    ForEach(Array(shown.enumerated()), id: \.element.1.id) { index, pair in
                        if index > 0 { PaperworkDivider() }
                        let kind = group(pair.1)
                        Button { open(PaperworkOpen(key: pair.0.occurrenceKey, step: pair.1.id)) } label: {
                            PaperworkRow(symbol: kind == "done" ? "checkmark" : kind == "blocked" ? "exclamationmark.triangle" : kind == "waiting" ? "clock" : "doc.text",
                                         tint: kind == "done" ? PaperworkLook.greenInk : kind == "blocked" ? PaperworkLook.red : Palette.text,
                                         back: kind == "done" ? PaperworkLook.greenSoft : kind == "blocked" ? PaperworkLook.blockBack : Palette.quiet,
                                         title: pair.1.title, note: "\(pair.0.title) · \(PaperworkWords.stepStatus[pair.1.status] ?? pair.1.status)")
                        }
                        .buttonStyle(.plain)
                    }
                }
                .animation(Motion.ios(0.3), value: filter)
            }
        }
    }
}

// MARK: - What to bring

struct PaperworkBringSheet: View {
    let key: String
    let web: (URL) -> Void
    @Environment(PaperworkModel.self) private var model
    @State private var open: String?

    var body: some View {
        if let plan = model.plan, let process = plan.process(key) {
            let papers = plan.papers(for: process)
            let required = papers.filter(\.required)
            PaperworkSheetFrame(title: "What to bring", subtitle: "\(process.title) · \(required.filter(\.ready).count) of \(required.count) ready",
                                note: "“Ready” means you have checked it for this task. No files are uploaded or sent.") {
                PaperworkList {
                    ForEach(Array(papers.enumerated()), id: \.element.id) { index, requirement in
                        if index > 0 { PaperworkDivider() }
                        PaperworkDocRow(plan: plan, process: process, requirement: requirement, open: $open, web: web)
                    }
                }
            }
            .onAppear { open = open ?? papers.first { !$0.ready }.map { process.occurrenceKey + $0.id } }
        }
    }
}

// MARK: - Appointment

/// Keep an appointment's details on the task (appointment_recorded): nothing is booked.
struct PaperworkAppointmentSheet: View {
    let key: String
    @Environment(Session.self) private var session
    @Environment(PaperworkModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @State private var task: String = ""
    @State private var day = Date.now
    @State private var time = Calendar.current.date(bySettingHour: 10, minute: 0, second: 0, of: .now) ?? .now
    @State private var place = ""
    @State private var minutes = 0

    var body: some View {
        let plan = model.plan
        let tracked = (plan?.tasks ?? []).filter { $0.tracked && $0.isClosed != true }
        let process = plan?.process(task.isEmpty ? key : task)
        let existing = process.flatMap { p in plan?.rows(of: p).first { $0.kind == "appointment" } }
        PaperworkSheetFrame(title: existing == nil ? "Record an appointment" : "Your appointment",
                            subtitle: "It shows on this task and on its day. Nothing is booked.") {
            if tracked.count > 1 {
                Picker("Task", selection: $task) {
                    ForEach(tracked, id: \.occurrenceKey) { Text($0.title).tag($0.occurrenceKey) }
                }
                .pickerStyle(.menu).tint(Palette.text)
                .padding(.horizontal, 8).frame(minHeight: 52).frame(maxWidth: .infinity, alignment: .leading).card(radius: 16)
            }
            VStack(spacing: 0) {
                DatePicker("Day", selection: $day, displayedComponents: .date).padding(.horizontal, 16).frame(minHeight: 52)
                PaperworkDivider()
                DatePicker("Time", selection: $time, displayedComponents: .hourAndMinute).padding(.horizontal, 16).frame(minHeight: 52)
                PaperworkDivider()
                TextField("Where (office, address)", text: $place).padding(.horizontal, 16).frame(minHeight: 52)
                PaperworkDivider()
                Stepper(minutes == 0 ? "Length not given" : "\(minutes) min", value: $minutes, in: 0...240, step: 15).padding(.horizontal, 16).frame(minHeight: 52)
            }
            .font(.geist(16)).tint(Color(light: 0x00768B, dark: 0x5CCBE0))
            .environment(\.locale, Locale(identifier: "en_GB"))
            .card(radius: 20)
            PaperworkButton(title: "Keep this appointment", grow: true, working: model.busy == "appt") {
                guard let process else { return }
                Task { await save(process, existing) }
            }
            if let existing, let process {
                PaperworkButton(title: "Remove it", plain: true, grow: true, working: model.busy == "appt-off") {
                    Task {
                        let done = await model.run("appt-off", session.api, done: "Appointment record removed.") { () async throws(APIFailure) in
                            try await session.api.record("appointment_cancelled", on: process, payload: ["appointment_id": existing.appointmentId ?? ""], reviewed: false)
                        }
                        if done { dismiss() }
                    }
                }
            }
        }
        .onAppear {
            if task.isEmpty { task = tracked.contains { $0.occurrenceKey == key } ? key : tracked.first?.occurrenceKey ?? key }
            if let existing, let start = existing.day {
                day = start
                if let clock = PaperworkDay.clock(existing.startsAt) {
                    let parts = clock.split(separator: ":").compactMap { Int($0) }
                    if parts.count == 2 { time = Calendar.current.date(bySettingHour: parts[0], minute: parts[1], second: 0, of: .now) ?? time }
                }
                place = existing.location?.label ?? ""
                minutes = existing.durationMinutes ?? 0
            }
        }
    }

    private func save(_ process: PaperworkProcess, _ existing: PaperworkDate?) async {
        let clock = Calendar.current.dateComponents([.hour, .minute], from: time)
        let start = "\(PaperworkDay.text(day))T\(String(format: "%02d:%02d", clock.hour ?? 0, clock.minute ?? 0)):00\(PaperworkDay.offset(day))"
        let id = existing?.appointmentId ?? UUID().uuidString.lowercased(), length = minutes
        let where_ = place.trimmingCharacters(in: .whitespaces)
        let done = await model.run("appt", session.api, done: "Appointment kept. Nothing was booked.") { () async throws(APIFailure) in
            var payload: [String: Any] = ["appointment_id": id, "starts_at": start, "timezone": "Europe/Berlin", "duration_minutes": length == 0 ? NSNull() : length]
            if !where_.isEmpty { payload["location"] = ["label": where_] }
            try await session.api.record("appointment_recorded", on: process, payload: payload)
        }
        if done { dismiss() }
    }
}

// MARK: - Submission

/// "I've handed it in" (submission_recorded), and taking that back; its history is kept.
struct PaperworkSubmissionSheet: View {
    let key: String
    @Environment(Session.self) private var session
    @Environment(PaperworkModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @State private var day = Date.now

    var body: some View {
        let plan = model.plan
        let process = plan?.process(key)
        let existing = process.flatMap { p in plan?.rows(of: p).first { $0.kind == "submission_recorded" } }
        PaperworkSheetFrame(title: existing == nil ? "Record a submission" : "Your submission",
                            subtitle: "When you handed it in. Nothing is sent anywhere.") {
            DatePicker("Handed in", selection: $day, in: ...Date.now, displayedComponents: .date)
                .datePickerStyle(.graphical).tint(Color(light: 0x00768B, dark: 0x5CCBE0))
                .environment(\.locale, Locale(identifier: "en_GB"))
                .padding(8).card(radius: 20)
            PaperworkButton(title: "Record \(day.formatted(.dateTime.day().month(.wide).locale(Locale(identifier: "en_GB"))))", grow: true, working: model.busy == "sub") {
                guard let process else { return }
                Task {
                    let done = await model.run("sub", session.api, done: "Submission recorded. The task now waits for the office.") { () async throws(APIFailure) in
                        try await session.api.record("submission_recorded", on: process, payload: ["occurred_on": PaperworkDay.text(day)])
                    }
                    if done { dismiss() }
                }
            }
            if let existing, let process, let event = existing.eventId {
                PaperworkButton(title: "Remove the submission record", plain: true, grow: true, working: model.busy == "sub-off") {
                    Task {
                        let done = await model.run("sub-off", session.api, done: "Submission record removed. Its history is kept.") { () async throws(APIFailure) in
                            try await session.api.record("submission_retracted", on: process, payload: ["event_id": event], reviewed: false)
                        }
                        if done { dismiss() }
                    }
                }
            }
        }
        .onAppear { if let date = existing?.day { day = date } }
    }
}

// MARK: - Update progress, with its day

/// A progress report that carries the day it happened, and a note in their own words if they like.
struct PaperworkProgressSheet: View {
    let key: String
    let event: String
    @Environment(Session.self) private var session
    @Environment(PaperworkModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @State private var day = Date.now
    @State private var dated = true
    @State private var note = ""

    var body: some View {
        let process = model.plan?.process(key)
        let option = process?.progressOptions?.first { $0.event == event }
        let required = option?.date == "required"
        PaperworkSheetFrame(title: PaperworkWords.change[event] ?? "Update progress", subtitle: "Your own record. Nothing is sent to the office.") {
            if option?.date != nil {
                if !required {
                    Toggle("Add the date", isOn: $dated.animation(Motion.ios(0.3))).font(.geist(16)).tint(PaperworkLook.green)
                        .padding(.horizontal, 16).frame(minHeight: 52).card(radius: 16)
                }
                if dated || required {
                    DatePicker(required ? "Date" : "Date (optional)", selection: $day, in: ...Date.now, displayedComponents: .date)
                        .datePickerStyle(.graphical).tint(Color(light: 0x00768B, dark: 0x5CCBE0))
                        .environment(\.locale, Locale(identifier: "en_GB"))
                        .padding(8).card(radius: 20)
                        .transition(.opacity)
                }
            }
            if option?.note == true {
                TextField("What’s happening, in your words (optional)", text: $note, axis: .vertical)
                    .font(.geist(16)).lineLimit(2...4)
                    .padding(14).card(radius: 16)
                    .onChange(of: note) { if note.count > 500 { note = String(note.prefix(500)) } }
            }
            PaperworkButton(title: "Save", grow: true, working: model.busy == "report") {
                guard let process else { return }
                let on = (dated || required) && option?.date != nil ? PaperworkDay.text(day) : nil
                let words = note.trimmingCharacters(in: .whitespacesAndNewlines)
                Task {
                    let done = await model.run("report", session.api, done: "Progress saved. Nothing was sent to the office.") { () async throws(APIFailure) in
                        var payload: [String: Any] = [:]
                        if let on { payload["occurred_on"] = on }
                        if !words.isEmpty { payload["note"] = words }
                        try await session.api.record(event, on: process, payload: payload)
                    }
                    if done { dismiss() }
                }
            }
        }
    }
}
