import API
import Core
import DesignSystem
import SwiftUI

/// What opens over Paperwork.
enum PaperworkSheet: Identifiable, Equatable {
    case questions(single: Bool)
    case setup
    case situation
    case sources(String?)
    case history
    case steps
    case documents(String)
    case appointment(String)
    case submission(String)
    case progress(String, String)
    case web(URL)

    var id: String {
        switch self {
        case let .questions(single): "q\(single)"
        case .setup: "setup"
        case .situation: "situation"
        case let .sources(key): "src\(key ?? "")"
        case .history: "history"
        case .steps: "steps"
        case let .documents(key): "docs\(key)"
        case let .appointment(key): "appt\(key)"
        case let .submission(key): "sub\(key)"
        case let .progress(key, event): "progress\(key)\(event)"
        case let .web(url): url.absoluteString
        }
    }
}

/// A task to open, at a step.
struct PaperworkOpen: Hashable {
    let key: String
    var step: String?
}

/// Paperwork, as the canvas draws it: the title and its line, search, Overview and Documents.
/// The overview leads with three numbers and their rings, the next two dates as calendar tiles,
/// the one open question, your tasks by status, and every date. A task opens its own page. All of
/// it is the server's plan; nothing here works out a rule or a date.
struct PaperworkView: View {
    let header: AnyView
    @Environment(Session.self) private var session
    @Environment(\.shell) private var shell
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var model = PaperworkModel()
    @State private var path: [PaperworkOpen] = []
    @State private var sheet: PaperworkSheet?
    @State private var view = 0
    @State private var query = ""
    @State private var filter = "all"
    @State private var openDoc: String?
    @State private var loadingSince = Date.now
    @FocusState private var searching: Bool

    var body: some View {
        NavigationStack(path: $path) {
            ScrollView {
                VStack(alignment: .leading, spacing: 22) {
                    top
                    switch model.phase {
                    case .loading:
                        VStack(spacing: 8) {
                            WavingMark(since: loadingSince, cycles: nil).frame(width: 34, height: 28).foregroundStyle(Palette.text)
                            Text("Opening your record…").font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft)
                        }
                        .frame(maxWidth: .infinity).padding(.top, 60)
                    case let .problem(text):
                        PaperworkNone(title: text, action: "Try again") { Task { await model.load(session.api) } }
                    case .ready:
                        content
                    }
                }
                .padding(.horizontal, 16).padding(.top, 10).padding(.bottom, 40)
                .animation(reduceMotion ? nil : Motion.ios(0.4), value: model.entry)
            }
            .scrollIndicators(.hidden)
            .scrollDismissesKeyboard(.immediately)
            .background(Palette.canvas)
            .onScrollGeometryChange(for: CGFloat.self) { $0.contentOffset.y + $0.contentInsets.top } action: { _, now in shell.scrolled(now) }
            .refreshable { await model.load(session.api) }
            .safeAreaInset(edge: .top, spacing: 0) { header }
            .toolbarVisibility(.hidden, for: .navigationBar)
            .navigationDestination(for: PaperworkOpen.self) { open in
                PaperworkTaskPage(open: open, sheet: $sheet)
            }
        }
        .environment(model)
        .task { await model.start(session.api) }
        .overlay(alignment: .bottom) {
            if let note = model.note {
                PaperworkNoteBar(note: note)
                    .padding(.horizontal, 16).padding(.bottom, 64)
                    .transition(.offset(y: 30).combined(with: .opacity))
                    .id(note.id)
            }
        }
        .animation(reduceMotion ? nil : .spring(duration: 0.5, bounce: 0.3), value: model.note)
        .sheet(item: $sheet) { which in
            Group {
                switch which {
                case let .questions(single): PaperworkQuestionsSheet(mode: single ? .one : .run)
                case .setup: PaperworkQuestionsSheet(mode: .setup)
                case .situation: PaperworkSituationSheet(web: { sheet = .web($0) })
                case let .sources(key): PaperworkSourcesSheet(only: key, web: { sheet = .web($0) })
                case .history: PaperworkHistorySheet(open: { key in sheet = nil; path.append(PaperworkOpen(key: key)) })
                case .steps: PaperworkStepsSheet(open: { open in sheet = nil; path.append(open) })
                case let .documents(key): PaperworkBringSheet(key: key, web: { sheet = .web($0) })
                case let .appointment(key): PaperworkAppointmentSheet(key: key)
                case let .submission(key): PaperworkSubmissionSheet(key: key)
                case let .progress(key, event): PaperworkProgressSheet(key: key, event: event)
                case let .web(url): SafariView(url: url).ignoresSafeArea()
                }
            }
            .environment(model)
            .environment(session)
        }
    }

    // MARK: The top

    private var top: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(alignment: .center, spacing: 10) {
                VStack(alignment: .leading, spacing: 4) {
                    Text("Paperwork").font(.posterGrotesque(36)).tracking(-0.6).foregroundStyle(Palette.text)
                        .accessibilityAddTraits(.isHeader)
                    Text(subline).font(.geist(13.5)).foregroundStyle(Palette.textSoft).contentTransition(.opacity)
                }
                Spacer(minLength: 8)
                if model.entry?.state == "ready" {
                    Button { sheet = .situation } label: {
                        Image(systemName: "person.text.rectangle").font(.system(size: 17, weight: .semibold)).foregroundStyle(Palette.text)
                    }
                    .buttonStyle(IconTileStyle(fill: Palette.surface, size: 44))
                    .accessibilityLabel("Your situation")
                }
            }
            if model.entry?.state == "ready" {
                HStack(spacing: 8) {
                    HStack(spacing: 8) {
                        Image(systemName: "magnifyingglass").font(.system(size: 15, weight: .semibold)).foregroundStyle(Palette.textSoft)
                        TextField(searching ? "Tasks, dates, documents" : "Search your paperwork", text: $query)
                            .font(.geist(16)).focused($searching).submitLabel(.search)
                        if !query.isEmpty {
                            Button { withAnimation(.snappy) { query = "" } } label: {
                                Image(systemName: "xmark.circle.fill").font(.system(size: 16)).foregroundStyle(Palette.textSoft)
                                    .frame(width: 32, height: 32)
                            }
                            .buttonStyle(.plain).accessibilityLabel("Clear search")
                        }
                    }
                    .padding(.leading, 14).padding(.trailing, 6).frame(height: 44)
                    .background(Palette.surface, in: .rect(cornerRadius: 14, style: .continuous))
                    .overlay { RoundedRectangle(cornerRadius: 14, style: .continuous).strokeBorder(Palette.border) }
                    if searching {
                        Button("Done") { searching = false }
                            .font(.geist(16, .semibold)).foregroundStyle(TodayLink.ink).frame(height: 44)
                            .transition(.move(edge: .trailing).combined(with: .opacity))
                    }
                }
                .animation(.snappy, value: searching)
                Picker("Show", selection: $view.animation(Motion.ios(0.35))) {
                    Text("Overview").tag(0)
                    Text("Documents").tag(1)
                }
                .pickerStyle(.segmented)
                .controlSize(.large)
            }
        }
    }

    private var subline: String {
        switch model.entry?.state {
        case "processing_stopped": "Processing stopped"
        case "setup_required": "Cologne · nothing set up yet"
        case "ready": "Cologne · your own record"
        default: "Your own record"
        }
    }

    // MARK: States

    @ViewBuilder
    private var content: some View {
        switch model.entry?.state {
        case "setup_required": welcome
        case "processing_stopped": stopped
        case "ready":
            if let plan = model.plan {
                if view == 0 {
                    PaperworkOverview(plan: plan, query: query, filter: $filter, sheet: $sheet, open: { path.append($0) }, showDocs: { withAnimation(Motion.ios(0.35)) { view = 1 } },
                                      clear: { query = "" })
                        .transition(.asymmetric(insertion: .offset(x: -24).combined(with: .opacity), removal: .opacity))
                } else {
                    PaperworkDocumentsView(plan: plan, query: query, open: $openDoc, openTask: { path.append(PaperworkOpen(key: $0)) }, web: { sheet = .web($0) })
                        .transition(.asymmetric(insertion: .offset(x: 24).combined(with: .opacity), removal: .opacity))
                }
            }
        default:
            // Erased, or the record can't be read right now: said plainly, nothing drawn from it.
            PaperworkNone(title: model.entry?.state == "erased" ? "Your Paperwork record was deleted." : "Your record can’t be shown right now.",
                          note: model.entry?.state == "erased" ? "Start again whenever you like." : "Try again in a moment.", action: "Check again") {
                Task { await model.load(session.api) }
            }
        }
    }

    private var welcome: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("WELCOME TO COLOGNE").font(.posterMarker(14)).foregroundStyle(Palette.text)
                .padding(.horizontal, 10).padding(.vertical, 5)
                .background(Palette.posterYellow, in: .rect(cornerRadius: 3)).rotationEffect(.degrees(-2))
            Text("A little clarity. One step at a time.").font(.posterGrotesque(30)).tracking(-0.4).foregroundStyle(Palette.text)
                .fixedSize(horizontal: false, vertical: true)
            Text("Answer a few questions and Paperwork lays out your steps, the documents to gather and the dates that matter.")
                .font(.geist(15)).foregroundStyle(Palette.textSoft).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
            HStack(spacing: 18) {
                ForEach([("checkmark", "Next steps", PaperworkLook.green), ("doc.text", "Documents", PaperworkLook.purple), ("calendar", "Dates", PaperworkLook.band)], id: \.1) { symbol, word, tint in
                    HStack(spacing: 7) {
                        Image(systemName: symbol).font(.system(size: 13, weight: .bold)).foregroundStyle(tint)
                            .frame(width: 28, height: 28).background(tint.opacity(0.13), in: .rect(cornerRadius: 8, style: .continuous))
                        Text(word).font(.geist(14, .semibold)).foregroundStyle(Palette.text).lineLimit(1).fixedSize()
                    }
                }
            }
            PaperworkButton(title: "Get started", grow: true) { sheet = .setup }.padding(.top, 4)
            Text("About two minutes. Your answers stay in your own record.").font(.geist(12.5)).foregroundStyle(Palette.textSoft)
                .multilineTextAlignment(.center).frame(maxWidth: .infinity)
        }
        .padding(.horizontal, 20).padding(.top, 22).padding(.bottom, 20)
        .card(radius: 28)
        .transition(.offset(y: 16).combined(with: .opacity))
    }

    private var stopped: some View {
        HStack(spacing: 12) {
            Image(systemName: "stop.circle.fill").font(.system(size: 16, weight: .semibold)).foregroundStyle(PaperworkLook.red)
                .frame(width: 36, height: 36).background(PaperworkLook.blockBack, in: .rect(cornerRadius: 10, style: .continuous))
            VStack(alignment: .leading, spacing: 2) {
                Text("Processing stopped.").font(.geist(16, .semibold)).foregroundStyle(Palette.text)
                Text("Paperwork keeps your record but doesn’t use it until you resume.").font(.geist(13)).foregroundStyle(Palette.textSoft)
                    .fixedSize(horizontal: false, vertical: true)
            }
            Spacer(minLength: 4)
            PaperworkButton(title: "Resume", small: true, working: model.busy == "resume") {
                Task {
                    guard let person = await person() else { return }
                    await model.run("resume", session.api, done: "Paperwork uses your record again.") { () async throws(APIFailure) in
                        try await session.api.setPaperworkStopped(false, person: person)
                    }
                }
            }
        }
        .padding(14)
        .card(radius: 22)
        .transition(.offset(y: 12).combined(with: .opacity))
    }

    /// While stopped there's no plan to read the person from; their own record is the one person.
    private func person() async -> Int? {
        if let id = model.plan?.personId { return id }
        return await session.api.paperworkPerson()
    }
}

// MARK: - Overview

struct PaperworkOverview: View {
    let plan: PaperworkPlan
    let query: String
    @Binding var filter: String
    @Binding var sheet: PaperworkSheet?
    let open: (PaperworkOpen) -> Void
    let showDocs: () -> Void
    let clear: () -> Void
    @Environment(PaperworkModel.self) private var model
    @Environment(Session.self) private var session
    @State private var skipped = false
    @State private var shownIn = false

    var body: some View {
        let tasks = plan.tasks.filter { query.isEmpty || PaperworkSearch.matches($0, query) }
        let dates = plan.dates.filter { query.isEmpty || PaperworkSearch.has(plan.words($0).title + " " + plan.words($0).note, query) }
        let docs = (plan.paperwork?.requirements ?? []).filter { !query.isEmpty && PaperworkSearch.matches($0, query) }
        VStack(alignment: .leading, spacing: 22) {
            if model.justReady {
                Label("Your steps for Cologne are ready.", systemImage: "checkmark").labelStyle(Tight())
                    .font(.geist(15, .semibold)).foregroundStyle(PaperworkLook.greenInk)
                    .padding(.horizontal, 14).frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                    .background(PaperworkLook.greenSoft, in: .rect(cornerRadius: 16))
                    .transition(.offset(y: -10).combined(with: .opacity))
            }
            if !query.isEmpty {
                let hits = tasks.count + dates.count + docs.count
                if hits == 0 {
                    PaperworkNone(title: "Nothing matches “\(query)”", note: "Try a task, a document or a date, in English or German.", action: "Clear search", run: clear)
                } else {
                    HStack {
                        Text("\(hits) \(hits == 1 ? "match" : "matches") for “\(query.trimmingCharacters(in: .whitespaces))”").font(.geist(14, .semibold)).foregroundStyle(Palette.textSoft)
                        Spacer()
                        Button("Clear", action: clear).font(.geist(14, .semibold)).foregroundStyle(TodayLink.ink).frame(minHeight: 44)
                    }
                    .padding(.horizontal, 4)
                }
                if !docs.isEmpty {
                    Button(action: showDocs) {
                        HStack(spacing: 12) {
                            Image(systemName: "doc.text").font(.system(size: 14, weight: .semibold)).foregroundStyle(PaperworkLook.purpleInk)
                                .frame(width: 32, height: 32).background(PaperworkLook.purpleSoft, in: .rect(cornerRadius: 9))
                            VStack(alignment: .leading, spacing: 2) {
                                Text("\(docs.count) \(docs.count == 1 ? "document matches" : "documents match")").font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                                Text(docs[0].label).font(.geist(13)).foregroundStyle(Palette.textSoft).lineLimit(1)
                            }
                            Spacer()
                            Image(systemName: "chevron.right").font(.system(size: 12, weight: .bold)).foregroundStyle(Palette.textSoft)
                        }
                        .padding(14).card(radius: 20)
                    }
                    .buttonStyle(PressStyle())
                }
            } else {
                glance
                coming
                question
            }
            tasksPart(tasks)
            datesPart(dates)
            VStack(spacing: 0) {
                Label("Your progress is your own record", systemImage: "checkmark").labelStyle(Tight())
                    .font(.geist(12.5)).foregroundStyle(Palette.textSoft)
                Button("Where this comes from") { sheet = .sources(nil) }
                    .font(.geist(13, .semibold)).foregroundStyle(TodayLink.ink).frame(minHeight: 44)
            }
            .frame(maxWidth: .infinity)
        }
        .onAppear {
            guard model.justReady else { return }
            Task { try? await Task.sleep(for: .seconds(5)); withAnimation(Motion.ios(0.4)) { model.justReady = false } }
        }
    }

    // MARK: At a glance

    private var glance: some View {
        let needs = plan.needsYou
        let blocked = plan.tasks.first { $0.tone == "blocked" }
        return HStack(spacing: 8) {
            tile("Steps", "checkmark", value: plan.stepsDone, of: "/\(plan.stepsTotal)", color: PaperworkLook.green,
                 ring: plan.stepsTotal == 0 ? 0 : Double(plan.stepsDone) / Double(plan.stepsTotal), index: 0,
                 label: "\(plan.stepsDone) of \(plan.stepsTotal) steps done. Show all steps") { sheet = .steps }
            tile("Papers", "doc.text", value: plan.papersReady, of: "/\(plan.papersRequired.count)", color: PaperworkLook.purple,
                 ring: plan.papersRequired.isEmpty ? 0 : Double(plan.papersReady) / Double(plan.papersRequired.count), index: 1,
                 label: "\(plan.papersReady) of \(plan.papersRequired.count) documents ready. Show documents", go: showDocs)
            tile(needs > 0 ? "Needs you" : "All clear", needs > 0 ? "exclamationmark.triangle" : "checkmark", value: needs, of: needs > 0 ? "" : " left",
                 color: needs > 0 ? PaperworkLook.red : PaperworkLook.green, ring: 1, index: 2,
                 label: needs > 0 ? "\(needs) \(needs == 1 ? "thing needs" : "things need") you" : "Nothing needs you right now") {
                if plan.questions?.question != nil { sheet = .questions(single: true) } else if let blocked { open(PaperworkOpen(key: blocked.occurrenceKey)) }
            }
        }
    }

    private func tile(_ title: String, _ symbol: String, value: Int, of: String, color: Color, ring: Double, index: Int, label: String, go: @escaping () -> Void) -> some View {
        Button(action: go) {
            VStack(alignment: .leading, spacing: 6) {
                Label(title, systemImage: symbol).labelStyle(Tight()).font(.geist(13, .bold)).foregroundStyle(color).lineLimit(1).minimumScaleFactor(0.8)
                Spacer(minLength: 0)
                HStack(alignment: .firstTextBaseline, spacing: 2) {
                    Text("\(value)").font(.posterGrotesque(30)).tracking(-0.5).monospacedDigit().contentTransition(.numericText())
                    Text(of).font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft)
                }
                .foregroundStyle(Palette.text)
            }
            .padding(.horizontal, 12).padding(.top, 12).padding(.bottom, 10)
            .frame(maxWidth: .infinity, minHeight: 104, alignment: .leading)
            .overlay(alignment: .bottomTrailing) {
                PaperworkRing(value: ring, color: color, line: 4) { EmptyView() }.frame(width: 34, height: 34).padding(10)
            }
            .card(radius: 20)
            .contentShape(.rect(cornerRadius: 20))
        }
        .buttonStyle(PressStyle())
        .accessibilityLabel(label)
    }

    // MARK: Coming up

    @ViewBuilder
    private var coming: some View {
        let next = plan.coming(from: .now, limit: 2)
        if !next.isEmpty {
            HStack(alignment: .top, spacing: 8) {
                ForEach(next) { row in
                    if let day = row.day {
                        let warn = row.kind == "document_expiry" || row.kind == "legal_due" || row.overdue == true
                        let days = PaperworkDay.days(day)
                        Button { go(row) } label: {
                            VStack(alignment: .leading, spacing: 0) {
                                HStack {
                                    Text(days == 0 ? "Today" : days == 1 ? "Tomorrow" : PaperworkDay.weekday(day))
                                    Spacer()
                                    Text(PaperworkDay.clock(row.startsAt) ?? (days <= 1 ? PaperworkDay.weekday(day) : ""))
                                        .monospacedDigit()
                                }
                                .font(.geist(12.5, .bold)).tracking(0.4).textCase(.uppercase).foregroundStyle(.white)
                                .padding(.horizontal, 12).padding(.vertical, 7)
                                .background(warn ? PaperworkLook.amber : PaperworkLook.band)
                                HStack(alignment: .firstTextBaseline, spacing: 6) {
                                    Text(PaperworkDay.dayNumber(day)).font(.posterGrotesque(40)).tracking(-1)
                                    Text(PaperworkDay.month(day)).font(.geist(14, .bold)).tracking(0.4).textCase(.uppercase).foregroundStyle(Palette.textSoft)
                                }
                                .foregroundStyle(Palette.text)
                                .padding(.horizontal, 12).padding(.top, 8)
                                HStack(alignment: .top, spacing: 6) {
                                    Image(systemName: row.kind == "appointment" ? "calendar" : warn ? "exclamationmark.triangle" : "flag")
                                        .font(.system(size: 13, weight: .semibold)).foregroundStyle(warn ? PaperworkLook.amber : Palette.textSoft).padding(.top, 2)
                                    VStack(alignment: .leading, spacing: 1) {
                                        Text(plan.words(row).title).font(.geist(14, .semibold)).foregroundStyle(Palette.text).multilineTextAlignment(.leading)
                                        if let task = row.occurrenceKey.flatMap(plan.process)?.title, row.kind != "appointment" {
                                            Text(task).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1)
                                        }
                                    }
                                }
                                .padding(.horizontal, 12).padding(.top, 6).padding(.bottom, 12)
                            }
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .background(Palette.surface)
                            .clipShape(.rect(cornerRadius: 20))
                            .overlay { RoundedRectangle(cornerRadius: 20).strokeBorder(Palette.border) }
                        }
                        .buttonStyle(PressStyle())
                    }
                }
                if next.count == 1 { Color.clear.frame(maxWidth: .infinity) }
            }
        }
    }

    private func go(_ row: PaperworkDate) {
        if let key = row.occurrenceKey, plan.process(key) != nil { open(PaperworkOpen(key: key, step: row.stepId)) } else { sheet = .situation }
    }

    // MARK: The one question

    @ViewBuilder
    private var question: some View {
        if let question = plan.questions?.question {
            if skipped {
                HStack {
                    Text("1 question left for later").font(.geist(14.5, .semibold)).foregroundStyle(Palette.textSoft)
                    Spacer()
                    Button("Answer when ready") { withAnimation(Motion.ios(0.38)) { skipped = false } }
                        .font(.geist(14.5, .semibold)).foregroundStyle(TodayLink.ink).frame(minHeight: 44)
                }
                .padding(.horizontal, 16).card(radius: 20)
                .transition(.opacity.combined(with: .offset(y: -8)))
            } else {
                VStack(alignment: .leading, spacing: 8) {
                    HStack(alignment: .top, spacing: 12) {
                        Image(systemName: "mappin").font(.system(size: 17, weight: .semibold)).foregroundStyle(Palette.text)
                            .frame(width: 40, height: 40).background(Palette.posterYellow, in: .rect(cornerRadius: 12, style: .continuous))
                        VStack(alignment: .leading, spacing: 4) {
                            Text("\(PaperworkWords.label(question.factKey)) · 1 question").font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft)
                            Text(question.question).font(.posterGrotesque(22)).tracking(-0.2).foregroundStyle(Palette.text)
                                .fixedSize(horizontal: false, vertical: true)
                        }
                    }
                    if let why = question.why {
                        Text(why).font(.geist(14)).foregroundStyle(Palette.textSoft).lineSpacing(2).fixedSize(horizontal: false, vertical: true)
                    }
                    HStack(spacing: 8) {
                        PaperworkButton(title: question.answerSchema.type == "date" ? "Enter date" : "Answer", symbol: question.answerSchema.type == "date" ? "calendar" : nil, small: true) {
                            sheet = .questions(single: true)
                        }
                        PaperworkButton(title: "Skip for now", plain: true, small: true) { withAnimation(Motion.ios(0.38)) { skipped = true } }
                    }
                    .padding(.top, 6)
                }
                .padding(18)
                .card(radius: 24)
                .transition(.opacity.combined(with: .scale(scale: 0.97, anchor: .top)))
            }
        }
    }

    // MARK: Tasks

    private func tasksPart(_ tasks: [PaperworkProcess]) -> some View {
        let count = { (tone: String) in tasks.filter { tone == "all" || $0.tone == tone }.count }
        let shown = tasks.filter { filter == "all" || $0.tone == filter }
        return VStack(alignment: .leading, spacing: 12) {
            PaperworkHead(title: "Your tasks", count: "\(shown.count)") {
                HStack(spacing: 4) {
                    Button { sheet = .steps } label: {
                        Label("All steps \(plan.stepsTotal)", systemImage: "chevron.right").labelStyle(TrailingIcon())
                            .font(.geist(14.5, .semibold)).foregroundStyle(TodayLink.ink).frame(minHeight: 44)
                    }
                    .buttonStyle(PressStyle())
                    PaperworkMini(symbol: "clock.arrow.circlepath", label: "Your history") { sheet = .history }
                }
            }
            PaperworkSegments(items: [("all", "All"), ("todo", "To do"), ("waiting", "Waiting"), ("blocked", "Blocked")].map { ($0.0, $0.1, count($0.0)) }, chosen: $filter)
            if tasks.isEmpty, query.isEmpty {
                // No open task: said plainly, with the way to more when Paperwork still has questions.
                let more = (plan.questions?.remainingInformationCount ?? 0) > 0
                PaperworkNone(title: "No open tasks right now",
                              note: more ? "A few more answers can bring up what applies to you." : "Finished ones are in your history.",
                              action: more ? "Answer more questions" : "Your history") { sheet = more ? .questions(single: false) : .history }
            }
            if shown.isEmpty, !tasks.isEmpty {
                PaperworkNone(title: "No tasks with this status", action: "Show all tasks") { withAnimation(.snappy) { filter = "all" } }
            }
            VStack(spacing: 10) {
                ForEach(Array(shown.enumerated()), id: \.element.occurrenceKey) { index, process in
                    PaperworkTaskRow(process: process) { open(PaperworkOpen(key: process.occurrenceKey)) }
                        .offset(y: shownIn ? 0 : 14).opacity(shownIn ? 1 : 0)
                        .animation(Motion.ios(0.5).delay(Double(index) * 0.07 + 0.1), value: shownIn)
                        .transition(.opacity.combined(with: .scale(scale: 0.97)))
                }
            }
            .animation(Motion.ios(0.35), value: filter)
            .onAppear { shownIn = true }
        }
    }

    // MARK: Dates

    private func datesPart(_ dates: [PaperworkDate]) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            PaperworkHead(title: "Dates & appointments") {
                if let tracked = plan.tasks.first(where: { $0.tracked && $0.isClosed != true }) {
                    PaperworkMini(symbol: "plus", label: "Record an appointment") { sheet = .appointment(tracked.occurrenceKey) }
                }
            }
            if dates.isEmpty {
                Text(query.isEmpty ? "No dates yet. Dates appear here as your answers and tasks give them." : "No dates match.")
                    .font(.geist(14)).foregroundStyle(Palette.textSoft).padding(.horizontal, 4)
            } else {
                PaperworkList {
                    ForEach(Array(dates.enumerated()), id: \.element.id) { index, row in
                        if index > 0 { PaperworkDivider() }
                        Button { go(row) } label: { PaperworkDateRow(row: row, words: plan.words(row)) }
                            .buttonStyle(.plain)
                    }
                }
            }
        }
    }
}

/// One task: its ring, title, the next step, its steps as segments, a chip for why it waits, its stamp.
struct PaperworkTaskRow: View {
    let process: PaperworkProcess
    let open: () -> Void

    var body: some View {
        let look = PaperworkLook.topic(process.topic)
        let stamp = PaperworkLook.stamp(process)
        let next = process.steps.first { !$0.done } ?? process.steps.last
        Button(action: open) {
            HStack(alignment: .top, spacing: 14) {
                PaperworkRing(value: process.steps.isEmpty ? 0 : Double(process.done) / Double(process.steps.count), color: look.color, line: 3.5) {
                    Image(systemName: look.symbol).font(.system(size: 15, weight: .semibold)).foregroundStyle(look.color)
                }
                .frame(width: 44, height: 44)
                VStack(alignment: .leading, spacing: 5) {
                    // The stamp in the title's row, so it never sits on a line of text.
                    HStack(alignment: .top, spacing: 8) {
                        Text(process.title).font(.geist(17, .bold)).foregroundStyle(Palette.text).multilineTextAlignment(.leading)
                            .fixedSize(horizontal: false, vertical: true).layoutPriority(1)
                        Spacer(minLength: 0)
                        PaperworkStamp(word: stamp.word, ink: stamp.ink, tilt: stamp.tilt, struck: stamp.struck).padding(.top, -2)
                    }
                    if let next { Text(next.title).font(.geist(14.5)).foregroundStyle(Palette.text).lineSpacing(1).multilineTextAlignment(.leading) }
                    HStack(spacing: 4) {
                        ForEach(process.steps) { step in
                            RoundedRectangle(cornerRadius: 2, style: .continuous).fill(step.done ? look.color : look.color.opacity(0.18)).frame(width: 22, height: 6)
                        }
                        Text("\(process.done)/\(process.steps.count)").font(.geist(12.5, .bold)).foregroundStyle(Palette.textSoft).monospacedDigit()
                            .padding(.leading, 4).contentTransition(.numericText())
                    }
                    .padding(.top, 2)
                    if let chip = chip {
                        Label(chip.text, systemImage: chip.symbol).labelStyle(Tight())
                            .font(.geist(13, .semibold)).foregroundStyle(chip.ink)
                            .padding(.top, 2)
                    }
                }
                Spacer(minLength: 0)
            }
            .padding(.leading, 16).padding(.trailing, 14).padding(.vertical, 16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .card(radius: 22)
            .contentShape(.rect(cornerRadius: 22))
        }
        .buttonStyle(PressStyle())
        .accessibilityElement(children: .combine)
        .accessibilityHint("Opens the task")
    }

    private var chip: (text: String, symbol: String, ink: Color, back: Color)? {
        if !process.tracked { return ("Explore first", "info.circle", Palette.textSoft, Palette.quiet) }
        return switch process.workflow {
        case "blocked": (process.blockingReason?.label ?? process.state.report?.note ?? "Something is holding this up", "exclamationmark.triangle", PaperworkLook.blockInk, PaperworkLook.blockBack)
        case "action_required": ("The office needs something from you", "exclamationmark.triangle", PaperworkLook.blockInk, PaperworkLook.blockBack)
        case "waiting_authority": ("Waiting for a reply", "clock", PaperworkLook.warnInk, PaperworkLook.warnBack)
        case "submitted": ("Submitted", "checkmark", PaperworkLook.apptInk, PaperworkLook.apptBack)
        default: process.guidanceState == "review_required" ? ("The guidance changed", "arrow.triangle.2.circlepath", PaperworkLook.warnInk, PaperworkLook.warnBack) : nil
        }
    }
}

/// A date: its day in a tile, what it is, and for which task.
struct PaperworkDateRow: View {
    let row: PaperworkDate
    let words: (title: String, note: String)

    var body: some View {
        let warn = row.kind == "document_expiry" || row.kind == "legal_due" || row.overdue == true
        let appt = row.kind == "appointment"
        HStack(spacing: 12) {
            VStack(spacing: 1) {
                if let day = row.day {
                    Text(PaperworkDay.near(day)).font(.posterGrotesque(15))
                    Text(appt ? (PaperworkDay.clock(row.startsAt) ?? PaperworkDay.weekday(day)) : PaperworkDay.weekday(day))
                        .font(.geist(11.5, .semibold)).foregroundStyle(appt || warn ? AnyShapeStyle(.primary) : AnyShapeStyle(Palette.textSoft))
                } else {
                    Text("?").font(.posterGrotesque(15))
                }
            }
            .foregroundStyle(appt ? PaperworkLook.apptInk : warn ? PaperworkLook.warnInk : Palette.text)
            .frame(width: 54, height: 46)
            .background {
                if row.day == nil {
                    RoundedRectangle(cornerRadius: 12).strokeBorder(Palette.border, style: StrokeStyle(lineWidth: 1.5, dash: [4, 3]))
                } else {
                    RoundedRectangle(cornerRadius: 12).fill(appt ? PaperworkLook.apptBack : warn ? PaperworkLook.warnBack : Palette.quiet)
                }
            }
            VStack(alignment: .leading, spacing: 2) {
                Text(words.title).font(.geist(15.5, .semibold)).foregroundStyle(Palette.text).multilineTextAlignment(.leading)
                if !words.note.isEmpty { Text(words.note).font(.geist(13)).foregroundStyle(Palette.textSoft).multilineTextAlignment(.leading) }
            }
            Spacer(minLength: 4)
            Image(systemName: "chevron.right").font(.system(size: 12, weight: .bold)).foregroundStyle(Palette.textSoft)
        }
        .padding(.horizontal, 14).padding(.vertical, 8).frame(minHeight: 62)
        .contentShape(.rect)
    }
}
