import API
import Core
import CoreLocation
import DesignSystem
import SafariServices
import SwiftUI

/// Today, as the canvas draws it: the greeting and two chips, Your day as a line from now through
/// the plans, ideas for this part of the day, Cologne today, Paperwork, where to pick up, what's
/// saved, what's coming up, and the city's own links. Every part is from the server's real data;
/// a part with nothing real behind it isn't drawn.
struct TodayView: View {
    @Environment(Session.self) private var session
    @Environment(\.shell) private var shell
    @Environment(TodayModel.self) private var model
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var sheet: TodaySheet?
    @State private var position = ScrollPosition(edge: .top)
    /// The canvas's .hd.intro: the greeting rises and the chips spring in once the day is read.
    @State private var intro = false
    @State private var pull: CGFloat = 0
    @State private var refresh = Refresh.idle
    @State private var loadingSince = Date.now
    /// The lead, steady while the page is read (the web's useSteadyLead): set on load and when plans
    /// or whether there are ideas change, never because the clock moved.
    @State private var lead = (key: "", lead: TodayPage.Lead.day)

    enum Part: Hashable { case day, ideas, city, paperwork, pickUp, saved, comingUp, links }

    /// The canvas's order per lead (Today.dc.html ORDER): what leads, then the city, then the rest.
    static func parts(_ lead: TodayPage.Lead) -> [Part] {
        let first: [Part] = switch lead {
        case .day: [.day, .ideas]
        case .ideas: [.ideas, .day]
        case .paperwork: [.paperwork, .ideas, .day]
        }
        return first + [.city] + (lead == .paperwork ? [] : [.paperwork]) + [.pickUp, .saved, .comingUp, .links]
    }

    /// Pull to check again, as the canvas's .ptr: the mark grows with the pull, waves while it
    /// checks, then says when it last did.
    enum Refresh: Equatable { case idle, busy(Date), done(String) }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 26) {
                switch model.phase {
                case .loading:
                    Greeting(page: nil, name: session.name, intro: true)
                    VStack(spacing: 8) {
                        WavingMark(since: loadingSince, cycles: nil).frame(width: 34, height: 28).foregroundStyle(Palette.text)
                        Text("Checking your day…").font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft)
                    }
                    .frame(maxWidth: .infinity).padding(.top, 60)
                    .transition(.opacity)
                case let .problem(text):
                    Greeting(page: nil, name: session.name, intro: true)
                    VStack(spacing: 14) {
                        Text(text).font(.geist(16, .medium)).foregroundStyle(Palette.textSoft).multilineTextAlignment(.center)
                        Button("Try again") { Task { await model.load(session.api) } }
                            .font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                            .padding(.horizontal, 18).frame(height: 44)
                            .background(Palette.quiet, in: .capsule)
                    }
                    .frame(maxWidth: .infinity).padding(.top, 40)
                case .ready:
                    if let page = model.page {
                        VStack(alignment: .leading, spacing: 12) {
                            Greeting(page: page, name: session.name, intro: intro)
                            Chips(page: page, sheet: $sheet, intro: intro)
                        }
                        ForEach(Self.parts(lead.lead), id: \.self) { part in
                            switch part {
                            case .day: YourDay(page: page)
                            case .ideas: if !model.shownIdeas.isEmpty { Ideas(page: page, sheet: $sheet) }
                            case .city: CologneToday(page: page, sheet: $sheet)
                            case .paperwork: PaperworkCard(paperwork: page.paperwork)
                            case .pickUp: if let continuation = page.continuation { PickUp(continuation: continuation) }
                            case .saved: if let saved = page.saved { SavedForLater(saved: saved, date: page.date) }
                            case .comingUp: ComingUp(page: page)
                            case .links: CityLinks(sheet: $sheet)
                            }
                        }
                    }
                }
            }
            .padding(.horizontal, 16)
            .padding(.top, refresh == .idle ? 14 : 78)
            .padding(.bottom, 24)
            .animation(reduceMotion ? nil : .snappy(duration: 0.35), value: model.page)
        }
        .scrollPosition($position)
        .scrollIndicators(.hidden)
        .background(Palette.canvas)
        .overlay(alignment: .top) { PullMark(pull: pull, refresh: refresh).padding(.top, 10) }
        .onScrollGeometryChange(for: CGFloat.self) { max(0, -($0.contentOffset.y + $0.contentInsets.top)) } action: { _, now in pull = now }
        .onScrollGeometryChange(for: CGFloat.self) { $0.contentOffset.y + $0.contentInsets.top } action: { _, now in shell.scrolled(now) }
        .onScrollPhaseChange { old, now in
            if old == .interacting, now != .interacting, pull > PullMark.reach, refresh == .idle { Task { await check() } }
        }
        .sensoryFeedback(.impact(weight: .light), trigger: pull > PullMark.reach)
        .task { await model.start(session.api) }
        .onChange(of: model.phase) { _, phase in
            guard phase == .ready, !intro else { return }
            withAnimation(reduceMotion ? nil : .spring(duration: 0.55, bounce: 0.3)) { intro = true }
        }
        .onChange(of: model.page?.leadKey) { _, key in
            guard let page = model.page, let key, key != lead.key else { return }
            withAnimation(reduceMotion || lead.key.isEmpty ? nil : .spring(duration: 0.5, bounce: 0.2)) { lead = (key, page.lead) }
        }
        .onChange(of: model.topRequests) { withAnimation(.snappy) { position.scrollTo(edge: .top) } }
        .overlay(alignment: .bottom) {
            if let note = model.note {
                NoteBar(note: note)
                    .padding(.horizontal, 16).padding(.bottom, 12)
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .id(note.id)
            }
        }
        .animation(reduceMotion ? nil : .spring(duration: 0.4, bounce: 0.25), value: model.note)
        .sheet(item: $sheet) { sheet in
            switch sheet {
            case let .weather(weather): WeatherSheet(weather: weather)
            case let .ideas(page): IdeasSheet(page: page)
            case let .link(url): SafariView(url: url).ignoresSafeArea()
            case let .addPlan(date): AddPlanSheet(date: date)
            case let .why(idea, when): WhySheet(idea: idea, when: when, sheet: $sheet)
            case let .howWeRank(on): HowWeRankSheet(personalised: on)
            case let .agree(pause): AgreeSheet(pause: pause)
            }
        }
    }

    /// The canvas's own timing: the check, then "Up to date" for 0.9 s, then the page slides back.
    private func check() async {
        withAnimation(.snappy) { refresh = .busy(.now) }
        await model.load(session.api)
        withAnimation(.snappy) { refresh = .done(TodayPage.clock(.now)) }
        try? await Task.sleep(for: .milliseconds(900))
        withAnimation(.snappy) { refresh = .idle }
    }
}

/// The mark over the page while it's pulled or checking.
private struct PullMark: View {
    static let reach: CGFloat = 70
    let pull: CGFloat
    let refresh: TodayView.Refresh

    var body: some View {
        let progress = min(1, pull / Self.reach)
        let shown = refresh != .idle || pull > 4
        VStack(spacing: 6) {
            Group {
                if case let .busy(since) = refresh {
                    WavingMark(since: since, cycles: nil)
                } else {
                    ExpaduMark()
                }
            }
            .frame(width: 34, height: 28)
            .foregroundStyle(Palette.text)
            .scaleEffect(refresh == .idle ? 0.6 + 0.4 * progress : 1)
            Text(words).font(.geist(12.5, .semibold)).foregroundStyle(Palette.textSoft)
                .contentTransition(.opacity)
        }
        // Fades in with the pull, as the canvas's .ptr; full once it's checking.
        .opacity(refresh != .idle ? 1 : shown ? min(1, (pull - 4) / 46) : 0)
        .allowsHitTesting(false)
        .accessibilityHidden(!shown)
    }

    private var words: String {
        switch refresh {
        case .idle: "Pull to check again"
        case .busy: "Checking your day…"
        case let .done(clock): "Up to date · \(clock)"
        }
    }
}

/// What opens over Today, in place: the weather by the hour, all the ideas, a city site.
enum TodaySheet: Identifiable {
    case weather(TodayPage.Weather)
    case ideas(TodayPage)
    case link(URL)
    case addPlan(String)
    case why(TodayPage.Idea, String)
    case howWeRank(Bool)
    case agree(TodayPage.RankPause)

    var id: String {
        switch self {
        case .weather: "weather"
        case .ideas: "ideas"
        case let .link(url): url.absoluteString
        case let .addPlan(date): "add-\(date)"
        case let .why(idea, _): "why-\(idea.id)"
        case .howWeRank: "how-we-rank"
        case .agree: "agree"
        }
    }
}

/// What just changed, in words, with Undo when it can be put back (the canvas's note, never alone:
/// the change itself moves in place too).
private struct NoteBar: View {
    let note: TodayModel.Note
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: note.symbol).font(.system(size: 15, weight: .semibold))
                .foregroundStyle(note.problem ? Palette.conflictText : Color(hex: 0x2F9E5B))
                .symbolEffect(.bounce, value: note.id)
            Text(note.text).font(.geist(14.5, .semibold)).foregroundStyle(Palette.text).lineLimit(2)
            Spacer(minLength: 0)
            if note.undo != nil {
                Button("Undo") { Task { await model.undo(session.api) } }
                    .font(.geist(14.5, .semibold)).foregroundStyle(TodayLink.ink)
                    .frame(minHeight: 44)
            }
        }
        .padding(.horizontal, 16).frame(minHeight: 52)
        .glassEffect(.regular, in: .capsule)
        .accessibilityElement(children: .combine)
    }
}

// MARK: - The top

private struct Greeting: View {
    let page: TodayPage?
    let name: String
    let intro: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(TodayPage.greeting(at: page?.now ?? .now, name: name))
                .font(.posterGrotesque(34)).tracking(-0.5)
                .lineLimit(1).minimumScaleFactor(0.7)
            if let page {
                Text(page.dateLine).font(.geist(15, .medium)).foregroundStyle(Palette.textSoft)
            }
        }
        .foregroundStyle(Palette.text)
        .padding(.horizontal, 4)
        .opacity(intro ? 1 : 0).offset(y: intro ? 0 : 10)
        .accessibilityElement(children: .combine)
        .accessibilityAddTraits(.isHeader)
    }
}

/// The weather now and the ideas for now, side by side; each opens its sheet.
private struct Chips: View {
    let page: TodayPage
    @Binding var sheet: TodaySheet?
    let intro: Bool
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        HStack(spacing: 8) {
            if let weather = page.weather {
                chip(symbol: WeatherSymbol.name(weather.icon), tint: WeatherSymbol.tint(weather.icon), title: "\(weather.temperature)°",
                     note: weather.summary ?? weather.condition, order: 0, spins: true) { sheet = .weather(weather) }
            }
            if !page.ideas.isEmpty {
                chip(symbol: "calendar", tint: Color(hex: 0x6B4FD0), title: "\(page.ideas.count) events", note: page.ideasWhen,
                     order: page.weather == nil ? 0 : 1, spins: false) { sheet = .ideas(page) }
            }
        }
    }

    /// Each chip springs up in turn (the canvas's chipin, 0.6 s); the sun turns in (sunin, 1.1 s, after 0.3 s).
    private func chip(symbol: String, tint: Color, title: String, note: String, order: Double, spins: Bool, open: @escaping () -> Void) -> some View {
        Button(action: open) {
            HStack(spacing: 9) {
                Image(systemName: symbol).symbolRenderingMode(.multicolor).font(.system(size: 19, weight: .semibold))
                    .foregroundStyle(tint)
                    .rotationEffect(.degrees(spins && !intro ? -90 : 0)).scaleEffect(spins && !intro ? 0.4 : 1)
                    .animation(reduceMotion ? nil : .spring(duration: 1.1, bounce: 0.35).delay(0.3), value: intro)
                    .frame(width: 40, height: 40)
                    .background(tint.opacity(0.15), in: .rect(cornerRadius: 14))
                VStack(alignment: .leading, spacing: 1) {
                    Text(title).font(.posterGrotesque(16)).foregroundStyle(Palette.text)
                    Text(note).font(.geist(12, .medium)).foregroundStyle(Palette.textSoft).lineLimit(1)
                }
                Spacer(minLength: 0)
            }
            .padding(.leading, 8).padding(.trailing, 10)
            .frame(maxWidth: .infinity, minHeight: 58)
            .card(radius: 20)
            .shadow(color: Color(hex: 0x19140C, opacity: 0.12), radius: 6, y: 5)
        }
        .buttonStyle(PressStyle())
        .opacity(intro ? 1 : 0).offset(y: intro ? 0 : 12).scaleEffect(intro ? 1 : 0.9)
        .animation(reduceMotion ? nil : .spring(duration: 0.6, bounce: 0.3).delay(0.08 + 0.08 * order), value: intro)
    }
}

// MARK: - Your day

/// The day as a line from now down through the plans; the next one is big and carries its notes,
/// what to bring and the Leave card; free time between shows what's open then.
private struct YourDay: View {
    let page: TodayPage
    @State private var doneOpen = false

    private var done: [TodayPage.Plan] { page.plans.filter(\.completed) }

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            SectionHead(title: "Your day") {
                if !page.ahead.isEmpty {
                    Text(page.ahead.count == 1 ? "1 plan" : "\(page.ahead.count) plans").font(.geist(13.5, .medium)).foregroundStyle(Palette.textSoft)
                }
            }
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 8) {
                    PulseDot()
                    Text("Now · \(TodayPage.clock(page.now))").font(.geist(13, .bold)).foregroundStyle(Self.line)
                }
                .frame(height: 22).padding(.bottom, 6)
                if !done.isEmpty { DoneFold(page: page, done: done, open: $doneOpen) }
                if page.free?.anchorTitle == nil, let free = page.free { FreeTime(page: page, free: free) }
                if page.ahead.isEmpty {
                    VStack(alignment: .leading, spacing: 3) {
                        Text(page.plans.isEmpty ? "No plans added for today" : "No more plans today").font(.geist(16, .semibold))
                        Text("Your day is open.").font(.geist(13.5)).foregroundStyle(Palette.textSoft)
                    }
                    .foregroundStyle(Palette.text)
                    .padding(.vertical, 10)
                }
                ForEach(Array(page.ahead.enumerated()), id: \.element.id) { index, plan in
                    Stop(page: page, plan: plan, next: index == 0)
                    if let free = page.free, free.anchorTitle == plan.title {
                        FreeTime(page: page, free: free)
                    } else if let link = page.link(after: plan), index < page.ahead.count - 1 {
                        LinkLine(link: link)
                    }
                }
                AddPlan(date: page.date)
            }
            .padding(.top, 14).padding(.trailing, 14).padding(.bottom, 6).padding(.leading, 40)
            .background(alignment: .leading) {
                Rectangle().fill(Self.line).frame(width: 3).clipShape(.capsule)
                    .padding(.top, 26).padding(.bottom, 30).padding(.leading, 21)
            }
            .card(radius: 22)
        }
    }

    static let line = Color(hex: 0xE6421F)
}

private struct PulseDot: View {
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var on = false

    var body: some View {
        Circle().fill(YourDay.line).frame(width: 13, height: 13)
            .background {
                Circle().fill(YourDay.line.opacity(0.35))
                    .scaleEffect(on ? 2.2 : 1).opacity(on ? 0 : 1)
            }
            .offset(x: -25)
            .padding(.trailing, -13)
            .onAppear {
                guard !reduceMotion else { return }
                withAnimation(.easeOut(duration: 1.8).repeatForever(autoreverses: false)) { on = true }
            }
            .accessibilityHidden(true)
    }
}

/// A plan on the line. The next one is big, with its deadline, what to bring and the Leave card.
private struct Stop: View {
    let page: TodayPage
    let plan: TodayPage.Plan
    let next: Bool
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(alignment: .top, spacing: 8) {
                VStack(alignment: .leading, spacing: 0) {
                    Text(plan.start ?? "Today")
                        .font(.posterGrotesque(next ? 46 : 19)).tracking(next ? -1 : 0).monospacedDigit()
                    Text(plan.title)
                        .font(next ? .posterGrotesque(22) : .geist(16, .semibold))
                        .tracking(next ? -0.2 : 0)
                        .padding(.top, next ? 6 : 1)
                    if let meta {
                        Text(meta).font(.geist(13.5)).foregroundStyle(Palette.textSoft).padding(.top, 2)
                    }
                }
                Spacer(minLength: 0)
                Menu {
                    Button("Mark as done", systemImage: "checkmark.circle") {
                        Task { await model.markDone(session.api, plan: plan, date: page.date) }
                    }
                    if plan.editable {
                        Button("Remove", systemImage: "trash", role: .destructive) {
                            Task { await model.change(session.api, action: "remove", id: plan.id, date: page.date, symbol: "trash") }
                        }
                    }
                } label: {
                    Image(systemName: "ellipsis").font(.system(size: 17, weight: .semibold)).foregroundStyle(Palette.textSoft)
                        .frame(width: 44, height: 44).contentShape(.rect)
                }
                .accessibilityLabel("More for \(plan.title)")
            }
            if next {
                if let deadline = page.deadline(for: plan) {
                    DeadlineNote(deadline: deadline).padding(.top, 10)
                }
                if let bring = plan.bring {
                    BringCard(bring: bring).padding(.top, 10)
                }
                if let leave = page.leave, leave.isKnown {
                    LeaveCard(leave: leave, place: plan.shortPlace).padding(.top, 12)
                }
            }
        }
        .foregroundStyle(Palette.text)
        .padding(.top, 6).padding(.bottom, 14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .overlay(alignment: .topLeading) {
            Circle().fill(Palette.surface).frame(width: 11, height: 11)
                .overlay { Circle().strokeBorder(YourDay.line, lineWidth: 3) }
                .offset(x: -24, y: next ? 22 : 12)
        }
        .accessibilityElement(children: .combine)
    }

    private var meta: String? {
        let place = plan.place ?? plan.subtitle
        let parts = [place, plan.end.map { "until \($0)" }].compactMap { $0 }
        return parts.isEmpty ? nil : parts.joined(separator: " · ")
    }
}

/// Paperwork's urgent date, under the appointment it's for.
private struct DeadlineNote: View {
    let deadline: TodayPage.Deadline

    var body: some View {
        HStack(alignment: .firstTextBaseline, spacing: 8) {
            Image(systemName: "clock").font(.system(size: 14, weight: .semibold)).foregroundStyle(Color(hex: 0xC98208))
            (Text(deadline.headline + ".").font(.geist(14, .semibold)).foregroundStyle(Palette.text)
                + Text(" This appointment is the next step.").font(.geist(14)).foregroundStyle(Palette.textSoft))
        }
    }
}

/// What to bring, from Paperwork: "1 of 5 documents ready".
private struct BringCard: View {
    let bring: TodayPage.Plan.Bring
    private let ink = Color(hex: 0x4E3774)

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: "doc.text.fill").font(.system(size: 16)).foregroundStyle(ink)
            VStack(alignment: .leading, spacing: 1) {
                Text("What to bring · \(bring.title ?? "Paperwork")").font(.geist(12)).lineLimit(1)
                Text("\(bring.ready) of \(bring.total) documents ready").font(.geist(15, .bold))
            }
            Spacer(minLength: 0)
            Image(systemName: "chevron.right").font(.system(size: 12, weight: .semibold))
        }
        .foregroundStyle(ink)
        .padding(.horizontal, 14).padding(.vertical, 12)
        .background(Palette.avatarBackground, in: .rect(cornerRadius: 16))
    }
}

/// When to leave: a ring counting down, the line, when you're there, the minutes to spare,
/// the walk to the platform, and the trip's two actions.
private struct LeaveCard: View {
    let leave: TodayPage.Leave
    let place: String?
    private let warn = Color(hex: 0xC98208)

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 12) {
                ZStack {
                    Circle().stroke(Palette.quiet, lineWidth: 4)
                    Circle().trim(from: 0, to: ringFill).stroke(warn, style: StrokeStyle(lineWidth: 4, lineCap: .round)).rotationEffect(.degrees(-90))
                    VStack(spacing: -1) {
                        Text("\(leave.leaveInMinutes ?? 0)").font(.posterGrotesque(15)).contentTransition(.numericText(countsDown: true))
                        Text("min").font(.geist(9, .semibold)).foregroundStyle(Palette.textSoft)
                    }
                }
                .frame(width: 46, height: 46)
                VStack(alignment: .leading, spacing: 3) {
                    Text("Leave at \(leave.leaveTime ?? "")").font(.geist(17, .bold))
                    if leave.live {
                        HStack(spacing: 5) {
                            Circle().frame(width: 7, height: 7)
                            Text("Live · KVB").font(.geist(12.5, .bold))
                        }
                        .foregroundStyle(Color(hex: 0x2F9E5B))
                    }
                }
            }
            HStack(spacing: 6) {
                if let line = leave.line, let departs = leave.departs {
                    tile(label: nil, badge: Board.badge(line), color: Color(hexString: leave.color ?? "#1A4CD4"), value: departs, note: leave.from ?? "")
                }
                tile(label: "There", badge: nil, color: .clear, value: leave.arriveTime ?? "", note: place ?? leave.to)
                if let spare = leave.spareMinutes, spare < 0 {
                    // Late: by how much, in the warning colour, never a negative spare.
                    tile(label: "Late", badge: nil, color: .clear, value: "\(-spare) min", note: leave.start.map { "after \($0)" } ?? "", warning: true)
                } else if let spare = leave.spareMinutes {
                    tile(label: "Spare", badge: nil, color: .clear, value: "\(spare) min", note: leave.start.map { "before \($0)" } ?? "")
                }
            }
            if let note = leave.note {
                Label(note, systemImage: "figure.walk").font(.geist(12.5)).foregroundStyle(Palette.textSoft)
                    .labelStyle(Tight())
            }
            HStack(spacing: 8) {
                Label("I’m on my way", systemImage: "figure.walk")
                    .font(.geist(15, .semibold)).foregroundStyle(.white)
                    .frame(maxWidth: .infinity).frame(height: 44)
                    .background(Palette.action, in: .capsule)
                Label("Other ways", systemImage: "arrow.triangle.branch")
                    .font(.geist(15, .medium)).foregroundStyle(Palette.text)
                    .padding(.horizontal, 16).frame(height: 44)
                    .background(Palette.quiet, in: .capsule)
            }
            .labelStyle(Tight())
        }
        .padding(14)
        .background(Palette.surface, in: .rect(cornerRadius: 20))
        .overlay { RoundedRectangle(cornerRadius: 20).strokeBorder(warn.opacity(0.55), lineWidth: 1.5) }
    }

    /// Fuller as the time to leave comes closer, over the last quarter hour.
    private var ringFill: Double {
        max(0.04, 1 - Double(min(leave.leaveInMinutes ?? 15, 15)) / 15)
    }

    private func tile(label: String?, badge: String?, color: Color, value: String, note: String, warning: Bool = false) -> some View {
        VStack(alignment: .leading, spacing: 1) {
            if let badge {
                Label(badge, systemImage: "tram.fill").labelStyle(.titleAndIcon)
                    .font(.geist(12, .bold)).foregroundStyle(color.onColor)
                    .padding(.horizontal, 6).frame(height: 20)
                    .background(color, in: .rect(cornerRadius: 5))
            } else if let label {
                Text(label.uppercased()).font(.geist(10.5, .bold)).tracking(0.4).foregroundStyle(warning ? Palette.conflictText : Palette.textSoft)
            }
            Text(value).font(.posterGrotesque(19)).monospacedDigit().foregroundStyle(warning ? Palette.conflictText : Palette.text)
            Text(note).font(.geist(12)).foregroundStyle(Palette.textSoft).lineLimit(1).minimumScaleFactor(0.85)
        }
        .padding(.vertical, 9).padding(.horizontal, 10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Palette.quiet, in: .rect(cornerRadius: 12))
    }
}

/// The way between two plans: "About 25 min · 15 min to spare".
private struct LinkLine: View {
    let link: TodayPage.Link

    var body: some View {
        let words = link.words
        Label(words.line, systemImage: words.tone == .conflict ? "exclamationmark.triangle.fill" : "arrow.down")
            .labelStyle(Tight())
            .font(.geist(13, words.tone == .ok ? .regular : .semibold))
            .foregroundStyle(words.tone == .conflict ? Palette.conflictText : words.tone == .tight ? Color(hex: 0xA86A00) : Palette.textSoft)
            .padding(.bottom, 14)
    }
}

/// Free time on the line, and what's open then nearby.
private struct FreeTime: View {
    let page: TodayPage
    let free: TodayPage.Free

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            if let line = page.freeLine {
                let parts = line.components(separatedBy: " · ")
                (Text(parts[0]).font(.geist(14, .semibold)).foregroundStyle(Palette.text)
                    + Text(parts.count > 1 ? " · " + parts.dropFirst().joined(separator: " · ") : "").font(.geist(14)).foregroundStyle(Palette.textSoft))
            }
            VStack(alignment: .leading, spacing: 0) {
                Text(free.heading).font(.geist(12.5, .bold)).foregroundStyle(Palette.textSoft)
                    .padding(.horizontal, 12).padding(.top, 8).padding(.bottom, 2)
                ForEach(free.places) { place in
                    HStack(spacing: 12) {
                        Image(systemName: PlaceSymbol.name(place.kind)).font(.system(size: 14, weight: .semibold))
                            .frame(width: 32, height: 32).background(Palette.surface, in: .circle)
                        VStack(alignment: .leading, spacing: 1) {
                            Text(place.name).font(.geist(15.5, .semibold)).lineLimit(1)
                            Text(place.line).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1)
                        }
                        Spacer(minLength: 0)
                        AddButton(candidate: place.candidate, date: page.date, title: place.name)
                    }
                    .foregroundStyle(Palette.text)
                    .padding(.horizontal, 12).padding(.vertical, 6)
                }
            }
            .padding(.vertical, 4)
            .background(Palette.quiet, in: .rect(cornerRadius: 16))
        }
        .padding(.top, 8).padding(.bottom, 16)
    }
}

private struct AddPlan: View {
    let date: String
    @State private var open = false

    var body: some View {
        Button { open = true } label: {
            Label("Add a plan", systemImage: "plus")
                .labelStyle(Tight())
                .font(.geist(15.5, .semibold)).foregroundStyle(Palette.action)
                .frame(height: 50).contentShape(.rect)
        }
        .buttonStyle(PressStyle())
        .sheet(isPresented: $open) { AddPlanSheet(date: date) }
    }
}

/// Plans marked done today, folded away; each can be put back.
private struct DoneFold: View {
    let page: TodayPage
    let done: [TodayPage.Plan]
    @Binding var open: Bool
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Button { withAnimation(.snappy) { open.toggle() } } label: {
                HStack(spacing: 6) {
                    Image(systemName: "checkmark").font(.system(size: 12, weight: .bold))
                    Text("\(done.count) done").font(.geist(13.5, .semibold))
                    Image(systemName: "chevron.down").font(.system(size: 11, weight: .semibold)).rotationEffect(.degrees(open ? 180 : 0))
                }
                .foregroundStyle(Palette.textSoft)
                .frame(minHeight: 32)
            }
            .buttonStyle(PressStyle())
            if open {
                ForEach(done) { plan in
                    HStack(spacing: 8) {
                        Text([plan.start, plan.title].compactMap { $0 }.joined(separator: " ")).font(.geist(14, .semibold)).strikethrough()
                            .foregroundStyle(Palette.textSoft)
                        Spacer(minLength: 0)
                        Button("Restore") { Task { await model.restore(session.api, plan: plan, date: page.date) } }
                            .font(.geist(13, .semibold)).foregroundStyle(TodayLink.ink)
                    }
                    .transition(.opacity.combined(with: .move(edge: .top)))
                }
            }
        }
        .padding(.bottom, 10)
    }
}

/// A plan of their own: what, when, where (the web's Add a plan).
private struct AddPlanSheet: View {
    let date: String
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @State private var name = ""
    @State private var place = ""
    @State private var start = Calendar.current.date(byAdding: .hour, value: 1, to: .now) ?? .now
    @State private var end = Calendar.current.date(byAdding: .hour, value: 2, to: .now) ?? .now
    @State private var saving = false

    var body: some View {
        NavigationStack {
            Form {
                TextField("What", text: $name)
                TextField("Where (optional)", text: $place)
                DatePicker("Starts", selection: $start, displayedComponents: .hourAndMinute)
                DatePicker("Ends", selection: $end, in: start..., displayedComponents: .hourAndMinute)
            }
            .navigationTitle("Add a plan")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel", systemImage: "xmark") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Add", systemImage: "checkmark") {
                        saving = true
                        Task {
                            let added = await model.addPlan(session.api, name: name, date: date, start: TodayPage.clock(start), end: TodayPage.clock(end), place: place)
                            saving = false
                            if added { dismiss() }
                        }
                    }
                    .disabled(name.trimmingCharacters(in: .whitespaces).isEmpty || saving)
                }
            }
        }
        .presentationDetents([.medium])
    }
}

/// Add an event or a place to a day's plan, in place: the + turns to a check once it's in.
private struct AddButton: View {
    let candidate: String
    let date: String
    let title: String
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model

    var body: some View {
        let added = model.added.contains(candidate)
        Button { Task { await model.change(session.api, action: "add", date: date, candidate: candidate, symbol: "calendar.badge.plus") } } label: {
            Image(systemName: added ? "checkmark" : "plus")
                .font(.system(size: 16, weight: .semibold))
                .foregroundStyle(added ? Color(hex: 0x2F9E5B) : Palette.action)
                .contentTransition(.symbolEffect(.replace))
                .frame(width: 36, height: 36).contentShape(.circle)
        }
        .buttonStyle(PressStyle())
        .disabled(added || candidate.isEmpty)
        .accessibilityLabel(added ? "\(title) is in your plan" : "Add \(title) to your plan")
        .sensoryFeedback(.success, trigger: added)
    }
}

// MARK: - Ideas

/// Events for this part of the day, each a small tilted poster with its time.
private struct Ideas: View {
    let page: TodayPage
    @Binding var sheet: TodaySheet?
    @Environment(TodayModel.self) private var model

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            SectionHead(title: page.ideasTitle) {
                Button { sheet = .ideas(page) } label: {
                    Label("All events", systemImage: "arrow.right").labelStyle(TrailingIcon())
                        .font(.geist(14.5, .semibold)).foregroundStyle(TodayLink.ink)
                        .frame(minHeight: 44)
                }
                .buttonStyle(PressStyle())
            }
            if let sub {
                Text(sub).font(.geist(13.5)).foregroundStyle(Palette.textSoft).padding(.horizontal, 4).padding(.top, -6)
            }
            if let pause = page.rankPaused, !model.pauseSetAside {
                PausedLine(pause: pause, sheet: $sheet).padding(.top, 10)
                    .transition(.opacity.combined(with: .move(edge: .top)))
            }
            VStack(spacing: 0) {
                ForEach(Array(model.shownIdeas.prefix(4).enumerated()), id: \.element.id) { index, idea in
                    if index > 0 { Divider().overlay(Palette.border) }
                    IdeaRow(idea: idea, now: page.now, tilt: [-3, 2, -2, 3][index % 4], date: page.date, tomorrow: page.tomorrowDate,
                            onWhy: { sheet = .why(idea, idea.whenLine(at: page.now)) })
                        .transition(.asymmetric(insertion: .opacity, removal: .opacity.combined(with: .move(edge: .trailing))))
                }
            }
            .card(radius: 22)
            .padding(.top, 12)
            // UWG § 5b: every ranked list says how it's put in order.
            Button { sheet = .howWeRank(page.personalised) } label: {
                Label("How we rank", systemImage: "list.number").labelStyle(Tight())
                    .font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft)
                    .frame(minHeight: 36)
            }
            .buttonStyle(PressStyle())
            .padding(.horizontal, 4).padding(.top, 4)
        }
    }

    private var sub: String? {
        guard let weather = page.weather else { return nil }
        return "\(page.ideasWhen) · \((weather.summary ?? weather.condition).lowercased()), \(weather.temperature)°"
    }
}

private struct IdeaRow: View {
    let idea: TodayPage.Idea
    let now: Date
    let tilt: Double
    var date: String = ""
    var tomorrow: String?
    var onWhy: (() -> Void)?
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model

    var body: some View {
        HStack(spacing: 12) {
            Poster(label: idea.posterLabel(at: now), night: night, tilt: tilt)
            VStack(alignment: .leading, spacing: 2) {
                Text(idea.title).font(.geist(15, .semibold)).foregroundStyle(Palette.text).lineLimit(2)
                Text(idea.whenLine(at: now)).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(2)
                if let why = idea.why, let onWhy {
                    Button(action: onWhy) {
                        Label(why, systemImage: "info.circle").labelStyle(Tight())
                            .font(.geist(12.5, .medium)).foregroundStyle(TodayLink.ink)
                            .multilineTextAlignment(.leading).lineLimit(2)
                    }
                    .buttonStyle(PressStyle())
                    .padding(.top, 2)
                    .accessibilityLabel("Why am I seeing this? \(why)")
                }
            }
            Spacer(minLength: 0)
            HStack(spacing: 4) {
                Button { Task { await model.toggleSave(session.api, idea: idea) } } label: {
                    Image(systemName: idea.saved ? "bookmark.fill" : "bookmark")
                        .font(.system(size: 16, weight: .semibold))
                        .foregroundStyle(idea.saved ? TodayLink.ink : Palette.textSoft)
                        .contentTransition(.symbolEffect(.replace))
                        .symbolEffect(.bounce, value: idea.saved)
                        .frame(width: 36, height: 36).contentShape(.circle)
                }
                .buttonStyle(PressStyle())
                .accessibilityLabel(idea.saved ? "Saved: \(idea.title)" : "Save \(idea.title)")
                .sensoryFeedback(.selection, trigger: idea.saved)
                AddButton(candidate: idea.candidate, date: idea.tomorrow ? (tomorrow ?? date) : date, title: idea.title)
            }
        }
        .padding(.horizontal, 14).padding(.vertical, 10)
    }

    /// Evening listings are the night poster; daytime, all-day and on-now ones the day poster.
    private var night: Bool {
        guard idea.timed, let start = idea.startsAt, start > now else { return false }
        return TodayPage.clock(start) >= "17:00"
    }
}

/// The canvas's poster thumb: a coloured card, a little tilted, its time in the corner.
private struct Poster: View {
    let label: String
    let night: Bool
    let tilt: Double

    var body: some View {
        let ink = Color(hex: night ? 0xFFE363 : 0x3D2F9E)
        Text(label)
            .font(.posterGrotesque(label.count > 5 ? 13 : 15))
            .foregroundStyle(ink)
            .lineLimit(2).minimumScaleFactor(0.8)
            .padding(6)
            .frame(width: 54, height: 68, alignment: .bottomLeading)
            .background(Color(hex: night ? 0x3D2F9E : 0xFFE363), in: .rect(cornerRadius: 7))
            .shadow(color: Color(hex: 0x211D15, opacity: 0.18), radius: 3, y: 2)
            .rotationEffect(.degrees(tilt))
            .accessibilityHidden(true)
    }
}

/// Their order waits for a yes to a changed notice (the web's AiOrderPausedLine; no AI words outside Settings).
private struct PausedLine: View {
    let pause: TodayPage.RankPause
    @Binding var sheet: TodaySheet?
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: "clock").font(.system(size: 13, weight: .semibold))
            Text("In the usual order until you agree to a change.").font(.geist(13)).fixedSize(horizontal: false, vertical: true)
            Spacer(minLength: 4)
            Button("Review") { sheet = .agree(pause) }.font(.geist(13, .semibold)).foregroundStyle(TodayLink.ink)
            Button("Not now") { Task { await model.orderLater(session.api) } }.font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft)
        }
        .foregroundStyle(Palette.textSoft)
        .padding(.horizontal, 12).padding(.vertical, 8)
        .background(Palette.quiet, in: .rect(cornerRadius: 14))
    }
}

/// Why am I seeing this? (the web's WhySheet): which of their leanings it matches, and what they can do,
/// each with Undo: fewer like this, or not for me (off Today).
private struct WhySheet: View {
    let idea: TodayPage.Idea
    let when: String
    @Binding var sheet: TodaySheet?
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            List {
                Section {
                    VStack(alignment: .leading, spacing: 3) {
                        Text(idea.title).font(.geist(16, .semibold))
                        Text(when).font(.geist(13)).foregroundStyle(Palette.textSoft)
                    }
                }
                if !idea.reasons.isEmpty {
                    Section {
                        ForEach(idea.reasons, id: \.self) { reason in
                            Label(reason, systemImage: "checkmark").font(.geist(15))
                        }
                    }
                }
                Section {
                    Button("Less like this", systemImage: "minus.circle") {
                        model.lessLikeThis(session.api, idea: idea)
                        dismiss()
                    }
                    Button("Not for me", systemImage: "xmark.circle") {
                        withAnimation(.snappy) { model.notForMe(session.api, idea: idea) }
                        dismiss()
                    }
                    Button("How we rank", systemImage: "list.number") {
                        let on = model.page?.personalised ?? false
                        dismiss()
                        Task { try? await Task.sleep(for: .milliseconds(450)); sheet = .howWeRank(on) }
                    }
                }
                .font(.geist(15, .medium))
                .foregroundStyle(Palette.text)
            }
            .navigationTitle("Why am I seeing this?")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done", systemImage: "checkmark") { dismiss() } } }
        }
        .presentationDetents([.medium, .large])
    }
}

/// How we rank (UWG § 5b, the web's HowWeRank): what puts Today's ideas in order, that nobody pays,
/// and where listings come from.
private struct HowWeRankSheet: View {
    let personalised: Bool
    @Environment(\.dismiss) private var dismiss
    private static let today: [(String, String)] = [
        ("Fits your plan", "Nothing that clashes with a plan whose times are known."),
        ("Near you first", "Within 3 km of your neighbourhood or Home, then the rest, each soonest first. Tomorrow’s only once nothing is left today."),
        ("What you lean towards, if you turned it on", "Put in order from what you open, save and turn down. It never hides one. Off: everyone sees the same order."),
        ("What you turned down", "An event you said isn’t for you stays off."),
    ]

    var body: some View {
        NavigationStack {
            List {
                Section("Today’s ideas") {
                    ForEach(Array(Self.today.enumerated()), id: \.offset) { index, rule in
                        HStack(alignment: .firstTextBaseline, spacing: 10) {
                            Text("\(index + 1)").font(.posterGrotesque(15)).foregroundStyle(Palette.textSoft)
                            VStack(alignment: .leading, spacing: 2) {
                                Text(rule.0).font(.geist(15, .semibold))
                                Text(rule.1).font(.geist(13.5)).foregroundStyle(Palette.textSoft)
                            }
                        }
                    }
                }
                Section("What’s on") { Text("Soonest first, unless you pick evening first or closest first.").font(.geist(14)) }
                Section("Around here") { Text("Nearest first.").font(.geist(14)) }
                Section {
                    Label("Nobody pays to be listed or placed higher.", systemImage: "xmark.circle").font(.geist(14))
                    Text("Events come from koeln.de, the City of Cologne’s open data, KölnTourismus (DatenHub NRW) and a few we add ourselves; exhibitions and all-day attractions are left out. Places come from OpenStreetMap and Overture Maps.")
                        .font(.geist(13.5)).foregroundStyle(Palette.textSoft)
                    LabeledContent("Personalised suggestions", value: personalised ? "On" : "Off").font(.geist(14))
                }
            }
            .navigationTitle("How we rank")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done", systemImage: "checkmark") { dismiss() } } }
        }
        .presentationDetents([.large])
    }
}

/// Agree to the change? (the web's AiOrderChange): what changed, what waits until they agree, what's kept.
private struct AgreeSheet: View {
    let pause: TodayPage.RankPause
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @State private var busy = false

    var body: some View {
        NavigationStack {
            List {
                Section { Text("We changed how Claude Haiku, the AI that puts Today in order, works with Expadu.").font(.geist(14)).foregroundStyle(Palette.textSoft) }
                Section(pause.changed.isEmpty ? "How it works now" : "What changed") {
                    ForEach(pause.changed.isEmpty ? pause.notice : pause.changed, id: \.self) { Label($0, systemImage: "pencil").font(.geist(14)) }
                }
                Section("Paused until you agree") { Label("Today put in order by AI, and its “why” lines", systemImage: "clock").font(.geist(14)) }
                Section("Kept either way") { Label("What Expadu learned, and your settings", systemImage: "checkmark").font(.geist(14)) }
                if !pause.changed.isEmpty {
                    Section("The whole notice") { ForEach(pause.notice, id: \.self) { Text($0).font(.geist(13.5)) } }
                }
                Section { Text("Your yes is saved with the date and this notice.").font(.geist(12.5)).foregroundStyle(Palette.textSoft) }
            }
            .navigationTitle("Agree to the change?")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Not now") { Task { await model.orderLater(session.api) }; dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Agree") {
                        busy = true
                        Task {
                            if await model.agree(session.api, to: pause) { dismiss() }
                            busy = false
                        }
                    }
                    .disabled(busy)
                }
            }
        }
        .presentationDetents([.large])
    }
}

// MARK: - Cologne today

/// The weather for the hours ahead, and the departures nearest you when you want them.
private struct CologneToday: View {
    let page: TodayPage
    @Binding var sheet: TodaySheet?
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.shell) private var shell
    @State private var open = false

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            SectionHead(title: "Cologne today") { EmptyView() }
            VStack(spacing: 0) {
                if let weather = page.weather {
                    Button { sheet = .weather(weather) } label: { WeatherRow(weather: weather) }
                        .buttonStyle(PressStyle())
                    Divider().overlay(Palette.border)
                }
                Button {
                    withAnimation(.snappy) { open.toggle() }
                    if open { Task { await model.loadBoard(session.api) } }
                } label: {
                    HStack(spacing: 10) {
                        Image(systemName: "tram.fill").font(.system(size: 16))
                        (Text("Departures") + Text(model.board?.stops.first.map { " · \($0.name)" } ?? "")
                            + Text(" · nearest you").font(.geist(13.5)).foregroundStyle(Palette.textSoft))
                            .lineLimit(1)
                        Spacer(minLength: 0)
                        Image(systemName: "chevron.down").font(.system(size: 12, weight: .semibold))
                            .rotationEffect(.degrees(open ? 180 : 0))
                    }
                    .font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                    .padding(.horizontal, 14).frame(height: 52)
                    .contentShape(.rect)
                }
                .buttonStyle(PressStyle())
                if open {
                    VStack(spacing: 0) {
                        if let board = model.board {
                            ForEach(board.departures.prefix(4)) { DepartureRow(departure: $0) }
                            if board.departures.isEmpty {
                                Text("Nothing leaves nearby in the next hour.").font(.geist(14)).foregroundStyle(Palette.textSoft).padding(14)
                            }
                        } else {
                            ProgressView().padding(20)
                        }
                        Button { shell.show(.travel) } label: {
                            Label("Open in Travel", systemImage: "chevron.right").labelStyle(TrailingIcon())
                                .font(.geist(14.5, .semibold)).foregroundStyle(TodayLink.ink)
                                .frame(maxWidth: .infinity).frame(height: 48)
                        }
                        .buttonStyle(PressStyle())
                    }
                    .transition(.opacity.combined(with: .move(edge: .top)))
                }
            }
            .clipShape(.rect(cornerRadius: 22))
            .card(radius: 22)
        }
    }
}

private struct WeatherRow: View {
    let weather: TodayPage.Weather

    var body: some View {
        VStack(spacing: 12) {
            HStack(spacing: 10) {
                Image(systemName: WeatherSymbol.name(weather.icon)).symbolRenderingMode(.multicolor)
                    .font(.system(size: 28)).foregroundStyle(WeatherSymbol.tint(weather.icon))
                Text("\(weather.temperature)°").font(.posterGrotesque(30))
                VStack(alignment: .leading, spacing: 1) {
                    Text(weather.summary ?? weather.condition).font(.geist(15, .semibold))
                    if let cycling = weather.cycling {
                        Text(cycling).font(.geist(12.5, .medium)).foregroundStyle(Palette.textSoft).lineLimit(1)
                    }
                }
                Spacer(minLength: 0)
                Image(systemName: "chevron.right").font(.system(size: 12, weight: .semibold)).foregroundStyle(Palette.textSoft)
            }
            if !weather.hours.isEmpty {
                HStack(spacing: 0) {
                    ForEach(weather.hours, id: \.hour) { hour in
                        VStack(spacing: 6) {
                            Text(String(hour.hour.prefix(2))).font(.geist(12.5, .medium)).foregroundStyle(Palette.text)
                            Image(systemName: WeatherSymbol.name(hour.icon ?? "")).symbolRenderingMode(.multicolor)
                                .font(.system(size: 15)).foregroundStyle(WeatherSymbol.tint(hour.icon ?? ""))
                                .frame(height: 18)
                            Text(hour.temperature.map { "\($0)°" } ?? "–").font(.geist(11.5)).foregroundStyle(Palette.textSoft)
                        }
                        .frame(maxWidth: .infinity)
                    }
                }
            }
        }
        .foregroundStyle(Palette.text)
        .padding(.horizontal, 14).padding(.top, 14).padding(.bottom, 12)
        .contentShape(.rect)
    }
}

// MARK: - Paperwork, pick up, saved, coming up, links

private struct PaperworkCard: View {
    let paperwork: TodayPage.Paperwork?
    @Environment(\.shell) private var shell

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            SectionHead(title: "Paperwork") { EmptyView() }
            Button { shell.show(.paperwork) } label: {
                HStack(spacing: 14) {
                    if let paperwork, let ready = paperwork.ready, let total = paperwork.total, total > 0 {
                        ZStack {
                            Circle().stroke(Palette.quiet, lineWidth: 6)
                            Circle().trim(from: 0, to: max(0.03, Double(ready) / Double(total)))
                                .stroke(Color(hex: 0x2F9E5B), style: StrokeStyle(lineWidth: 6, lineCap: .round)).rotationEffect(.degrees(-90))
                            (Text("\(ready)").font(.posterGrotesque(16)) + Text("/\(total)").font(.geist(11, .semibold)).foregroundStyle(Palette.textSoft))
                        }
                        .frame(width: 58, height: 58)
                    } else {
                        Image(systemName: "doc.text.fill").font(.system(size: 20)).frame(width: 58, height: 58).background(Palette.quiet, in: .circle)
                    }
                    VStack(alignment: .leading, spacing: 3) {
                        if let paperwork {
                            if let process = paperwork.processTitle {
                                Text(process.uppercased()).font(.geist(12.5, .semibold)).tracking(0.4).foregroundStyle(Palette.textSoft)
                            }
                            Text("Next: \(paperwork.title.prefix(1).lowercased() + paperwork.title.dropFirst())").font(.geist(16, .semibold)).multilineTextAlignment(.leading)
                            Text("Your situation · Review details").font(.geist(13)).foregroundStyle(Palette.textSoft)
                        } else {
                            Text("SETTLING IN?").font(.geist(12.5, .semibold)).tracking(0.4).foregroundStyle(Palette.textSoft)
                            Text("Get started when you’re ready").font(.geist(16, .semibold))
                            Text("A few questions, then your steps for Cologne.").font(.geist(13)).foregroundStyle(Palette.textSoft)
                        }
                    }
                    Spacer(minLength: 0)
                }
                .foregroundStyle(Palette.text)
                .padding(16)
                .card(radius: 22)
            }
            .buttonStyle(PressStyle())
        }
    }
}

private struct PickUp: View {
    let continuation: TodayPage.Continuation
    @Environment(\.shell) private var shell

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            SectionHead(title: "Pick up where you left off") { EmptyView() }
            Button { shell.openComposer() } label: {
                ListRow(symbol: "sparkle", title: continuation.title,
                        note: ["Composer", continuation.kind == "trip" ? "your trip" : "your plan", continuation.lastAt.map(Self.when)].compactMap { $0 }.joined(separator: " · "),
                        action: "Continue")
                    .card(radius: 22)
            }
            .buttonStyle(PressStyle())
        }
    }

    private static func when(_ date: Date) -> String {
        if Calendar.current.isDateInToday(date) { return "today" }
        if Calendar.current.isDateInYesterday(date) { return "yesterday" }
        return date.formatted(.dateTime.weekday(.abbreviated).day().month(.abbreviated))
    }
}

private struct SavedForLater: View {
    let saved: TodayPage.Saved
    let date: String
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            SectionHead(title: "Saved for later") {
                Text("View all \(saved.count)").font(.geist(14.5, .semibold)).foregroundStyle(TodayLink.ink)
            }
            VStack(spacing: 0) {
                ForEach(Array(saved.items.enumerated()), id: \.element.id) { index, item in
                    if index > 0 { Divider().overlay(Palette.border) }
                    HStack(spacing: 0) {
                        ListRow(symbol: item.type == "place" ? "mappin" : "calendar", title: item.title, note: item.line, action: nil)
                        Button("Add to a plan") {
                            Task { await model.change(session.api, action: "add", date: item.date ?? date, candidate: item.candidate, symbol: "calendar.badge.plus") }
                        }
                        .font(.geist(14, .semibold)).foregroundStyle(TodayLink.ink)
                        .padding(.trailing, 14).frame(minHeight: 44)
                    }
                }
            }
            .card(radius: 22)
        }
    }
}

private struct ComingUp: View {
    let page: TodayPage
    @Environment(\.shell) private var shell
    @State private var open = true

    var body: some View {
        VStack(spacing: 0) {
            Button { withAnimation(.snappy) { open.toggle() } } label: {
                HStack(spacing: 10) {
                    Image(systemName: "calendar").font(.system(size: 16))
                    (Text("Coming up") + Text(" · \(days)").font(.geist(13.5)).foregroundStyle(Palette.textSoft))
                    Spacer(minLength: 0)
                    Image(systemName: "chevron.down").font(.system(size: 12, weight: .semibold)).rotationEffect(.degrees(open ? 180 : 0))
                }
                .font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                .padding(.horizontal, 14).frame(height: 52)
                .contentShape(.rect)
            }
            .buttonStyle(PressStyle())
            if open {
                VStack(spacing: 0) {
                    Divider().overlay(Palette.border)
                    Button { shell.openComposer() } label: {
                        ListRow(symbol: "calendar", title: page.tomorrowLine ?? "Tomorrow", note: tomorrowNote, action: page.tomorrow.isEmpty ? "Plan it" : "Open")
                    }
                    .buttonStyle(PressStyle())
                    ForEach(Array(page.later.enumerated()), id: \.offset) { _, later in
                        Divider().overlay(Palette.border)
                        ListRow(symbol: "calendar", title: "\(later.date.map(TodayPage.shortDay) ?? "") · \(later.title)",
                                note: [later.start, later.place].compactMap { $0 }.joined(separator: " · "), action: nil)
                    }
                }
                .transition(.opacity)
            }
        }
        .clipShape(.rect(cornerRadius: 22))
        .card(radius: 22)
    }

    private var days: String {
        let count = 1 + Set(page.later.compactMap(\.date)).count
        return count == 1 ? "1 day" : "\(count) days"
    }

    private var tomorrowNote: String {
        if page.tomorrow.isEmpty { return "Nothing planned yet" }
        return page.tomorrow.map { [$0.start, $0.title].compactMap { $0 }.joined(separator: " ") }.joined(separator: " · ")
    }
}

private struct CityLinks: View {
    @Binding var sheet: TodaySheet?
    private static let links: [(name: String, symbol: String, url: String)] = [
        ("KVB transport", "tram.fill", "https://www.kvb.koeln/fahrtinfo/betriebslage/"),
        ("City news", "doc.text.fill", "https://www.stadt-koeln.de/politik-und-verwaltung/presse/"),
    ]

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            VStack(alignment: .leading, spacing: 12) {
                HStack {
                    Text("Useful city links").font(.posterGrotesque(18)).tracking(-0.3)
                    Spacer()
                    Text("Official websites").font(.geist(13.5, .medium)).foregroundStyle(Palette.textSoft)
                }
                HStack(spacing: 8) {
                    ForEach(Self.links, id: \.name) { link in
                        Button { sheet = URL(string: link.url).map { .link($0) } } label: {
                            Label(link.name, systemImage: link.symbol).labelStyle(Tight())
                                .font(.geist(13.5, .semibold)).foregroundStyle(TodayLink.ink)
                                .padding(.horizontal, 12).frame(height: 34)
                                .background(Palette.quiet, in: .capsule)
                        }
                        .buttonStyle(PressStyle())
                    }
                }
            }
            .foregroundStyle(Palette.text)
            .padding(14)
            .card(radius: 22)
            Text("Weather from Open-Meteo and the DWD. Departures from KVB.")
                .font(.geist(12)).foregroundStyle(Palette.textSoft).padding(.horizontal, 4)
        }
    }
}

// MARK: - Sheets

private struct WeatherSheet: View {
    let weather: TodayPage.Weather
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            List {
                Section {
                    HStack(spacing: 12) {
                        Image(systemName: WeatherSymbol.name(weather.icon)).symbolRenderingMode(.multicolor).font(.system(size: 34))
                            .foregroundStyle(WeatherSymbol.tint(weather.icon))
                        Text("\(weather.temperature)°").font(.posterGrotesque(40))
                        VStack(alignment: .leading) {
                            Text(weather.summary ?? weather.condition).font(.geist(16, .semibold))
                            if let cycling = weather.cycling { Text(cycling).font(.geist(13)).foregroundStyle(Palette.textSoft) }
                        }
                    }
                }
                Section("Next hours") {
                    ForEach(weather.hours, id: \.hour) { hour in
                        HStack {
                            Text(hour.hour).font(.geist(15, .medium)).monospacedDigit()
                            Spacer()
                            Image(systemName: WeatherSymbol.name(hour.icon ?? "")).symbolRenderingMode(.multicolor)
                                .foregroundStyle(WeatherSymbol.tint(hour.icon ?? ""))
                            Text(hour.temperature.map { "\($0)°" } ?? "–").font(.geist(15)).frame(width: 40, alignment: .trailing)
                        }
                    }
                }
            }
            .navigationTitle("Weather")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done", systemImage: "checkmark") { dismiss() } } }
        }
        .presentationDetents([.medium, .large])
    }
}

private struct IdeasSheet: View {
    let page: TodayPage
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            List(Array(page.ideas.enumerated()), id: \.element.id) { index, idea in
                IdeaRow(idea: idea, now: page.now, tilt: [-3, 2, -2, 3][index % 4], date: page.date, tomorrow: page.tomorrowDate)
                    .listRowInsets(EdgeInsets())
            }
            .navigationTitle(page.ideasTitle)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done", systemImage: "checkmark") { dismiss() } } }
        }
        .presentationDetents([.medium, .large])
    }
}

/// A city site, inside the app.
private struct SafariView: UIViewControllerRepresentable {
    let url: URL

    func makeUIViewController(context: Context) -> SFSafariViewController { SFSafariViewController(url: url) }
    func updateUIViewController(_ controller: SFSafariViewController, context: Context) {}
}

// MARK: - Pieces

private struct SectionHead<Trailing: View>: View {
    let title: String
    @ViewBuilder let trailing: Trailing

    var body: some View {
        HStack(alignment: .firstTextBaseline) {
            Text(title).font(.posterGrotesque(23)).tracking(-0.3).foregroundStyle(Palette.text)
                .accessibilityAddTraits(.isHeader)
            Spacer(minLength: 8)
            trailing
        }
        .padding(.horizontal, 4)
    }
}

/// A row with a round icon, a title, a line under it, and its action in teal.
private struct ListRow: View {
    let symbol: String
    let title: String
    let note: String
    let action: String?

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: symbol).font(.system(size: 15, weight: .semibold))
                .frame(width: 36, height: 36).background(Palette.quiet, in: .circle)
            VStack(alignment: .leading, spacing: 2) {
                Text(title).font(.geist(15.5, .semibold)).lineLimit(2).multilineTextAlignment(.leading)
                if !note.isEmpty { Text(note).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(2).multilineTextAlignment(.leading) }
            }
            Spacer(minLength: 0)
            if let action { Text(action).font(.geist(14, .semibold)).foregroundStyle(TodayLink.ink) }
        }
        .foregroundStyle(Palette.text)
        .padding(.horizontal, 14).padding(.vertical, 10)
        .contentShape(.rect)
    }
}

enum TodayLink {
    static let ink = Color(light: 0x005B6B, dark: 0x5CCBE0)
}

private struct Tight: LabelStyle {
    func makeBody(configuration: Configuration) -> some View {
        HStack(spacing: 7) { configuration.icon; configuration.title }
    }
}

private struct TrailingIcon: LabelStyle {
    func makeBody(configuration: Configuration) -> some View {
        HStack(spacing: 4) { configuration.title; configuration.icon.font(.system(size: 12, weight: .bold)) }
    }
}

private extension View {
    /// The canvas's .t-card: the surface, rounded, with its hairline.
    func card(radius: CGFloat) -> some View {
        background(Palette.surface, in: .rect(cornerRadius: radius))
            .overlay { RoundedRectangle(cornerRadius: radius).strokeBorder(Palette.border) }
    }
}

/// The server's weather icons as SF Symbols, in the canvas's colours.
enum WeatherSymbol {
    static func name(_ icon: String) -> String {
        switch icon {
        case "clear-day": "sun.max.fill"
        case "clear-night": "moon.stars.fill"
        case "partly-cloudy-day": "cloud.sun.fill"
        case "partly-cloudy-night": "cloud.moon.fill"
        case "cloudy": "cloud.fill"
        case "fog": "cloud.fog.fill"
        case "wind": "wind"
        case "rain": "cloud.rain.fill"
        case "sleet": "cloud.sleet.fill"
        case "snow": "cloud.snow.fill"
        case "hail": "cloud.hail.fill"
        case "thunderstorm": "cloud.bolt.rain.fill"
        default: "cloud.sun.fill"
        }
    }

    static func tint(_ icon: String) -> Color {
        icon == "clear-day" ? Color(hex: 0xF2A516) : Color(hex: 0x8A97A8)
    }
}

/// Around here's kinds as SF Symbols.
enum PlaceSymbol {
    static func name(_ kind: String) -> String {
        switch kind {
        case "pharmacy": "cross.case.fill"
        case "cafe": "cup.and.saucer.fill"
        case "bakery": "birthday.cake.fill"
        case "grocery": "cart.fill"
        case "playground", "park", "nature": "leaf.fill"
        case "library": "books.vertical.fill"
        case "restaurant", "fastfood": "fork.knife"
        case "culture": "building.columns.fill"
        case "sports": "figure.run"
        default: "mappin"
        }
    }
}

/// A note's colour by how much it matters.
enum NeedTone {
    static func color(_ severity: String) -> Color {
        switch severity {
        case "conflict", "critical": Color(hex: 0xD64528)
        case "attention", "warning": Color(hex: 0xC98208)
        default: Color(hex: 0x2F78C8)
        }
    }
}

// MARK: - The model

@Observable
@MainActor
final class TodayModel {
    enum Phase: Equatable { case loading, ready, problem(String) }

    private(set) var phase = Phase.loading
    private(set) var page: TodayPage?
    /// The departures nearest the person, read when Cologne today's fold opens.
    private(set) var board: Board?
    private let locator = OneLocation()
    private var refreshing: Task<Void, Never>?

    /// The bell's count: every note that needs the person.
    var alerts: Int { page?.needs.count ?? 0 }
    /// When the header's mark last began to wave: on opening, and on a tap of the brand.
    private(set) var waveSince: Date?
    /// A tap of the brand takes Today back to its top.
    private(set) var topRequests = 0

    func brandTapped() {
        topRequests += 1
        waveSince = .now
    }

    func start(_ api: AppAPI) async {
        await load(api)
        refreshing?.cancel()
        // The Leave card counts down; the day re-reads each minute.
        refreshing = Task { [weak self] in
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(60))
                guard let self, !Task.isCancelled else { return }
                await self.load(api, quietly: true)
            }
        }
    }

    func load(_ api: AppAPI, quietly: Bool = false) async {
        let place = await locator.coordinate()
        do throws(APIFailure) {
            page = try await api.today(latitude: place?.latitude, longitude: place?.longitude)
            if phase != .ready { waveSince = .now }
            phase = .ready
            reportShown(api)
        } catch {
            if page != nil, quietly { return }
            phase = .problem(error == .signedOut ? "You’ve been signed out." : "Couldn’t reach Expadu. Check your connection.")
        }
    }

    /// What just changed, for the note; `undo` puts it back.
    struct Note: Equatable, Identifiable {
        enum Undo: Equatable { case change(String), restore(id: String, date: String), unsave(TodayPage.Idea), signal(TodayPage.Idea) }

        let id = UUID()
        let text: String
        let symbol: String
        var undo: Undo?
        var problem = false
    }

    private(set) var note: Note?
    /// Ideas they said aren't for them: off Today at once (the server keeps them off once Undo has passed).
    private(set) var hidden: Set<Int> = []
    /// Not now on the paused line, for this session (the server keeps it off a week).
    private(set) var pauseSetAside = false
    /// A turned-down idea's signal waits out its Undo, as the web's signalLater.
    private var pending: [Int: Task<Void, Never>] = [:]
    /// Ideas already reported shown, so each counts once a day.
    private var seen: Set<String> = []

    var shownIdeas: [TodayPage.Idea] { (page?.ideas ?? []).filter { !hidden.contains($0.id) } }
    /// What was added this session, so its + shows a check at once.
    private(set) var added: Set<String> = []
    private var noteTimer: Task<Void, Never>?

    private func say(_ next: Note) {
        note = next
        noteTimer?.cancel()
        noteTimer = Task { [weak self] in
            try? await Task.sleep(for: .seconds(6))
            guard !Task.isCancelled else { return }
            self?.note = nil
        }
    }

    /// One change to the day through the server, then the day re-read so it moves in place.
    func change(_ api: AppAPI, action: String, id: String? = nil, date: String? = nil, candidate: String? = nil, symbol: String,
                name: String? = nil, start: String? = nil, end: String? = nil, location: String? = nil) async {
        guard let page else { return }
        if let candidate { added.insert(candidate) }
        do throws(APIFailure) {
            let done = try await api.changeDay(revision: page.revision, action: action, id: id, date: date, candidate: candidate, name: name, start: start, end: end, location: location)
            say(Note(text: done.label ?? "Done", symbol: symbol, undo: done.undoable ? done.id.map { .change($0) } : nil))
            await load(api, quietly: true)
        } catch {
            if let candidate { added.remove(candidate) }
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
        }
    }

    func markDone(_ api: AppAPI, plan: TodayPage.Plan, date: String) async {
        guard let page else { return }
        do throws(APIFailure) {
            _ = try await api.changeDay(revision: page.revision, action: "complete", id: plan.id, date: date)
            say(Note(text: "\(plan.title) is done", symbol: "checkmark.circle.fill", undo: .restore(id: plan.id, date: date)))
            await load(api, quietly: true)
        } catch {
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
        }
    }

    func restore(_ api: AppAPI, plan: TodayPage.Plan, date: String) async {
        guard let page else { return }
        do throws(APIFailure) {
            _ = try await api.changeDay(revision: page.revision, action: "uncomplete", id: plan.id, date: date)
            say(Note(text: "\(plan.title) is back on your day", symbol: "arrow.uturn.backward"))
            await load(api, quietly: true)
        } catch {
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
        }
    }

    func addPlan(_ api: AppAPI, name: String, date: String, start: String, end: String, place: String) async -> Bool {
        let where_ = place.trimmingCharacters(in: .whitespaces)
        await change(api, action: "appointment", date: date, symbol: "calendar.badge.plus", name: name.trimmingCharacters(in: .whitespaces),
                     start: start, end: end, location: where_.isEmpty ? nil : where_)
        return note?.problem != true
    }

    /// The bookmark flips at once; the server's answer settles it.
    func toggleSave(_ api: AppAPI, idea: TodayPage.Idea) async {
        guard let startsAt = idea.startsAt, let index = page?.ideas.firstIndex(where: { $0.id == idea.id }) else { return }
        let saving = !idea.saved
        page?.ideas[index].saved = saving
        do throws(APIFailure) {
            _ = try await api.saveEvent(id: idea.id, startsAt: startsAt, saved: saving)
            say(Note(text: saving ? "Saved for later" : "Removed from saved", symbol: saving ? "bookmark.fill" : "bookmark",
                     undo: saving ? .unsave(idea) : nil))
        } catch {
            page?.ideas[index].saved = !saving
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
        }
    }

    func undo(_ api: AppAPI) async {
        guard let undo = note?.undo, let page else { return }
        note = nil
        switch undo {
        case let .change(id):
            do throws(APIFailure) {
                _ = try await api.changeDay(revision: page.revision, action: "undo", change: id)
                added.removeAll()
                say(Note(text: "Put back", symbol: "arrow.uturn.backward"))
                await load(api, quietly: true)
            } catch {
                say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
            }
        case let .restore(id, date):
            if let plan = page.plans.first(where: { $0.id == id }) { await restore(api, plan: plan, date: date) }
        case let .unsave(idea):
            var saved = idea
            saved.saved = true
            await toggleSave(api, idea: saved)
        case let .signal(idea):
            pending.removeValue(forKey: idea.id)?.cancel()
            withAnimation(.snappy) { _ = hidden.remove(idea.id) }
            say(Note(text: "Put back", symbol: "arrow.uturn.backward"))
        }
    }

    /// Less like this: its kind lower from tomorrow, nothing hidden; sent once Undo has passed.
    func lessLikeThis(_ api: AppAPI, idea: TodayPage.Idea) {
        later(api, Signal(action: .lessLikeThis, event: idea.id), for: idea)
        say(Note(text: "Noted. Fewer like this from tomorrow.", symbol: "minus.circle", undo: .signal(idea)))
    }

    /// Not for me: off Today at once.
    func notForMe(_ api: AppAPI, idea: TodayPage.Idea) {
        hidden.insert(idea.id)
        later(api, Signal(action: .notForMe, event: idea.id), for: idea)
        say(Note(text: "Taken off Today.", symbol: "xmark.circle", undo: .signal(idea)))
    }

    private func later(_ api: AppAPI, _ signal: Signal, for idea: TodayPage.Idea) {
        pending[idea.id]?.cancel()
        pending[idea.id] = Task { [weak self] in
            try? await Task.sleep(for: .seconds(5))
            guard !Task.isCancelled else { return }
            try? await api.send([signal])
            self?.pending.removeValue(forKey: idea.id)
        }
    }

    /// The ideas Today showed, with their places in the list, once a day each.
    private func reportShown(_ api: AppAPI) {
        guard let page else { return }
        let fresh = Array(shownIdeas.prefix(4).enumerated()).filter { seen.insert("\(page.date):\($0.element.id)").inserted }
        guard !fresh.isEmpty else { return }
        let signals = fresh.map { Signal(action: .impression, event: $0.element.id, position: $0.offset) }
        Task { try? await api.send(signals) }
    }

    func agree(_ api: AppAPI, to pause: TodayPage.RankPause) async -> Bool {
        do throws(APIFailure) {
            try await api.agreeToOrder(pause)
            say(Note(text: "Agreed. Today is in your order again in a minute.", symbol: "checkmark.circle.fill"))
            withAnimation(.snappy) { pauseSetAside = true }
            return true
        } catch {
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
            return false
        }
    }

    func orderLater(_ api: AppAPI) async {
        withAnimation(.snappy) { pauseSetAside = true }
        try? await api.orderLater()
    }

    private static func words(_ error: APIFailure) -> String {
        switch error {
        case let .refused(why): why
        case .signedOut: "You’ve been signed out."
        case .tooManyTries: "Too many tries. Wait a moment."
        case .unreachable: "Couldn’t reach Expadu. Check your connection."
        }
    }

    func loadBoard(_ api: AppAPI) async {
        let place = await locator.coordinate() ?? OneLocation.neumarkt
        if let next = try? await api.nearby(latitude: place.latitude, longitude: place.longitude) {
            withAnimation(.snappy) { board = next }
        }
    }
}
