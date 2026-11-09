import API
import Core
import DesignSystem
import SwiftUI

/// The questions, one at a time, as the server's question session offers them (the web's
/// starter.tsx and questions.ts). `.setup` asks for the city first and starts the record;
/// `.one` answers only the plan's open question; `.run` keeps going until there's nothing more.
struct PaperworkQuestionsSheet: View {
    enum Mode { case setup, run, one }
    enum Stage: Equatable { case city, loading, question(PaperworkQuestion), checkpoint(more: Bool), failed(String) }

    let mode: Mode
    @Environment(Session.self) private var session
    @Environment(PaperworkModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var stage = Stage.loading
    @State private var session_: Int?
    @State private var answered = 0
    @State private var pending = false
    @State private var problem: String?
    @State private var headHeight: CGFloat = 0
    @State private var bodyHeight: CGFloat = 0
    @State private var city = "Cologne"
    @State private var other = ""

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 3) {
                    Text(title).font(.posterGrotesque(26)).foregroundStyle(Palette.text)
                    Text(subtitle).font(.geist(14)).foregroundStyle(Palette.textSoft).contentTransition(.opacity)
                }
                Spacer()
                CloseButton { finish() }
            }
            .padding(.horizontal, 20).padding(.top, 22).padding(.bottom, 14)
            .onGeometryChange(for: CGFloat.self) { $0.size.height } action: { headHeight = $0 }
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    switch stage {
                    case .city: cityStep
                    case .loading:
                        ProgressView().frame(maxWidth: .infinity).padding(.vertical, 40)
                    case let .question(question):
                        PaperworkAnswerForm(question: question, pending: pending, canSkip: mode != .setup || question.canSkip != false) { answer, state in
                            Task { await submit(question, answer, state) }
                        } skip: {
                            Task { await skip(question) }
                        }
                        .id(question.id ?? 0)
                        .transition(.asymmetric(insertion: .offset(x: 40).combined(with: .opacity), removal: .offset(x: -40).combined(with: .opacity)))
                    case let .checkpoint(more):
                        checkpoint(more: more)
                    case let .failed(text):
                        PaperworkNone(title: text, action: "Try again") { Task { await begin() } }
                    }
                    if let problem {
                        Label(problem, systemImage: "exclamationmark.circle").labelStyle(Tight())
                            .font(.geist(14, .semibold)).foregroundStyle(PaperworkLook.red)
                    }
                }
                .padding(.horizontal, 20).padding(.bottom, 10)
                .animation(reduceMotion ? nil : Motion.ios(0.38), value: stage)
            }
            .scrollIndicators(.hidden)
            .onScrollGeometryChange(for: CGFloat.self) { $0.contentSize.height } action: { _, now in bodyHeight = now }
        }
        .background(Palette.canvas)
        .fittedSheet(headHeight + bodyHeight)
        .interactiveDismissDisabled(pending)
        .task {
            if mode == .setup { stage = .city } else { await begin() }
        }
    }

    private var title: String {
        switch stage {
        case .city: "Where do you live?"
        case .checkpoint: "Your steps are ready"
        default: mode == .one ? "One question" : "A few questions"
        }
    }

    private var subtitle: String {
        switch stage {
        case .city: return "Your city decides which office’s tasks apply"
        case .checkpoint: return "\(answered) \(answered == 1 ? "answer" : "answers") saved in your own record"
        default:
            return mode == .one ? "It changes your steps and dates" : "Question \(answered + 1)"
        }
    }

    // MARK: The city

    private var cityStep: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("You can change it later in Settings.").font(.geist(15)).foregroundStyle(Palette.textSoft)
            VStack(spacing: 8) {
                option("Cologne", on: city == "Cologne") { city = "Cologne" }
                option("Somewhere else", on: city != "Cologne") { city = "" }
            }
            if city != "Cologne" {
                TextField("Your city", text: $other).font(.geist(17)).textContentType(.addressCity)
                    .padding(.horizontal, 16).frame(height: 52).card(radius: 16)
                Text("Paperwork covers Cologne for now. Elsewhere it says so, and keeps your answers.")
                    .font(.geist(13)).foregroundStyle(Palette.textSoft)
            }
            PaperworkButton(title: "Continue", grow: true, working: pending) { Task { await setUp() } }
                .disabled(chosenCity.count < 2)
                .padding(.top, 6)
        }
    }

    private var chosenCity: String { city == "Cologne" ? city : other.trimmingCharacters(in: .whitespaces) }

    private func option(_ title: String, on: Bool, action: @escaping () -> Void) -> some View {
        Button { withAnimation(.snappy) { action() } } label: {
            HStack {
                Text(title).font(.geist(16.5, .semibold)).foregroundStyle(Palette.text)
                Spacer()
                Image(systemName: "checkmark").font(.system(size: 15, weight: .bold)).foregroundStyle(Color(light: 0x00768B, dark: 0x73D3E6))
                    .opacity(on ? 1 : 0).scaleEffect(on ? 1 : 0.5)
            }
            .padding(.horizontal, 16).frame(minHeight: 56)
            .background(on ? Palette.selectBackground : Palette.surface, in: .rect(cornerRadius: 16))
            .overlay { RoundedRectangle(cornerRadius: 16).strokeBorder(on ? Color(light: 0x00768B, dark: 0x5CCBE0) : Palette.border, lineWidth: on ? 1.5 : 1) }
            .contentShape(.rect)
        }
        .buttonStyle(PressStyle())
        .accessibilityAddTraits(on ? [.isSelected] : [])
    }

    private func setUp() async {
        pending = true
        problem = nil
        defer { pending = false }
        do {
            try await session.api.setUpPaperwork(city: chosenCity)
        } catch {
            problem = PaperworkModel.words(error)
            return
        }
        await model.load(session.api)
        await begin()
    }

    // MARK: The session

    private func begin() async {
        problem = nil
        guard let plan = model.plan else {
            stage = .checkpoint(more: false)
            return
        }
        stage = .loading
        do {
            var id = plan.questions?.sessionId
            if id == nil {
                id = try await session.api.startQuestions(person: plan.personId, jurisdiction: plan.jurisdiction)
            } else if plan.questions?.status == "paused", let id {
                try await session.api.resumeQuestions(session: id)
            }
            session_ = id
            if let id { await next(id, first: true) }
        } catch {
            stage = .failed(PaperworkModel.words(error))
        }
    }

    private func next(_ id: Int, first: Bool = false) async {
        do {
            var offer = try await session.api.nextQuestion(session: id)
            // A run pauses on purpose; asking for the plan's one question carries on.
            if offer.status == "paused", mode == .one || first {
                try await session.api.resumeQuestions(session: id)
                offer = try await session.api.nextQuestion(session: id)
            }
            if offer.status == "offered", let question = offer.question, !(mode == .one && !first) {
                stage = .question(question)
                return
            }
            await model.load(session.api)
            if mode == .one { finish(); return }
            stage = .checkpoint(more: offer.status == "paused")
        } catch {
            // An expired session keeps its answers; carry on in a new one.
            if first, let plan = model.plan, let fresh = try? await session.api.startQuestions(person: plan.personId, jurisdiction: plan.jurisdiction) {
                session_ = fresh
                await next(fresh)
            } else {
                stage = .failed(PaperworkModel.words(error))
            }
        }
    }

    private func submit(_ question: PaperworkQuestion, _ answer: PaperworkAnswer, _ state: String) async {
        guard let id = session_ else { return }
        pending = true
        problem = nil
        defer { pending = false }
        do {
            try await session.api.answer(session: id, question: question, value: answer, state: state)
            answered += 1
        } catch {
            problem = PaperworkModel.words(error)
            return
        }
        if mode == .one {
            await model.load(session.api)
            model.say("Saved. Your tasks and dates use it now.")
            finish()
            return
        }
        await next(id)
    }

    private func skip(_ question: PaperworkQuestion) async {
        guard let id = session_ else { return }
        pending = true
        defer { pending = false }
        do {
            try await session.api.skip(session: id, question: question)
        } catch {
            problem = PaperworkModel.words(error)
            return
        }
        if mode == .one {
            await model.load(session.api)
            model.say("Left for later. It stays in your overview.")
            finish()
            return
        }
        await next(id)
    }

    private func checkpoint(more: Bool) -> some View {
        VStack(alignment: .leading, spacing: 14) {
            Image(systemName: "checkmark").font(.system(size: 22, weight: .bold)).foregroundStyle(.white)
                .frame(width: 56, height: 56).background(PaperworkLook.green, in: .rect(cornerRadius: 16, style: .continuous))
            Text(more ? "That’s enough for now. You can answer the rest whenever you like." : "Paperwork has what it needs for now. Your tasks, documents and dates are laid out.")
                .font(.geist(16)).foregroundStyle(Palette.text).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
            PaperworkButton(title: "Show my steps", grow: true) {
                model.justReady = true
                finish()
            }
            if more, let id = session_ {
                PaperworkButton(title: "Keep going", quiet: true, grow: true, working: pending) {
                    Task {
                        pending = true
                        defer { pending = false }
                        do { try await session.api.resumeQuestions(session: id) } catch {
                            problem = PaperworkModel.words(error)
                            return
                        }
                        answered = 0
                        await next(id)
                    }
                }
            }
        }
        .padding(.top, 8)
    }

    private func finish() {
        Task { await model.load(session.api) }
        dismiss()
    }
}

/// One question's answer, by its type: choices to tap, a calendar, or a number; and the honest
/// "I'm not sure" and "Prefer not to say".
struct PaperworkAnswerForm: View {
    let question: PaperworkQuestion
    var pending = false
    var canSkip = true
    var current: String?
    let answer: (PaperworkAnswer, String) -> Void
    var skip: (() -> Void)?
    @State private var chosen: String?
    @State private var day = Date.now
    @State private var number = 0

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            Text(question.question).font(.posterGrotesque(24)).tracking(-0.2).foregroundStyle(Palette.text)
                .fixedSize(horizontal: false, vertical: true)
            if let why = question.why {
                Text(why).font(.geist(15)).foregroundStyle(Palette.textSoft).lineSpacing(2).fixedSize(horizontal: false, vertical: true)
            }
            switch question.answerSchema.type {
            case "date":
                DatePicker("Date", selection: $day, in: range, displayedComponents: .date)
                    .datePickerStyle(.graphical).tint(Color(light: 0x00768B, dark: 0x5CCBE0))
                    .environment(\.locale, Locale(identifier: "en_GB"))
                    .environment(\.calendar, PaperworkDay.berlin)
                    .padding(8).card(radius: 20)
                PaperworkButton(title: "Confirm \(day.formatted(.dateTime.day().month(.wide).year().locale(Locale(identifier: "en_GB"))))", grow: true, working: pending) {
                    answer(.text(PaperworkDay.text(day)), "value")
                }
            case "integer":
                Stepper(value: $number, in: 0...600) {
                    Text("\(number)").font(.posterGrotesque(30)).monospacedDigit().contentTransition(.numericText())
                }
                .padding(.horizontal, 16).frame(minHeight: 64).card(radius: 18)
                PaperworkButton(title: "Confirm \(number)", grow: true, working: pending) { answer(.number(number), "value") }
            default:
                VStack(spacing: 8) {
                    ForEach(Array(question.choices.enumerated()), id: \.offset) { _, choice in
                        let key = Self.key(choice.value)
                        let on = chosen == key || (chosen == nil && current == key)
                        Button {
                            withAnimation(.snappy) { chosen = key }
                            Task { try? await Task.sleep(for: .seconds(0.32)); answer(choice.value, "value") }
                        } label: {
                            HStack {
                                Text(choice.label).font(.geist(16.5, .semibold)).foregroundStyle(Palette.text).multilineTextAlignment(.leading)
                                Spacer()
                                if pending && chosen == key { ProgressView().controlSize(.small) } else {
                                    Image(systemName: "checkmark").font(.system(size: 15, weight: .bold)).foregroundStyle(Color(light: 0x00768B, dark: 0x73D3E6))
                                        .opacity(on ? 1 : 0).scaleEffect(on ? 1 : 0.5)
                                }
                            }
                            .padding(.horizontal, 16).padding(.vertical, 12).frame(minHeight: 56)
                            .background(on ? Palette.selectBackground : Palette.surface, in: .rect(cornerRadius: 16))
                            .overlay { RoundedRectangle(cornerRadius: 16).strokeBorder(on ? Color(light: 0x00768B, dark: 0x5CCBE0) : Palette.border, lineWidth: on ? 1.5 : 1) }
                            .contentShape(.rect)
                        }
                        .buttonStyle(PressStyle())
                        .disabled(pending)
                        .accessibilityAddTraits(on ? [.isSelected] : [])
                    }
                }
            }
            HStack(spacing: 18) {
                if question.canAnswerUnknown != false {
                    Button("I’m not sure") { answer(.none, "unknown") }
                    Button("Prefer not to say") { answer(.none, "declined") }
                }
                if question.answerSchema.allowsNotApplicable == true { Button("Doesn’t apply") { answer(.none, "not_applicable") } }
                Spacer(minLength: 0)
                if canSkip, let skip { Button("Skip for now", action: skip) }
            }
            .font(.geist(15, .medium)).foregroundStyle(Palette.textSoft).buttonStyle(PressStyle())
            .frame(minHeight: 44).disabled(pending)
        }
        .onAppear {
            if let current, question.answerSchema.type == "date", let date = PaperworkDay.date(current) { day = date }
            if let current, question.answerSchema.type == "integer", let value = Int(current) { number = value }
        }
    }

    /// Something that happened (moved in, arrived) can't be in the future; an expiry can be either side.
    private var range: ClosedRange<Date> {
        let calendar = PaperworkDay.berlin
        let far = calendar.date(byAdding: .year, value: -60, to: .now)!, ahead = calendar.date(byAdding: .year, value: 15, to: .now)!
        return question.answerSchema.dateSemantics == "historical" ? far...Date.now : far...ahead
    }

    static func key(_ answer: PaperworkAnswer) -> String {
        switch answer {
        case let .text(value): value
        case let .bool(value): value ? "true" : "false"
        case let .number(value): String(value)
        case .none: ""
        }
    }
}
