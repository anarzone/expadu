import API
import Core
import CoreLocation
import Maps
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
                            case .saved: if let saved = page.saved { SavedForLater(saved: saved, date: page.date, plans: page.plans) }
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
                // Above the floating bar (62pt and its 21pt gap), never under it.
                NoteBar(note: note)
                    .padding(.horizontal, 16).padding(.bottom, 64)
                    // rise: up from below on the canvas's spring.
                    .transition(.offset(y: 30).combined(with: .opacity))
                    .id(note.id)
            }
        }
        .animation(reduceMotion ? nil : .spring(duration: 0.5, bounce: 0.35), value: model.note)
        .sheet(item: $sheet) { sheet in
            switch sheet {
            case let .weather(page): WeatherSheet(page: page)
            case let .whatsOn(open, date): WhatsOnSheet(open: open, date: date)
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
    case weather(TodayPage)
    /// What's on; with a listing, straight at its details (on that listing's day).
    case whatsOn(open: String? = nil, date: String? = nil)
    case link(URL)
    case addPlan(String)
    case why(TodayPage.Idea, String)
    case howWeRank(Bool)
    case agree(TodayPage.RankPause)

    var id: String {
        switch self {
        case .weather: "weather"
        case .whatsOn: "whats-on"
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

    // The canvas's .toast: a dark pill over the bar, Undo in a lighter capsule.
    var body: some View {
        HStack(spacing: 12) {
            if note.problem {
                Image(systemName: "exclamationmark.circle.fill").font(.system(size: 15, weight: .semibold)).foregroundStyle(Color(hex: 0xFF9E80))
            }
            Text(note.text).font(.geist(15)).foregroundStyle(.white).lineLimit(2)
            Spacer(minLength: 0)
            if note.undo != nil {
                Button("Undo") { Task { await model.undo(session.api) } }
                    .font(.geist(15, .semibold)).foregroundStyle(.white)
                    .padding(.horizontal, 14).frame(height: 36)
                    .background(.white.opacity(0.16), in: .capsule)
                    .contentShape(.rect.inset(by: -4))
            }
        }
        .padding(.leading, 18).padding(.trailing, 8).padding(.vertical, 8)
        .frame(minHeight: 52)
        .background(Color(hex: 0x2B2720), in: .rect(cornerRadius: 26))
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
                     note: weather.summary ?? weather.condition, order: 0, spins: true) { sheet = .weather(page) }
            }
            if !page.ideas.isEmpty {
                chip(symbol: "calendar", tint: Color(hex: 0x6B4FD0), title: "\(page.ideas.count) events", note: page.ideasWhen,
                     order: page.weather == nil ? 0 : 1, spins: false) { sheet = .whatsOn() }
            }
        }
    }

    /// Each chip springs up in turn (the canvas's chipin, 0.6 s); the sun turns in (sunin, 1.1 s, after 0.3 s).
    private func chip(symbol: String, tint: Color, title: String, note: String, order: Double, spins: Bool, open: @escaping () -> Void) -> some View {
        Button(action: open) {
            HStack(spacing: 9) {
                Image(systemName: symbol).symbolRenderingMode(.monochrome).font(.system(size: 19, weight: .semibold))
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
                // A trip to something that isn't a plan (an event's Getting there) goes at now, not under a plan.
                if let trip = page.trip, !page.ahead.contains(where: { $0.title == trip.to }) {
                    LiveTrip(trip: trip).padding(.bottom, 14)
                        .transition(.asymmetric(insertion: .scale(scale: 0.985).combined(with: .opacity),
                                                removal: .opacity.combined(with: .offset(y: -10)).combined(with: .scale(scale: 0.97))))
                }
                if !done.isEmpty { DoneFold(page: page, done: done, open: $doneOpen) }
                if page.free?.anchorTitle == nil, let free = page.free { FreeTime(page: page, free: free) }
                if page.ahead.isEmpty {
                    EmptyDay(page: page).padding(.top, 4).padding(.bottom, 12)
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
            .frame(maxWidth: .infinity, alignment: .leading)
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

/// A day with nothing ahead (the canvas's .ln-empty): what that means now, and two ways on.
private struct EmptyDay: View {
    let page: TodayPage
    @Environment(\.shell) private var shell

    var body: some View {
        let evening = TodayPage.greeting(at: page.now, name: "").hasPrefix("Guten Abend")
        let words: (String, String, String) = page.plans.isEmpty
            ? (page.welcome ? ("No plans yet", "Add one, or let Composer plan your day.", "Plan my day") : ("No plans added for today", "Your day is open.", "Plan the rest of today"))
            : evening ? ("No more plans today", "Tomorrow is still open.", "Plan tomorrow") : ("No plans left today", "Everything is done.", "Plan the rest of today")
        VStack(alignment: .leading, spacing: 0) {
            Text(words.0).font(.geist(16, .semibold)).foregroundStyle(Palette.text)
            Text(words.1).font(.geist(13.5)).foregroundStyle(Palette.textSoft).padding(.top, 2)
            HStack(spacing: 8) {
                Button { shell.openComposer() } label: {
                    AskComposerLabel(title: words.2, height: 44)
                }
                .buttonStyle(PressStyle())
                Button { shell.show(.travel) } label: {
                    Text("Find a route").font(.geist(15, .medium)).foregroundStyle(Palette.text)
                        .padding(.horizontal, 16).frame(height: 44)
                        .background(Palette.quiet, in: .capsule)
                }
                .buttonStyle(PressStyle())
            }
            .padding(.top, 12)
        }
    }
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
    @State private var details = false
    @State private var editing = false

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
                    Button(details ? "Hide details" : "Details", systemImage: "info.circle") { withAnimation(Motion.ios(0.35)) { details.toggle() } }
                    if plan.editable {
                        Button("Edit plan", systemImage: "pencil") { editing = true }
                    }
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
            if details {
                // The canvas's Details: what's known about the plan, in place.
                VStack(alignment: .leading, spacing: 6) {
                    detail("clock", [plan.start, plan.end].compactMap { $0 }.joined(separator: "–"))
                    if let place = plan.place { detail("mappin", place) }
                    detail(plan.editable ? "person" : "doc.text", plan.editable ? "Your own plan" : "From Paperwork")
                }
                .padding(.vertical, 10).padding(.horizontal, 12)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Palette.quiet, in: .rect(cornerRadius: 14))
                .padding(.top, 8)
                .transition(.opacity.combined(with: .offset(y: -6)))
            }
            if next {
                if let deadline = page.deadline(for: plan) {
                    DeadlineNote(deadline: deadline).padding(.top, 10)
                }
                if let bring = plan.bring {
                    BringCard(bring: bring).padding(.top, 10)
                }
            }
            // The trip goes under the plan it's for: the next one, or a later one while this one is under way.
            if let trip = page.trip, trip.to == plan.title {
                // lvlive in, tlaway out.
                LiveTrip(trip: trip).padding(.top, 12)
                    .transition(.asymmetric(insertion: .scale(scale: 0.985).combined(with: .opacity),
                                            removal: .opacity.combined(with: .offset(y: -10)).combined(with: .scale(scale: 0.97))))
            } else if page.trip == nil, let leave = page.leave, leave.isKnown, forThis {
                LeaveCard(leave: leave, place: plan.shortPlace).padding(.top, 12)
                    .transition(.opacity)
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
        .accessibilityElement(children: .contain)
        .sheet(isPresented: $editing) { AddPlanSheet(date: page.date, editing: plan) }
    }

    private func detail(_ symbol: String, _ text: String) -> some View {
        Label(text, systemImage: symbol).labelStyle(Tight()).font(.geist(14)).foregroundStyle(Palette.text)
    }

    /// The Leave card's plan is this one (by the server's id; with none, the next plan).
    private var forThis: Bool {
        guard let id = page.leave?.planId else { return next }
        return id == plan.id
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
    private let ink = Palette.bringText

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
        .background(Palette.bringBackground, in: .rect(cornerRadius: 16))
    }
}

/// The canvas's motion curves (today.css): --ios, and the springs its flips and notes use.
enum Motion {
    static let ios = Animation.timingCurve(0.32, 0.72, 0, 1, duration: 0.4)
    static func ios(_ duration: Double) -> Animation { .timingCurve(0.32, 0.72, 0, 1, duration: duration) }
    static let spring = Animation.spring(duration: 0.6, bounce: 0.35)
    static let green = Color(hex: 0x2F9E5B)
    static let ink = Color(hex: 0x211D15)
}

/// A value turning over into place (lvflip: rotateX from -90°), each after the one before.
private struct Flip: ViewModifier {
    let trigger: Int
    var order = 0
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var angle = 0.0

    func body(content: Content) -> some View {
        content
            .rotation3DEffect(.degrees(angle), axis: (x: 1, y: 0, z: 0), anchor: UnitPoint(x: 0.5, y: 0.6), perspective: 0.6)
            .opacity(angle == 0 ? 1 : 0)
            .onChange(of: trigger) {
                guard !reduceMotion else { return }
                angle = -90
                withAnimation(Motion.spring.delay(0.06 + 0.08 * Double(order))) { angle = 0 }
            }
    }
}

/// Rising in after the card turns live (tlup: 8pt up, fading in), each part a little after the last.
private struct Rise: ViewModifier {
    let order: Int
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var shown = false

    func body(content: Content) -> some View {
        content
            .opacity(shown || reduceMotion ? 1 : 0)
            .offset(y: shown || reduceMotion ? 0 : 8)
            .onAppear { withAnimation(Motion.ios.delay(0.06 * Double(order))) { shown = true } }
    }
}

/// The card's own note (the canvas's .lv-say): green, dropped in at its top, with Undo when it can.
struct CardSay: View {
    let note: TodayModel.Note
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: note.symbol).font(.system(size: 13, weight: .bold))
            Text(note.text).font(.geist(13.5, .semibold)).fixedSize(horizontal: false, vertical: true)
            Spacer(minLength: 0)
            if note.undo != nil {
                Button("Undo") { Task { await model.undoCard(session.api) } }
                    .font(.geist(13.5, .bold))
                    .padding(.horizontal, 12).frame(height: 30)
                    .background(Color(hex: 0x17603A, opacity: 0.12), in: .capsule)
                    .contentShape(.rect.inset(by: -7))
            }
        }
        .foregroundStyle(Color(hex: 0x17603A))
        .padding(.vertical, 8).padding(.leading, 12).padding(.trailing, 8)
        .background(Color(hex: 0xE4F4E8), in: .rect(cornerRadius: 14))
        .transition(.asymmetric(insertion: .opacity.combined(with: .offset(y: -8)).combined(with: .scale(scale: 0.97, anchor: .top)), removal: .opacity))
    }
}

/// When to leave: a ring counting down, the line, when you're there, the minutes to spare,
/// the walk to the platform, and the trip's two actions. A changed way turns its values over and
/// the card glows green (lvflip, lvglow); its note sits at its top.
private struct LeaveCard: View {
    let leave: TodayPage.Leave
    let place: String?
    private let warn = Color(hex: 0xC98208)
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var starting = false
    @State private var othersOpen = false
    @State private var replaceAsked = false
    @State private var glow = 0.0

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            if let say = model.cardNote {
                CardSay(note: say).padding(.top, -2)
            }
            HStack(spacing: 12) {
                ZStack {
                    Circle().stroke(Palette.quiet, lineWidth: 4)
                    Circle().trim(from: 0, to: ringFill).stroke(warn, style: StrokeStyle(lineWidth: 4, lineCap: .round)).rotationEffect(.degrees(-90))
                        .animation(Motion.ios(0.8), value: ringFill)
                    // Minutes in the last hour; further off, hours ("10 h").
                    let minutes = leave.leaveInMinutes ?? 0
                    VStack(spacing: -1) {
                        Text(minutes >= 60 ? "\(minutes / 60)" : "\(minutes)").font(.posterGrotesque(15)).contentTransition(.numericText(countsDown: true))
                        Text(minutes >= 60 ? "h" : "min").font(.geist(9, .semibold)).foregroundStyle(Palette.textSoft)
                    }
                }
                .frame(width: 46, height: 46)
                VStack(alignment: .leading, spacing: 3) {
                    Text("Leave at \(leave.leaveTime ?? "")").font(.geist(17, .bold)).modifier(Flip(trigger: model.cardFlips))
                    if leave.live {
                        HStack(spacing: 5) {
                            Circle().frame(width: 7, height: 7)
                            Text("Live · KVB").font(.geist(12.5, .bold))
                        }
                        .foregroundStyle(Motion.green)
                    }
                }
            }
            HStack(spacing: 6) {
                if let line = leave.line, let departs = leave.departs {
                    tile(label: nil, badge: Board.badge(line), line: LineLook(hex: leave.color ?? "#1A4CD4"), value: departs, note: leave.from ?? "", order: 1)
                }
                tile(label: "There", badge: nil, line: nil, value: leave.arriveTime ?? "", note: place ?? leave.to, order: 2)
                if let spare = leave.spareMinutes, spare < 0 {
                    // Late: by how much, in the warning colour, never a negative spare.
                    tile(label: "Late", badge: nil, line: nil, value: "\(-spare) min", note: leave.start.map { "after \($0)" } ?? "", warning: true, order: 3)
                } else if let spare = leave.spareMinutes {
                    tile(label: "Spare", badge: nil, line: nil, value: "\(spare) min", note: leave.start.map { "before \($0)" } ?? "", order: 3)
                }
            }
            if let note = leave.note {
                Label(note, systemImage: "figure.walk").font(.geist(12.5)).foregroundStyle(Palette.textSoft)
                    .labelStyle(Tight())
            }
            HStack(spacing: 8) {
                Button { start(replace: false) } label: {
                    Group {
                        if starting {
                            ProgressView().tint(.white)
                        } else {
                            Label("I’m on my way", systemImage: "figure.walk")
                        }
                    }
                    .font(.geist(15, .semibold)).foregroundStyle(.white)
                    .frame(maxWidth: .infinity).frame(height: 44)
                    .background(Palette.action, in: .capsule)
                    .contentShape(.capsule)
                }
                .buttonStyle(PressStyle())
                .disabled(starting || leave.ways.isEmpty)
                Button { othersOpen = true } label: {
                    Label("Other ways", systemImage: "arrow.triangle.branch")
                        .font(.geist(15, .medium)).foregroundStyle(Palette.text)
                        .padding(.horizontal, 16).frame(height: 44)
                        .background(Palette.quiet, in: .capsule)
                        .contentShape(.capsule)
                }
                .buttonStyle(PressStyle())
                .disabled(leave.ways.count < 2)
            }
            .labelStyle(Tight())
        }
        .padding(14)
        .background(Palette.surface, in: .rect(cornerRadius: 20))
        .overlay { RoundedRectangle(cornerRadius: 20).strokeBorder(warn.opacity(0.55), lineWidth: 1.5) }
        // lvglow: green for a moment after the way changed, the halo fading out.
        .overlay { RoundedRectangle(cornerRadius: 20).strokeBorder(Motion.green, lineWidth: 2).opacity(glow) }
        .background { RoundedRectangle(cornerRadius: 20).stroke(Motion.green.opacity(0.14), lineWidth: 12).opacity(glow) }
        .animation(Motion.spring, value: model.cardNote)
        .onChange(of: model.cardFlips) {
            guard !reduceMotion else { return }
            glow = 1
            withAnimation(.easeOut(duration: 1.0).delay(0.4)) { glow = 0 }
        }
        .sheet(isPresented: $othersOpen) { OtherWaysSheet(leave: leave) }
        .confirmationDialog("Replace the trip you’re on?", isPresented: $replaceAsked, titleVisibility: .visible) {
            Button("Replace") { start(replace: true) }
            Button("Keep", role: .cancel) {}
        } message: {
            Text("You’re already on your way somewhere else.")
        }
    }

    /// I'm on my way, on the way the card shows; a trip already running asks first.
    private func start(replace: Bool) {
        guard let way = leave.ways.first else { return }
        starting = true
        Task {
            let running = await model.startTrip(session.api, way: way, replace: replace)
            starting = false
            if running { replaceAsked = true }
        }
    }

    /// Fuller as the time to leave comes closer, over the last quarter hour.
    private var ringFill: Double {
        max(0.04, 1 - Double(min(leave.leaveInMinutes ?? 15, 15)) / 15)
    }

    private func tile(label: String?, badge: String?, line: LineLook?, value: String, note: String, warning: Bool = false, order: Int) -> some View {
        VStack(alignment: .leading, spacing: 1) {
            if let badge, let line {
                Label(badge, systemImage: "tram.fill").labelStyle(.titleAndIcon)
                    .font(.geist(12, .bold)).foregroundStyle(line.ink)
                    .padding(.horizontal, 6).frame(height: 20)
                    .background(line.fill, in: .rect(cornerRadius: 5))
            } else if let label {
                Text(label.uppercased()).font(.geist(10.5, .bold)).tracking(0.4).foregroundStyle(warning ? Palette.conflictText : Palette.textSoft)
            }
            Text(value).font(.posterGrotesque(19)).monospacedDigit().foregroundStyle(warning ? Palette.conflictText : Palette.text)
                .modifier(Flip(trigger: model.cardFlips, order: order))
            Text(note).font(.geist(12)).foregroundStyle(Palette.textSoft).lineLimit(1).minimumScaleFactor(0.85)
        }
        .padding(.vertical, 9).padding(.horizontal, 10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Palette.quiet, in: .rect(cornerRadius: 12))
    }
}

/// On your way, live (the canvas's .trip-l): progress bars for the steps, the step they're on by the
/// clock in its line's colour, every step when asked, the way on a map, and End. It turns live in
/// place: the card settles in, its parts rise one after another, the dot pulses.
struct LiveTrip: View {
    let trip: TodayPage.Trip
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var picked: Int?
    @State private var steps = false
    @State private var ending = false
    @State private var mapOpen = false
    @State private var pulse = false
    /// Our own navigation: the phone's GPS on the trip's lines (Guide).
    @State private var follow = PhoneFollow()
    @State private var replanning = false
    @State private var trouble: String?
    @State private var drag: CGFloat = 0
    @State private var swipedLeft = true
    @State private var back: Task<Void, Never>?
    @AppStorage("speakTurns") private var speak = false

    /// Look a step ahead or back; after a while the card comes back to the step they're on.
    private func look(by delta: Int, from index: Int, current: Int, count: Int) {
        let next = min(max(index + delta, 0), count - 1)
        guard next != index else { return }
        withAnimation(.spring(duration: 0.4, bounce: 0.2)) { picked = next == current ? nil : next }
        back?.cancel()
        back = Task {
            try? await Task.sleep(for: .seconds(6))
            guard !Task.isCancelled else { return }
            withAnimation(.spring(duration: 0.4, bounce: 0.2)) { swipedLeft = false; picked = nil }
        }
    }

    private func end(arrived: Bool) {
        ending = true
        Task { await model.endTrip(session.api, arrived: arrived); ending = false }
    }

    var body: some View {
        TimelineView(.periodic(from: .now, by: 20)) { timeline in
            let here = follow.here
            let live = trip.live(at: timeline.date, picked: picked, guided: here?.step)
            let all = trip.steps
            let last = live.current == all.count - 1
            VStack(alignment: .leading, spacing: 0) {
                if let say = model.cardNote {
                    CardSay(note: say).padding(.bottom, 12)
                }
                HStack(spacing: 8) {
                    HStack(spacing: 5) {
                        Circle().fill(Motion.green).frame(width: 7, height: 7)
                            .background { Circle().fill(Motion.green.opacity(0.25)).scaleEffect(pulse ? 2.4 : 1).opacity(pulse ? 0 : 1) }
                        Text("On your way · live").font(.geist(13.5, .bold))
                    }
                    .foregroundStyle(Motion.green)
                    Spacer(minLength: 0)
                    if let arrive = trip.arriveTime {
                        Text("There \(arrive)").font(.geist(13.5, .semibold)).monospacedDigit().foregroundStyle(Palette.textSoft)
                    }
                }
                .modifier(Rise(order: 0))
                bars(all, current: live.current)
                    .padding(.top, 12).padding(.bottom, 4)
                    .modifier(Rise(order: 1))
                // Swipe sideways: the step before or after; tap: every step. It comes back to now by itself.
                nowCard(live)
                    .id(live.index)
                    .transition(.asymmetric(insertion: .opacity.combined(with: .offset(x: swipedLeft ? 40 : -40)),
                                            removal: .opacity.combined(with: .offset(x: swipedLeft ? -40 : 40))))
                    .offset(x: drag)
                    .contentShape(.rect(cornerRadius: 16))
                    .onTapGesture { withAnimation(Motion.ios(0.35)) { steps.toggle() } }
                    .simultaneousGesture(
                        DragGesture(minimumDistance: 18)
                            .onChanged { value in
                                guard abs(value.translation.width) > abs(value.translation.height) * 1.4 else { return }
                                drag = value.translation.width * 0.35
                            }
                            .onEnded { value in
                                withAnimation(.spring(duration: 0.35, bounce: 0.3)) { drag = 0 }
                                guard abs(value.translation.width) > 50, abs(value.translation.width) > abs(value.translation.height) * 1.4 else { return }
                                swipedLeft = value.translation.width < 0
                                look(by: swipedLeft ? 1 : -1, from: live.index, current: live.current, count: all.count)
                            }
                    )
                    .modifier(Rise(order: 2))
                    .accessibilityAction(named: "Next step") { look(by: 1, from: live.index, current: live.current, count: all.count) }
                    .accessibilityAction(named: "Previous step") { look(by: -1, from: live.index, current: live.current, count: all.count) }
                    .accessibilityAction(named: steps ? "Hide steps" : "All steps") { withAnimation(Motion.ios(0.35)) { steps.toggle() } }
                // On foot: the next turn, by where the phone is on our router's way.
                if picked == nil, let here, let turn = here.turn, !here.offRoute {
                    TurnRow(turn: turn, metres: here.metresToTurn)
                        .padding(.top, 12)
                        .transition(.opacity.combined(with: .offset(y: -6)))
                }
                if let here, let missed = here.missed {
                    TripTrouble(text: "\(missed.name) at \(missed.departTime) has left.", busy: replanning) { replan() }
                        .padding(.top, 12).transition(.opacity)
                } else if let here, here.offRoute, let since = follow.offSince, timeline.date.timeIntervalSince(since) > 15 {
                    TripTrouble(text: "You’re off the way, \(TurnRow.distance(Int(here.offBy))) from it.", busy: replanning) { replan() }
                        .padding(.top, 12).transition(.opacity)
                } else if let trouble {
                    Label(trouble, systemImage: "exclamationmark.circle.fill").font(.geist(13.5, .semibold)).foregroundStyle(Palette.conflictText)
                        .padding(.top, 10)
                }
                if steps {
                    StepList(steps: all, current: live.current)
                        .padding(.top, 14).padding(.horizontal, 2)
                        .transition(.opacity.combined(with: .offset(y: -6)))
                }
                // There: the one thing left to do. On the way, everything else is a long press away.
                if last && picked == nil {
                    action("Done", symbol: "checkmark", grow: true) { end(arrived: true) }
                        .disabled(ending)
                        .padding(.top, 12)
                        .transition(.opacity.combined(with: .scale(scale: 0.95)))
                } else {
                    Text("Swipe for the next step · tap for all · hold for more")
                        .font(.geist(12)).foregroundStyle(Palette.textSoft)
                        .frame(maxWidth: .infinity)
                        .padding(.top, 10)
                        .accessibilityHidden(true)
                }
            }
            .animation(Motion.ios(0.35), value: live.index)
        }
        .padding(14)
        .background(Palette.surface, in: .rect(cornerRadius: 20))
        .overlay { RoundedRectangle(cornerRadius: 20).strokeBorder(Motion.green.opacity(0.6), lineWidth: 1.5) }
        .sensoryFeedback(.selection, trigger: picked)
        .contentShape(.contextMenuPreview, .rect(cornerRadius: 20))
        .contextMenu {
            Button { mapOpen = true } label: { Label("Show the map", systemImage: "map") }
            Button { withAnimation(Motion.ios(0.35)) { steps.toggle() } } label: { Label(steps ? "Hide steps" : "All steps", systemImage: "list.bullet") }
            Toggle(isOn: $speak) { Label("Speak turns", systemImage: "speaker.wave.2") }
            Button { replan() } label: { Label("New way from here", systemImage: "arrow.triangle.turn.up.right.diamond") }
                .disabled(follow.point == nil)
            Divider()
            Button(role: .destructive) { end(arrived: false) } label: { Label("End trip", systemImage: "stop.circle") }
        }
        .accessibilityAction(named: "Show the map") { mapOpen = true }
        .accessibilityAction(named: "End trip") { end(arrived: false) }
        .animation(Motion.spring, value: model.cardNote)
        .sheet(isPresented: $mapOpen) { WayMap(title: "Your way to \(trip.to)", legs: trip.legs, guiding: (trip, follow)) }
        .onAppear {
            follow.trip = trip
            follow.start()
            guard !reduceMotion else { return }
            withAnimation(.easeInOut(duration: 1.6).repeatForever(autoreverses: false)) { pulse = true }
        }
        .onDisappear { follow.stop() }
        .onChange(of: trip) { follow.trip = trip }
        .animation(Motion.ios(0.35), value: follow.point)
    }

    /// A new way from where the phone is now, to the same place: it replaces the trip.
    private func replan() {
        guard let from = follow.point else { return }
        replanning = true
        Task {
            trouble = await model.newWay(session.api, trip: trip, from: from)
            replanning = false
            follow.restart()
        }
    }

    /// The steps as bars: done ones full, the one they're on running, the rest empty; a tap shows that step.
    private func bars(_ all: [TodayPage.Step], current: Int) -> some View {
        HStack(spacing: 4) {
            ForEach(Array(all.enumerated()), id: \.offset) { index, step in
                Button { withAnimation(Motion.ios(0.35)) { picked = picked == index || index == current ? nil : index } } label: {
                    BarFill(colour: StepColour.of(step), state: index < current ? .done : index == current ? .on : .ahead)
                        .frame(height: 24)
                        .contentShape(.rect)
                }
                .buttonStyle(.plain)
                .accessibilityLabel(step.title)
            }
        }
    }

    private func nowCard(_ live: TodayPage.Live) -> some View {
        let colour = StepColour.of(live.step)
        return HStack(alignment: .top, spacing: 12) {
            VStack(alignment: .leading, spacing: 2) {
                Text(live.label.uppercased()).font(.geist(11.5, .bold)).tracking(0.3).foregroundStyle(Palette.textSoft)
                Text(live.step.title).font(.geist(17, .bold)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                if !live.step.sub.isEmpty {
                    Text(live.step.sub).font(.geist(13)).foregroundStyle(Palette.textSoft)
                }
            }
            Spacer(minLength: 0)
            VStack(alignment: .trailing, spacing: 4) {
                Text(live.big).font(.posterGrotesque(28)).monospacedDigit().foregroundStyle(colour)
                    .contentTransition(.numericText(countsDown: true))
                Text(live.bigNote).font(.geist(12, .semibold)).foregroundStyle(Palette.textSoft)
            }
        }
        .padding(.vertical, 12).padding(.leading, 14).padding(.trailing, 12)
        .background(colour.opacity(0.09), in: .rect(cornerRadius: 16))
        .background(Palette.surface, in: .rect(cornerRadius: 16))
        .overlay(alignment: .leading) {
            UnevenRoundedRectangle(topLeadingRadius: 16, bottomLeadingRadius: 16).fill(colour).frame(width: 3)
        }
    }

    private func action(_ title: String, symbol: String, grow: Bool = false, run: @escaping () -> Void) -> some View {
        Button(action: run) {
            Label(title, systemImage: symbol).labelStyle(Tight())
                .font(.geist(15, .medium)).foregroundStyle(Palette.text)
                .padding(.horizontal, 14).frame(maxWidth: grow ? .infinity : nil).frame(height: 44)
                .background(Palette.quiet, in: .capsule)
                .contentShape(.capsule)
        }
        .buttonStyle(PressStyle())
    }
}

/// A step's colour: its line's, the ink for walking, the red-orange of arriving.
enum StepColour {
    /// LINE_LOOK.walk.
    static let walk = Color(hex: 0x6A6253)

    static func of(_ step: TodayPage.Step) -> Color {
        step.color.map { LineLook(hex: $0).deep } ?? walk
    }
}

/// One progress bar (.lv-bar): a quiet track, full once done, running while it's the step they're on.
private struct BarFill: View {
    enum State { case done, on, ahead }
    let colour: Color
    let state: State
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @SwiftUI.State private var run = false

    var body: some View {
        GeometryReader { proxy in
            let fill: Double = switch state {
            case .done: 1
            case .on: run ? 0.62 : 0.4
            case .ahead: 0
            }
            ZStack(alignment: .leading) {
                Capsule().fill(Palette.quiet)
                Capsule().fill(colour).frame(width: proxy.size.width * fill)
            }
            .frame(height: 5)
            .clipShape(.capsule)
            .frame(maxHeight: .infinity)
        }
        .animation(Motion.ios(0.5), value: state == .done)
        .onAppear {
            guard state == .on, !reduceMotion else { return }
            withAnimation(.easeInOut(duration: 2.4).repeatForever(autoreverses: true)) { run = true }
        }
    }
}

/// Every step of a way (.way .w-s): a ring in its colour, the line to the next (dotted for walking),
/// the step and when; past ones faded, the one they're on in its colour.
struct StepList: View {
    let steps: [TodayPage.Step]
    var current: Int?

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            ForEach(Array(steps.enumerated()), id: \.offset) { index, step in
                let colour = StepColour.of(step)
                let isLast = index == steps.count - 1
                HStack(alignment: .top, spacing: 12) {
                    Circle().strokeBorder(step.kind == .walk ? Palette.textSoft : colour, lineWidth: 3)
                        .background(Circle().fill(Palette.surface))
                        .frame(width: 17, height: 17)
                        .padding(.top, 1)
                        .background(alignment: .top) {
                            if !isLast {
                                connector(step.kind == .walk, colour: colour).frame(width: 3).padding(.top, 10).frame(maxHeight: .infinity, alignment: .top)
                                    .offset(y: 0)
                            }
                        }
                    VStack(alignment: .leading, spacing: 1) {
                        Text(step.title).font(.geist(14, .semibold)).foregroundStyle(index == current ? colour : Palette.text)
                        if !step.sub.isEmpty { Text(step.sub).font(.geist(12.5)).foregroundStyle(Palette.textSoft) }
                    }
                    Spacer(minLength: 8)
                    Text(step.time).font(.geist(13)).monospacedDigit().foregroundStyle(Palette.textSoft)
                }
                .padding(.bottom, isLast ? 0 : 14)
                .background(alignment: .topLeading) {
                    if !isLast {
                        connector(step.kind == .walk, colour: colour).frame(width: 3).padding(.leading, 7).padding(.top, 10).offset(y: 6)
                    }
                }
                .opacity(current.map { index < $0 ? 0.5 : 1 } ?? 1)
            }
        }
    }

    @ViewBuilder
    private func connector(_ walking: Bool, colour: Color) -> some View {
        if walking {
            Rectangle().fill(.clear).overlay {
                GeometryReader { proxy in
                    Path { path in
                        path.move(to: CGPoint(x: 1.5, y: 0))
                        path.addLine(to: CGPoint(x: 1.5, y: proxy.size.height))
                    }
                    .stroke(Palette.textSoft, style: StrokeStyle(lineWidth: 3, lineCap: .round, dash: [0.1, 6]))
                }
            }
        } else {
            Capsule().fill(colour)
        }
    }
}

/// The way on a map, in place: each leg in its line's colour, walking dotted, start and end marked.
struct WayMap: View {
    let title: String
    let legs: [TodayPage.Trip.Leg]
    /// While the trip is live: our navigation on the map (the phone, the next turn, Speak turns).
    var guiding: (trip: TodayPage.Trip, follow: PhoneFollow)?
    @Environment(\.dismiss) private var dismiss
    @AppStorage("speakTurns") private var speak = false
    @State private var following = true
    @State private var pull: CGFloat = 0

    var body: some View {
        let here = guiding?.follow.here
        ZStack(alignment: .top) {
            CityMap(lines: WayDrawing.lines(legs, doneBefore: here?.leg), pins: WayDrawing.pins(legs, to: legs.last?.to ?? "", start: guiding == nil),
                    fit: WayDrawing.all(legs), padding: UIEdgeInsets(top: 150, left: 50, bottom: 120, right: 50),
                    follow: guiding != nil && following, showsUser: guiding != nil)
                .ignoresSafeArea()
            VStack(spacing: 10) {
                HStack(alignment: .top, spacing: 10) {
                    // Pull the title down: the map shrinks back to where it came from.
                    VStack(alignment: .leading, spacing: 6) {
                        Capsule().fill(Palette.textSoft.opacity(0.5)).frame(width: 36, height: 5).frame(maxWidth: .infinity)
                        Text(title).font(.geist(17, .semibold)).foregroundStyle(Palette.text).lineLimit(2)
                    }
                        .padding(.horizontal, 16).padding(.top, 8).padding(.bottom, 12)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .glassEffect(.regular, in: .rect(cornerRadius: 22))
                        .contentShape(.rect)
                        .gesture(
                            DragGesture(minimumDistance: 8)
                                .onChanged { value in pull = max(0, value.translation.height) }
                                .onEnded { value in
                                    if value.translation.height > 90 || value.predictedEndTranslation.height > 220 { dismiss() }
                                    withAnimation(.spring(duration: 0.35, bounce: 0.25)) { pull = 0 }
                                }
                        )
                        .accessibilityAction(named: "Close the map") { dismiss() }
                    Button { dismiss() } label: {
                        Image(systemName: "checkmark").font(.system(size: 17, weight: .bold)).foregroundStyle(.white)
                            .frame(width: 48, height: 48).background(Palette.action, in: .circle)
                    }
                    .buttonStyle(PressStyle())
                    .accessibilityLabel("Done")
                }
                if let here, let turn = here.turn, !here.offRoute {
                    TurnRow(turn: turn, metres: here.metresToTurn)
                        .padding(14)
                        .glassEffect(.regular, in: .rect(cornerRadius: 22))
                        .transition(.opacity.combined(with: .offset(y: -8)))
                } else if let here, here.offRoute {
                    Label("You’re off the way. Go back to the line, or plan a new way on the card.", systemImage: "exclamationmark.triangle.fill")
                        .font(.geist(14, .semibold)).foregroundStyle(Palette.warnText)
                        .padding(14).frame(maxWidth: .infinity, alignment: .leading)
                        .background(Palette.warnBackground, in: .rect(cornerRadius: 18))
                }
            }
            .padding(.horizontal, 14).padding(.top, 14)
        }
        .overlay(alignment: .bottomTrailing) {
            if guiding != nil {
                VStack(spacing: 10) {
                    // Speak turns, with the phone's own voice.
                    Button { speak.toggle() } label: {
                        Image(systemName: speak ? "speaker.wave.2.fill" : "speaker.slash.fill").font(.system(size: 17, weight: .semibold))
                            .foregroundStyle(speak ? Palette.action : Palette.text)
                            .contentTransition(.symbolEffect(.replace))
                            .frame(width: 48, height: 48).glassEffect(.regular.interactive(), in: .circle)
                    }
                    .accessibilityLabel(speak ? "Stop speaking turns" : "Speak turns")
                    Button { following.toggle() } label: {
                        Image(systemName: following ? "location.north.line.fill" : "location").font(.system(size: 17, weight: .semibold))
                            .foregroundStyle(following ? Color(hex: 0x2F6FD0) : Palette.text)
                            .frame(width: 48, height: 48).glassEffect(.regular.interactive(), in: .circle)
                    }
                    .accessibilityLabel(following ? "Show the whole way" : "Follow me")
                }
                .buttonStyle(PressStyle())
                .padding(.trailing, 16).padding(.bottom, 40)
            }
        }
        // Following the finger while pulled, as a sheet does.
        .scaleEffect(1 - min(pull, 300) / 1500, anchor: .top)
        .offset(y: pull * 0.6)
        .animation(Motion.ios(0.3), value: here?.turn)
        .presentationDetents([.large])
    }
}

/// A ticket's half (.tk-top / .tk-bot): rounded outer corners and the two notches the perforation runs between.
nonisolated struct TicketHalf: Shape {
    let top: Bool

    func path(in rect: CGRect) -> Path {
        let radius: CGFloat = 26
        let body = Path(roundedRect: rect, cornerRadii: top
            ? RectangleCornerRadii(topLeading: radius, bottomLeading: 0, bottomTrailing: 0, topTrailing: radius)
            : RectangleCornerRadii(topLeading: 0, bottomLeading: radius, bottomTrailing: radius, topTrailing: 0))
        let y = top ? rect.maxY : rect.minY
        let notches = Path { path in
            path.addEllipse(in: CGRect(x: rect.minX - 11, y: y - 11, width: 22, height: 22))
            path.addEllipse(in: CGRect(x: rect.maxX - 11, y: y - 11, width: 22, height: 22))
        }
        return body.subtracting(notches)
    }
}

/// Other ways (the canvas's sheet of tickets): each way printed in its line's colour, the one the card
/// shows stamped "Your way"; Use this makes another the card's, and the sheet slides away.
private struct OtherWaysSheet: View {
    let leave: TodayPage.Leave
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @State private var busy: Int?
    @State private var problem: (way: Int, text: String)?
    @State private var open: Set<Int> = []

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                HStack(alignment: .top) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("Other ways").font(.posterGrotesque(28)).tracking(-0.4).foregroundStyle(Palette.text)
                        Text("To \(leave.to)" + (leave.start.map { " · be there by \($0)" } ?? "")).font(.geist(13.5)).foregroundStyle(Palette.textSoft)
                    }
                    Spacer()
                    Button { dismiss() } label: {
                        Image(systemName: "xmark").font(.system(size: 16, weight: .semibold)).foregroundStyle(Palette.text)
                            .frame(width: 44, height: 44)
                            .glassEffect(.regular.interactive(), in: .circle)
                    }
                    .buttonStyle(PressStyle())
                    .accessibilityLabel("Close")
                }
                ForEach(Array(leave.ways.enumerated()), id: \.element.id) { index, way in
                    ticket(way).modifier(Rise(order: index))
                }
            }
            .padding(.horizontal, 16).padding(.top, 20).padding(.bottom, 30)
        }
        .scrollIndicators(.hidden)
        .background(Palette.canvas)
        .presentationDetents([.large])
        .presentationDragIndicator(.visible)
        .presentationBackground(Palette.canvas)
    }

    private func ticket(_ way: TodayPage.Way) -> some View {
        let mine = way.id == 0
        let spareTone: (Color, Color) = way.spareMinutes < 0 ? (Palette.conflictText, Palette.conflictBackground)
            : way.spareMinutes <= 2 ? (Palette.warnText, Palette.warnBackground)
            : way.spareMinutes >= 10 ? (Color(hex: 0x17603A), Color(hex: 0xE4F4E8)) : (Palette.text, Palette.quiet)
        return VStack(spacing: 0) {
            TicketTop(way: way, mine: mine)

            // The perforation.
            Line().stroke(Color(hex: 0x211D15, opacity: 0.28), style: StrokeStyle(lineWidth: 2, dash: [6, 5]))
                .frame(height: 2).padding(.horizontal, 14)
                .background(Palette.surface)
            VStack(alignment: .leading, spacing: 8) {
                Text(way.spareWords).font(.geist(13, .bold)).foregroundStyle(spareTone.0)
                    .padding(.horizontal, 10).frame(height: 26)
                    .background(spareTone.1, in: .capsule)
                WhyLines(way: way, start: leave.start)
                if open.contains(way.id) {
                    StepList(steps: TodayPage.Step.of(way.legs, to: leave.to, start: leave.start))
                        .padding(.top, 6)
                        .transition(.opacity.combined(with: .offset(y: -6)))
                }
                Group {
                    if mine {
                        Label("Your way", systemImage: "checkmark").labelStyle(Tight())
                            .font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                            .frame(maxWidth: .infinity).frame(height: 44)
                            .background(Palette.quiet, in: .capsule)
                    } else {
                        Button {
                            busy = way.id
                            Task {
                                if let why = await model.keepWay(session.api, way: way) {
                                    withAnimation(.snappy) { problem = (way.id, why) }
                                } else {
                                    dismiss()
                                }
                                busy = nil
                            }
                        } label: {
                            Group {
                                if busy == way.id { ProgressView().tint(.white) } else { Text("Use this") }
                            }
                            .font(.geist(15, .semibold)).foregroundStyle(.white)
                            .frame(maxWidth: .infinity).frame(height: 44)
                            .background(Palette.action, in: .capsule)
                            .contentShape(.capsule)
                        }
                        .buttonStyle(PressStyle())
                        .disabled(busy != nil)
                    }
                }
                .padding(.top, 4)
                if let problem, problem.way == way.id {
                    Label(problem.text, systemImage: "exclamationmark.circle").labelStyle(Tight())
                        .font(.geist(13, .semibold)).foregroundStyle(Palette.conflictText)
                        .transition(.opacity.combined(with: .offset(y: -6)))
                }
                if !way.legs.isEmpty {
                    Button { withAnimation(Motion.ios(0.4)) { if open.contains(way.id) { open.remove(way.id) } else { open.insert(way.id) } } } label: {
                        HStack(spacing: 5) {
                            Text("Journey details")
                            Image(systemName: "chevron.down").font(.system(size: 11, weight: .bold)).rotationEffect(.degrees(open.contains(way.id) ? 180 : 0))
                        }
                        .font(.geist(14, .semibold)).foregroundStyle(TodayLink.ink)
                        .frame(minHeight: 36)
                    }
                    .buttonStyle(PressStyle())
                }
            }
            .padding(.top, 14).padding(.horizontal, 18).padding(.bottom, 12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Palette.surface)
            .clipShape(TicketHalf(top: false))
        }
        .shadow(color: Color(hex: 0x19140C, opacity: 0.22), radius: 9, y: 10)
    }

}

/// A Travel ticket's top half: the line plates, Leave → There, the minutes, the line's colour.
/// Other ways and Getting there show the same ticket.
struct TicketTop: View {
    let way: TodayPage.Way
    var mine = false

    var body: some View {
        // TRIP_LOOK: the line's colour; a change runs from the first line into the second; bike teal, walking soft brown.
        let look = LineLook(hex: way.lines.first?.color ?? (way.mode == "bike" ? "#00768B" : "#6A6253"))
        let fill = way.lines.count > 1 ? LineLook.fill(from: look, to: LineLook(hex: way.lines[1].color)) : look.fill
        let ink = look.ink
        VStack(alignment: .leading, spacing: 0) {
            if mine {
                Text("YOUR WAY").font(.posterMarker(15)).foregroundStyle(Motion.ink)
                    .padding(.horizontal, 10).padding(.top, 3).padding(.bottom, 2)
                    .background(Color(hex: 0xFFE363))
                    .rotationEffect(.degrees(-2))
                    .padding(.bottom, 10)
            }
            HStack(spacing: 8) {
                if way.lines.isEmpty {
                    plate(way.mode == "bike" ? "Bike" : "Walk", symbol: way.mode == "bike" ? "bicycle" : "figure.walk", colour: look.deep)
                }
                ForEach(Array(way.lines.enumerated()), id: \.offset) { index, line in
                    if index > 0 { Image(systemName: "chevron.right").font(.system(size: 12, weight: .bold)) }
                    plate(Board.badge(line.line), symbol: "tram.fill", colour: LineLook(hex: line.color).deep)
                }
                if let departs = way.departs, !way.lines.isEmpty {
                    Text("at \(departs)").font(.geist(16, .bold))
                }
            }
            HStack(alignment: .bottom, spacing: 10) {
                VStack(alignment: .leading, spacing: 4) {
                    Text("Leave").font(.geist(12.5, .semibold)).opacity(0.9)
                    Text(way.leaveTime).font(.posterGrotesque(42)).tracking(-1).monospacedDigit()
                }
                Image(systemName: "arrow.right").font(.system(size: 20, weight: .semibold)).padding(.bottom, 10)
                VStack(alignment: .leading, spacing: 4) {
                    Text("There").font(.geist(12.5, .semibold)).opacity(0.9)
                    Text(way.arriveTime).font(.posterGrotesque(42)).tracking(-1).monospacedDigit()
                }
            }
            .padding(.top, 12)
        }
        .foregroundStyle(ink)
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.top, 16).padding(.horizontal, 18).padding(.bottom, 18)
        .background(alignment: .topTrailing) {
            Text(way.lines.count == 1 ? Board.badge(way.lines[0].line) : "")
                .font(.posterGrotesque(170)).foregroundStyle(ink.opacity(0.13))
                .offset(x: 8, y: -18)
                .lineLimit(1).fixedSize()
        }
        .overlay(alignment: .topTrailing) {
            Text("\(way.minutes) min").font(.geist(13, .bold)).foregroundStyle(ink)
                .padding(.horizontal, 11).frame(height: 28)
                .background(.black.opacity(0.28), in: .capsule)
                .padding(16)
        }
        .background(fill)
        .clipShape(TicketHalf(top: true))
    }

    private func plate(_ text: String, symbol: String, colour: Color) -> some View {
        Label(text, systemImage: symbol).labelStyle(Tight())
            .font(.posterGrotesque(20)).foregroundStyle(colour)
            .padding(.leading, 8).padding(.trailing, 10).frame(height: 32)
            .background(Color(hex: 0xFFF8E6), in: .rect(cornerRadius: 8))
    }
}

/// A ticket's reasons (.tk-why): changes, walking, and how close it cuts it.
private struct WhyLines: View {
    let way: TodayPage.Way
    let start: String?

    var body: some View {
        let lines: [(String, String)] = [
            way.lines.isEmpty ? nil : ("checkmark", way.transfers == 0 ? "No change" : way.transfers == 1 ? "1 change" : "\(way.transfers) changes"),
            way.walkMinutes > 0 ? ("figure.walk", "\(way.walkMinutes) min walking") : nil,
            way.spareMinutes < 0 ? ("exclamationmark.triangle.fill", "\(-way.spareMinutes) min after your \(start ?? "start")")
                : way.spareMinutes <= 2 ? ("exclamationmark.triangle.fill", "Only \(way.spareMinutes) min before your \(start ?? "start")") : nil,
        ].compactMap { $0 }
        FlowLines(lines: lines)
    }
}

private struct FlowLines: View {
    let lines: [(String, String)]

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            ForEach(Array(lines.enumerated()), id: \.offset) { _, line in
                Label(line.1, systemImage: line.0).labelStyle(Tight())
                    .font(.geist(13.5, .medium)).foregroundStyle(Palette.text)
            }
        }
    }
}

nonisolated struct Line: Shape {
    func path(in rect: CGRect) -> Path {
        Path { path in
            path.move(to: CGPoint(x: rect.minX, y: rect.midY))
            path.addLine(to: CGPoint(x: rect.maxX, y: rect.midY))
        }
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
            .foregroundStyle(words.tone == .conflict ? Palette.conflictText : words.tone == .tight ? Palette.warnText : Palette.textSoft)
            .padding(.bottom, 14)
    }
}

/// Free time on the line, and what's open then nearby.
private struct FreeTime: View {
    let page: TodayPage
    let free: TodayPage.Free
    @State private var mapOpen = false

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
                if free.places.contains(where: { $0.latitude != nil }) {
                    Button { mapOpen = true } label: {
                        Label("See them on the map", systemImage: "map").labelStyle(Tight())
                            .font(.geist(13.5, .semibold)).foregroundStyle(TodayLink.ink)
                            .padding(.horizontal, 12).padding(.vertical, 8)
                    }
                    .buttonStyle(PressStyle())
                }
            }
            .padding(.vertical, 4)
            .background(Palette.quiet, in: .rect(cornerRadius: 16))
        }
        .padding(.top, 8).padding(.bottom, 16)
        .sheet(isPresented: $mapOpen) { NearbyMap(page: page, free: free) }
    }
}

/// The free time's places on a map, in place (the canvas's map with its Nearby card): each marked,
/// the list under the map to add one, and more places in Travel.
private struct NearbyMap: View {
    let page: TodayPage
    let free: TodayPage.Free
    @Environment(\.dismiss) private var dismiss
    @Environment(\.shell) private var shell

    var body: some View {
        ZStack(alignment: .bottom) {
            let pins = free.places.compactMap { place -> CityMap.Pin? in
                guard let lat = place.latitude, let lng = place.longitude else { return nil }
                return CityMap.Pin(id: "p\(place.id)", coordinate: CLLocationCoordinate2D(latitude: lat, longitude: lng), title: place.name, kind: .place(symbol: PlaceSymbol.name(place.kind)))
            }
            CityMap(lines: [], pins: pins, fit: pins.map(\.coordinate),
                    padding: UIEdgeInsets(top: 90, left: 50, bottom: 360, right: 50), showsUser: true)
                .ignoresSafeArea()
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    Label("Nearby", systemImage: "mappin").labelStyle(Tight())
                        .font(.geist(13, .bold)).foregroundStyle(.white)
                        .padding(.horizontal, 10).frame(height: 28)
                        .background(Color(hex: 0x2F78C8), in: .rect(cornerRadius: 8))
                    VStack(alignment: .leading, spacing: 1) {
                        Text(free.anchorArea.map { "Near \($0)" } ?? "Near \(free.near ?? "you")").font(.geist(16, .bold))
                        if let line = page.freeLine { Text(line).font(.geist(12.5)).foregroundStyle(Palette.textSoft) }
                    }
                }
                .padding(.bottom, 8)
                ForEach(free.places) { place in
                    HStack(spacing: 12) {
                        Image(systemName: PlaceSymbol.name(place.kind)).font(.system(size: 14, weight: .semibold))
                            .frame(width: 32, height: 32).background(Palette.quiet, in: .circle)
                        VStack(alignment: .leading, spacing: 1) {
                            Text(place.name).font(.geist(15.5, .semibold)).lineLimit(1)
                            Text(place.line).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1)
                        }
                        Spacer(minLength: 0)
                        AddButton(candidate: place.candidate, date: page.date, title: place.name)
                    }
                    .padding(.vertical, 6)
                    Divider().overlay(Palette.border)
                }
                Button { dismiss(); shell.show(.travel) } label: {
                    Label("More places in Travel", systemImage: "chevron.right").labelStyle(TrailingIcon())
                        .font(.geist(14.5, .semibold)).foregroundStyle(TodayLink.ink)
                        .frame(maxWidth: .infinity).frame(height: 44)
                }
                .buttonStyle(PressStyle())
            }
            .foregroundStyle(Palette.text)
            .padding(16)
            .background(Palette.surface, in: .rect(cornerRadius: 26))
            .shadow(color: Color(hex: 0x19140C, opacity: 0.2), radius: 14, y: 8)
            .padding(10)
        }
        .overlay(alignment: .topTrailing) {
            Button { dismiss() } label: {
                Image(systemName: "xmark").font(.system(size: 16, weight: .semibold)).foregroundStyle(Palette.text)
                    .frame(width: 44, height: 44).glassEffect(.regular.interactive(), in: .circle)
            }
            .buttonStyle(PressStyle())
            .padding(16)
            .accessibilityLabel("Close")
        }
        .presentationDetents([.large])
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

/// A plan of their own (the canvas's Add a plan): What, Where (optional) and At, then Add to today.
/// Opened from a plan's menu it edits that plan instead.
private struct AddPlanSheet: View {
    let date: String
    var editing: TodayPage.Plan?
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @State private var name = ""
    @State private var place = ""
    @State private var at = Calendar.current.date(bySettingHour: 16, minute: 0, second: 0, of: .now) ?? .now
    @State private var picking = false
    @State private var saving = false
    @FocusState private var focus: Field?

    enum Field { case what, place }

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Text(editing == nil ? "Add a plan" : "Edit plan").font(.posterGrotesque(26)).tracking(-0.3)
                Spacer()
                Button { dismiss() } label: {
                    Image(systemName: "xmark").font(.system(size: 16, weight: .semibold))
                        .frame(width: 44, height: 44).glassEffect(.regular.interactive(), in: .circle)
                }
                .buttonStyle(PressStyle())
                .accessibilityLabel("Close")
            }
            .foregroundStyle(Palette.text)
            .padding(.bottom, 6)
            field("What?") {
                TextField("e.g. Doctor", text: $name).focused($focus, equals: .what).submitLabel(.next)
                    .onSubmit { focus = .place }
            }
            field("Where? Optional") {
                TextField("A place or address", text: $place).focused($focus, equals: .place).submitLabel(.done)
            }
            Button { focus = nil; withAnimation(Motion.ios(0.35)) { picking.toggle() } } label: {
                field("At") { Text(TodayPage.clock(at)).monospacedDigit().frame(maxWidth: .infinity, alignment: .leading) }
            }
            .buttonStyle(.plain)
            if picking {
                DatePicker("At", selection: $at, displayedComponents: .hourAndMinute)
                    .datePickerStyle(.wheel).labelsHidden()
                    .frame(maxWidth: .infinity)
                    .transition(.opacity.combined(with: .offset(y: -6)))
            }
            Button(action: save) {
                Group {
                    if saving { ProgressView().tint(.white) } else { Text(editing == nil ? "Add to today" : "Save") }
                }
                .font(.geist(16, .semibold))
                .foregroundStyle(ready ? .white : Palette.textSoft)
                .frame(maxWidth: .infinity).frame(height: 52)
                .background(ready ? Palette.action : Palette.quiet, in: .capsule)
            }
            .buttonStyle(PressStyle())
            .disabled(!ready || saving)
            .padding(.top, 6)
            .animation(.snappy, value: ready)
        }
        .padding(20)
        .background(Palette.canvas)
        .presentationDetents(picking ? [.large] : [.height(430)])
        .presentationDragIndicator(.visible)
        .presentationBackground(Palette.canvas)
        .onAppear {
            if let editing {
                name = editing.title
                place = editing.place ?? ""
                if let start = editing.start, let time = Self.time(start) { at = time }
            } else {
                focus = .what
            }
        }
    }

    private var ready: Bool { !name.trimmingCharacters(in: .whitespaces).isEmpty }

    /// The canvas's .fld2: a small label over the value, on the card with its hairline.
    private func field(_ label: String, @ViewBuilder value: () -> some View) -> some View {
        VStack(alignment: .leading, spacing: 3) {
            Text(label).font(.geist(12)).foregroundStyle(Palette.textSoft)
            value().font(.geist(16, .medium)).foregroundStyle(Palette.text)
        }
        .padding(.vertical, 9).padding(.horizontal, 12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Palette.surface, in: .rect(cornerRadius: 14))
        .overlay { RoundedRectangle(cornerRadius: 14).strokeBorder(Palette.border) }
    }

    private func save() {
        saving = true
        let start = TodayPage.clock(at)
        let end = TodayPage.clock(at.addingTimeInterval(TimeInterval((editing.flatMap(Self.length) ?? 60) * 60)))
        Task {
            let done: Bool
            if let editing {
                done = await model.editPlan(session.api, plan: editing, date: date, name: name, start: start, end: end, place: place)
            } else {
                done = await model.addPlan(session.api, name: name, date: date, start: start, end: end, place: place)
            }
            saving = false
            if done { dismiss() }
        }
    }

    private static func time(_ clock: String) -> Date? {
        let parts = clock.split(separator: ":").compactMap { Int($0) }
        guard parts.count == 2 else { return nil }
        return Calendar.current.date(bySettingHour: parts[0], minute: parts[1], second: 0, of: .now)
    }

    /// How long the plan runs, so moving it keeps its length.
    private static func length(_ plan: TodayPage.Plan) -> Int? {
        guard let start = plan.start, let end = plan.end, let a = time(start), let b = time(end) else { return nil }
        let minutes = Int(b.timeIntervalSince(a) / 60)
        return minutes > 0 ? minutes : minutes + 24 * 60
    }
}

/// Add an event or a place to a day's plan, in place: the + turns to a check once it's in.
struct AddButton: View {
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
                .foregroundStyle(added ? Motion.green : Palette.action)
                .contentTransition(.symbolEffect(.replace))
                .frame(width: 36, height: 36)
                .background(Motion.green.opacity(added ? 0.14 : 0), in: .circle)
                .contentShape(.circle)
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
                Button { sheet = .whatsOn() } label: {
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
                            onOpen: { sheet = .whatsOn(open: idea.candidate, date: idea.tomorrow ? page.tomorrowDate : nil) },
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
    var onOpen: (() -> Void)?
    var onWhy: (() -> Void)?
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model

    var body: some View {
        HStack(spacing: 12) {
            // The poster and the title open its details (e.open), as on the canvas.
            Button { onOpen?() } label: {
                Poster(label: idea.posterLabel(at: now), night: night, tilt: tilt)
            }
            .buttonStyle(PressStyle())
            .disabled(onOpen == nil)
            .accessibilityLabel("Open \(idea.title)")
            VStack(alignment: .leading, spacing: 2) {
                Button { onOpen?() } label: {
                    Text(idea.title).font(.geist(15, .semibold)).foregroundStyle(Palette.text).lineLimit(2).multilineTextAlignment(.leading)
                }
                .buttonStyle(.plain)
                .disabled(onOpen == nil)
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
                    // Saved: a star in a quiet circle (.id-btn.on); not yet: the bookmark.
                    Image(systemName: idea.saved ? "star.fill" : "bookmark")
                        .font(.system(size: 16, weight: .semibold))
                        .foregroundStyle(idea.saved ? Palette.text : Palette.textSoft)
                        .contentTransition(.symbolEffect(.replace))
                        .symbolEffect(.bounce, value: idea.saved)
                        .frame(width: 36, height: 36)
                        .background(Palette.quiet.opacity(idea.saved ? 1 : 0), in: .circle)
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
struct Poster: View {
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
                    Button { sheet = .weather(page) } label: { WeatherRow(weather: weather) }
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
                Image(systemName: WeatherSymbol.name(weather.icon)).symbolRenderingMode(.monochrome)
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
                    ForEach(weather.hours.prefix(6), id: \.hour) { hour in
                        VStack(spacing: 6) {
                            Text(String(hour.hour.prefix(2))).font(.geist(12.5, .medium)).foregroundStyle(Palette.text)
                            Image(systemName: WeatherSymbol.name(hour.icon ?? "")).symbolRenderingMode(.monochrome)
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
                ListRow(symbol: "composer", title: continuation.title,
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
    var plans: [TodayPage.Plan] = []
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
                        if model.added.contains(item.candidate) || plans.contains(where: { $0.id == item.candidate }) {
                            Text("In your plan").font(.geist(14, .semibold)).foregroundStyle(TodayLink.ink)
                                .padding(.trailing, 14).frame(minHeight: 44)
                                .transition(.opacity)
                        } else {
                            Button("Add to a plan") {
                                Task { await model.change(session.api, action: "add", date: item.date ?? date, candidate: item.candidate, symbol: "calendar.badge.plus") }
                            }
                            .font(.geist(14, .semibold)).foregroundStyle(TodayLink.ink)
                            .padding(.trailing, 14).frame(minHeight: 44)
                        }
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

/// The canvas's weather sheet: now, what it means for each plan today, then Hours / Rain / Next days.
private struct WeatherSheet: View {
    let page: TodayPage
    @Environment(\.dismiss) private var dismiss
    @State private var tab = 0

    private var weather: TodayPage.Weather? { page.weather }

    var body: some View {
        ScrollView {
            if let weather {
                VStack(alignment: .leading, spacing: 0) {
                    top
                    now(weather)
                    let rows = forYourDay(weather)
                    if !rows.isEmpty {
                        Text("For your day").font(.posterGrotesque(20)).foregroundStyle(Palette.text)
                            .padding(.top, 26).padding(.bottom, 10).padding(.leading, 4)
                        VStack(spacing: 0) {
                            ForEach(Array(rows.enumerated()), id: \.offset) { index, row in
                                if index > 0 { Rectangle().fill(Palette.border).frame(height: 1).padding(.leading, 59) }
                                row.modifier(WxUp(delay: 0.08 + 0.05 * Double(index)))
                            }
                        }
                        .clipShape(.rect(cornerRadius: 22))
                        .card(radius: 22)
                    }
                    WxTabs(tab: $tab).padding(.top, 22).padding(.bottom, 12)
                    Group {
                        switch tab {
                        case 0: WxHours(hours: weather.hours)
                        case 1: WxRain(weather: weather, plans: coming)
                        default: WxDays(weather: weather)
                        }
                    }
                    .id(tab)
                    // The old pane goes at once; the new one rises in (wxup), as on the canvas.
                    .transition(.identity)
                    .modifier(WxUp(delay: 0, duration: 0.32))
                }
                .padding(.horizontal, 16).padding(.top, 20).padding(.bottom, 40)
            }
        }
        .scrollIndicators(.hidden)
        .background(Palette.canvas)
        .presentationDetents([.large])
        .presentationDragIndicator(.visible)
        .presentationBackground(Palette.canvas)
    }

    private var top: some View {
        HStack(alignment: .top) {
            VStack(alignment: .leading, spacing: 2) {
                Text("Weather").font(.posterGrotesque(28)).tracking(-0.4).foregroundStyle(Palette.text)
                Text("Cologne · updated \(TodayPage.clock(page.now))").font(.geist(13)).foregroundStyle(Palette.textSoft)
            }
            Spacer()
            Button { dismiss() } label: {
                Image(systemName: "xmark").font(.system(size: 16, weight: .semibold)).foregroundStyle(Palette.text)
                    .frame(width: 44, height: 44)
                    .glassEffect(.regular.interactive(), in: .circle)
            }
            .buttonStyle(PressStyle())
            .accessibilityLabel("Close")
        }
        .padding(.bottom, 6)
    }

    private func now(_ weather: TodayPage.Weather) -> some View {
        HStack(spacing: 12) {
            Image(systemName: WeatherSymbol.name(weather.icon)).symbolRenderingMode(.monochrome)
                .font(.system(size: 44)).foregroundStyle(WeatherSymbol.tint(weather.icon))
                .frame(width: 52, height: 52)
            Text("\(weather.temperature)°").font(.posterGrotesque(56)).tracking(-1).foregroundStyle(Palette.text)
            VStack(alignment: .leading, spacing: 2) {
                Text(weather.condition).font(.geist(17, .bold)).foregroundStyle(Palette.text)
                if let range = weather.range { Text(range).font(.geist(13.5)).foregroundStyle(Palette.textSoft) }
            }
        }
        .padding(.top, 8).padding(.bottom, 4).padding(.horizontal, 2)
        .modifier(WxUp(delay: 0, duration: 0.5))
        .accessibilityElement(children: .combine)
    }

    /// Today's plans still to come, each with the weather at its hour.
    private var coming: [TodayPage.Plan] {
        page.plans.filter { !$0.completed && $0.state == "upcoming" && $0.start != nil }
    }

    private func forYourDay(_ weather: TodayPage.Weather) -> [WxPlanRow] {
        coming.compactMap { plan in
            guard let start = plan.start, let hour = weather.at(start) else { return nil }
            let sky = [hour.condition?.lowercased(), hour.rain.map(WxRain.words)].compactMap { $0 }.joined(separator: ", ")
            return WxPlanRow(time: start, title: plan.title, note: [plan.shortPlace, sky.isEmpty ? nil : sky].compactMap { $0 }.joined(separator: " · "),
                             icon: hour.icon ?? weather.icon, temperature: hour.temperature)
        }
    }
}

private struct WxPlanRow: View {
    let time: String
    let title: String
    let note: String
    let icon: String
    let temperature: Int?

    var body: some View {
        HStack(spacing: 13) {
            Text(time).font(.geist(15, .bold)).monospacedDigit().frame(width: 46, alignment: .leading)
            VStack(alignment: .leading, spacing: 1) {
                Text(title).font(.geist(16, .semibold)).lineLimit(1)
                if !note.isEmpty { Text(note).font(.geist(13.5)).foregroundStyle(Palette.textSoft).lineLimit(2) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            Image(systemName: WeatherSymbol.name(icon)).symbolRenderingMode(.monochrome)
                .font(.system(size: 18)).foregroundStyle(WeatherSymbol.tint(icon)).frame(width: 20)
            Text(temperature.map { "\($0)°" } ?? "–").font(.geist(16, .bold)).frame(width: 30, alignment: .trailing)
        }
        .foregroundStyle(Palette.text)
        .padding(.horizontal, 14).padding(.vertical, 10).frame(minHeight: 58)
        .accessibilityElement(children: .combine)
    }
}

/// The canvas's segmented control: a card thumb that springs between Hours, Rain and Next days.
private struct WxTabs: View {
    @Binding var tab: Int
    private let names = ["Hours", "Rain", "Next days"]

    var body: some View {
        GeometryReader { proxy in
            let width = (proxy.size.width - 4) / 3
            ZStack(alignment: .leading) {
                RoundedRectangle(cornerRadius: 16).fill(Palette.surface)
                    .shadow(color: Color(hex: 0x211D15).opacity(0.16), radius: 2, y: 1)
                    .frame(width: width).padding(.vertical, 2)
                    .offset(x: 2 + width * CGFloat(tab))
                HStack(spacing: 0) {
                    ForEach(names.indices, id: \.self) { index in
                        Button { withAnimation(.spring(duration: 0.42, bounce: 0.3)) { tab = index } } label: {
                            Text(names[index]).font(.geist(14, .semibold))
                                .foregroundStyle(tab == index ? Palette.text : Palette.textSoft)
                                .frame(maxWidth: .infinity, maxHeight: .infinity).contentShape(.rect)
                        }
                        .buttonStyle(.plain)
                        .accessibilityAddTraits(tab == index ? [.isSelected] : [])
                    }
                }
                .padding(.horizontal, 2)
            }
        }
        .frame(height: 36)
        .background(Palette.quiet, in: .rect(cornerRadius: 18))
        .sensoryFeedback(.selection, trigger: tab)
    }
}

/// The hour labels under the chart and the bars: "Now", then the hour.
private struct WxHourLabels: View {
    let hours: [TodayPage.Weather.Hour]

    var body: some View {
        HStack(spacing: 0) {
            ForEach(Array(hours.enumerated()), id: \.offset) { index, hour in
                Text(index == 0 ? "Now" : String(Int(hour.hour.prefix(2)) ?? 0))
                    .font(.geist(11.5, .semibold)).monospacedDigit()
                    .foregroundStyle(index == 0 ? Palette.text : Palette.textSoft)
                    .frame(maxWidth: .infinity)
            }
        }
        .padding(.top, 4)
    }
}

private enum WxPalette {
    static let sun = Color(hex: 0xF2A516)
    static let rain = Color(hex: 0x3F86D6)
    static let rainText = Color(light: 0x2F6FD0, dark: 0x7FB0F5)

    /// The canvas's wxTone: cold blue, mild green, warm yellow, hot orange.
    static func tone(_ temperature: Int) -> Color {
        temperature <= 4 ? Color(hex: 0x5AC8FA) : temperature <= 8 ? Color(hex: 0x6FD3B0) : temperature <= 11 ? Color(hex: 0xFFD60A) : Color(hex: 0xFF9F0A)
    }
}

/// Hours: each hour's sky, a smooth line through the temperatures, and the hours under it.
private struct WxHours: View {
    let hours: [TodayPage.Weather.Hour]
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var drawn = false

    private var shown: [TodayPage.Weather.Hour] { hours.filter { $0.temperature != nil } }

    var body: some View {
        let hours = shown
        let temps = hours.compactMap(\.temperature)
        VStack(spacing: 0) {
            HStack(spacing: 0) {
                ForEach(Array(hours.enumerated()), id: \.offset) { index, hour in
                    Image(systemName: WeatherSymbol.name(hour.icon ?? "")).symbolRenderingMode(.monochrome)
                        .font(.system(size: 17)).foregroundStyle(WeatherSymbol.tint(hour.icon ?? "")).frame(height: 20)
                        .frame(maxWidth: .infinity)
                        .modifier(WxFade(delay: 0.035 * Double(index)))
                }
            }
            if temps.count > 1 {
                GeometryReader { proxy in
                    let points = WxCurve.points(temps, in: proxy.size)
                    ZStack(alignment: .topLeading) {
                        WxCurve(temps: temps, closed: true)
                            .fill(LinearGradient(colors: [WxPalette.sun.opacity(0.32), WxPalette.sun.opacity(0)], startPoint: .top, endPoint: .bottom))
                            .opacity(drawn ? 1 : 0)
                            .animation(reduceMotion ? nil : .easeOut(duration: 0.6).delay(0.45), value: drawn)
                        WxCurve(temps: temps, closed: false)
                            .trim(from: 0, to: drawn ? 1 : 0)
                            .stroke(WxPalette.sun, style: StrokeStyle(lineWidth: 3, lineCap: .round, lineJoin: .round))
                            .animation(reduceMotion ? nil : Motion.ios(0.9).delay(0.1), value: drawn)
                        ForEach(Array(points.enumerated()), id: \.offset) { index, point in
                            WxPoint(now: index == 0, temperature: temps[index], order: index).position(point)
                        }
                    }
                }
                .frame(height: 150).padding(.top, 2)
            }
            WxHourLabels(hours: hours)
        }
        .onAppear { drawn = true }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(hours.compactMap { hour in hour.temperature.map { "\(hour.hour) \($0)°" } }.joined(separator: ", "))
    }
}

private struct WxPoint: View {
    let now: Bool
    let temperature: Int
    let order: Int

    var body: some View {
        ZStack {
            Circle().fill(now ? WxPalette.sun : Palette.surface).frame(width: 8, height: 8)
                .overlay { Circle().strokeBorder(WxPalette.sun, lineWidth: now ? 0 : 2.5).frame(width: 13, height: 13) }
                .background { if now { Circle().fill(WxPalette.sun.opacity(0.3)).frame(width: 16, height: 16) } }
                .modifier(WxFade(delay: 0.15 + 0.055 * Double(order)))
            Text("\(temperature)°").font(.geist(12.5, .bold)).foregroundStyle(Palette.text).fixedSize()
                .offset(y: -18)
                .modifier(WxUp(delay: 0.18 + 0.055 * Double(order), duration: 0.35))
        }
    }
}

/// A smooth line through the hourly temperatures, each point over its column (the canvas's wxCurve).
nonisolated private struct WxCurve: Shape {
    let temps: [Int]
    let closed: Bool

    static func points(_ temps: [Int], in size: CGSize) -> [CGPoint] {
        guard let low = temps.min(), let high = temps.max() else { return [] }
        let lo = Double(low - 1), hi = Double(high + 1), count = Double(temps.count)
        return temps.enumerated().map { index, temperature in
            CGPoint(x: (Double(index) + 0.5) * size.width / count,
                    y: 34 + (1 - (Double(temperature) - lo) / (hi - lo)) * (size.height - 58))
        }
    }

    func path(in rect: CGRect) -> Path {
        let points = Self.points(temps, in: rect.size)
        var path = Path()
        guard let first = points.first, let last = points.last else { return path }
        path.move(to: first)
        for index in 0..<(points.count - 1) {
            let p0 = points[max(index - 1, 0)], p1 = points[index], p2 = points[index + 1], p3 = points[min(index + 2, points.count - 1)]
            path.addCurve(to: p2,
                          control1: CGPoint(x: p1.x + (p2.x - p0.x) / 6, y: p1.y + (p2.y - p0.y) / 6),
                          control2: CGPoint(x: p2.x - (p3.x - p1.x) / 6, y: p2.y - (p3.y - p1.y) / 6))
        }
        if closed {
            path.addLine(to: CGPoint(x: last.x, y: rect.height))
            path.addLine(to: CGPoint(x: first.x, y: rect.height))
            path.closeSubpath()
        }
        return path
    }
}

/// Rain: what the next hours hold, then the chance of rain each hour as bars.
private struct WxRain: View {
    let weather: TodayPage.Weather
    let plans: [TodayPage.Plan]

    static func words(_ chance: Int) -> String {
        chance >= 50 ? "rain likely" : chance >= 20 ? "\(chance)% chance of rain" : "dry"
    }

    private var hours: [TodayPage.Weather.Hour] { weather.hours }

    /// "Rain from about 21:00", or the server's own words for the day.
    private var title: String {
        if let wet = hours.first(where: { ($0.rain ?? 0) >= 50 }) { return "Rain from about \(wet.hour)" }
        if let maybe = hours.first(where: { ($0.rain ?? 0) >= 20 }) { return "Maybe rain from about \(maybe.hour)" }
        return weather.summary ?? "Dry for the next \(hours.count) hours"
    }

    /// The peak, and whether a plan today falls in the rain.
    private var note: String? {
        var parts: [String] = []
        if let peak = hours.max(by: { ($0.rain ?? 0) < ($1.rain ?? 0) }), let chance = peak.rain {
            if chance >= 20 {
                parts.append("Up to \(chance)% around \(peak.hour).")
            } else if let last = hours.last {
                parts.append(chance < 5 ? "No rain expected until \(last.hour)." : "No hour above \(chance)% until \(last.hour).")
            }
        }
        let wet = plans.first { plan in plan.start.flatMap { weather.at($0)?.rain }.map { $0 >= 50 } ?? false }
        if let wet, let start = wet.start {
            parts.append("Your \(wet.title) at \(start) falls in it.")
        } else if !plans.isEmpty, plans.allSatisfy({ plan in (plan.start.flatMap { weather.at($0)?.rain } ?? 0) < 20 }) {
            parts.append(plans.count == 1 ? "Your plan today looks dry." : "Your plans today look dry.")
        }
        return parts.isEmpty ? nil : parts.joined(separator: " ")
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            VStack(alignment: .leading, spacing: 3) {
                Text(title).font(.geist(17, .bold)).foregroundStyle(Palette.text)
                if let note { Text(note).font(.geist(14)).foregroundStyle(Palette.textSoft).lineSpacing(2) }
            }
            .padding(.horizontal, 2).padding(.top, 2).padding(.bottom, 14)
            HStack(alignment: .bottom, spacing: 5) {
                ForEach(Array(hours.enumerated()), id: \.offset) { index, hour in
                    WxBar(chance: hour.rain ?? 0, order: index)
                }
            }
            .frame(height: 130)
            WxHourLabels(hours: hours)
            Text("Chance of rain each hour").font(.geist(12.5)).foregroundStyle(Palette.textSoft).padding(.top, 10)
        }
        .accessibilityElement(children: .combine)
    }
}

private struct WxBar: View {
    let chance: Int
    let order: Int
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var grown = false

    var body: some View {
        VStack(spacing: 4) {
            Spacer(minLength: 0)
            if chance >= 20 { Text("\(chance)%").font(.geist(10.5, .bold)).foregroundStyle(WxPalette.rainText).fixedSize() }
            UnevenRoundedRectangle(topLeadingRadius: 6, bottomLeadingRadius: 3, bottomTrailingRadius: 3, topTrailingRadius: 6)
                .fill(WxPalette.rain.opacity(chance >= 50 ? 1 : chance >= 20 ? 0.62 : 0.3))
                .frame(height: 130 * CGFloat(max(chance, 3)) / 100)
                .scaleEffect(x: 1, y: grown || reduceMotion ? 1 : 0, anchor: .bottom)
        }
        .frame(maxWidth: .infinity)
        .onAppear { withAnimation(.spring(duration: 0.55, bounce: 0.35).delay(0.035 * Double(order))) { grown = true } }
    }
}

/// Next days: each day's sky, its most likely rain, and its low to high on one shared scale.
private struct WxDays: View {
    let weather: TodayPage.Weather

    private var days: [TodayPage.Weather.Day] { weather.days.filter { $0.low != nil && $0.high != nil } }

    var body: some View {
        let days = days
        let lo = days.compactMap(\.low).min() ?? 0, hi = max(days.compactMap(\.high).max() ?? 1, lo + 1)
        let place = { (t: Int) in CGFloat(t - lo) / CGFloat(hi - lo) }
        VStack(spacing: 0) {
            ForEach(Array(days.enumerated()), id: \.element.id) { index, day in
                if index > 0 { Rectangle().fill(Palette.border).frame(height: 1) }
                WxDayRow(name: index == 0 ? "Today" : Self.weekday(day.date), day: day,
                         from: place(day.low ?? lo), to: place(day.high ?? hi),
                         now: index == 0 ? place(min(max(weather.temperature, lo), hi)) : nil, order: index)
            }
        }
        .padding(.vertical, 4)
        .clipShape(.rect(cornerRadius: 22))
        .card(radius: 22)
    }

    static func weekday(_ date: String) -> String {
        let read = DateFormatter()
        read.locale = Locale(identifier: "en_GB")
        read.timeZone = TimeZone(identifier: "Europe/Berlin")
        read.dateFormat = "yyyy-MM-dd"
        guard let day = read.date(from: date) else { return date }
        read.dateFormat = "EEE"
        return read.string(from: day)
    }
}

private struct WxDayRow: View {
    let name: String
    let day: TodayPage.Weather.Day
    let from: CGFloat
    let to: CGFloat
    let now: CGFloat?
    let order: Int
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var grown = false

    var body: some View {
        HStack(spacing: 8) {
            Text(name).font(.geist(16, .semibold)).frame(width: 58, alignment: .leading)
            VStack(spacing: 1) {
                Image(systemName: WeatherSymbol.name(day.icon ?? "")).symbolRenderingMode(.monochrome)
                    .font(.system(size: 17)).foregroundStyle(WeatherSymbol.tint(day.icon ?? "")).frame(height: 20)
                if let rain = day.rain, rain >= 30 { Text("\(rain)%").font(.geist(11, .bold)).foregroundStyle(WxPalette.rainText) }
            }
            .frame(width: 44)
            Text("\(day.low ?? 0)°").font(.geist(16, .semibold)).foregroundStyle(Palette.textSoft).frame(width: 30, alignment: .trailing)
            GeometryReader { proxy in
                let width = proxy.size.width
                ZStack(alignment: .leading) {
                    Capsule().fill(Palette.quiet)
                    Capsule()
                        .fill(LinearGradient(colors: [WxPalette.tone(day.low ?? 0), WxPalette.tone(day.high ?? 0)], startPoint: .leading, endPoint: .trailing))
                        .frame(width: max(width * (to - from), width * 0.04))
                        .scaleEffect(x: grown || reduceMotion ? 1 : 0, y: 1, anchor: .leading)
                        .offset(x: width * from)
                    if let now {
                        Circle().fill(Palette.text).frame(width: 10, height: 10)
                            .overlay { Circle().strokeBorder(Palette.surface, lineWidth: 2).frame(width: 14, height: 14) }
                            .offset(x: width * now - 5)
                    }
                }
                .frame(height: 6).frame(maxHeight: .infinity)
            }
            .frame(height: 14)
            Text("\(day.high ?? 0)°").font(.geist(16, .bold)).frame(width: 30, alignment: .leading)
        }
        .foregroundStyle(Palette.text)
        .padding(.horizontal, 16).frame(minHeight: 52)
        .modifier(WxUp(delay: 0.045 * Double(order), duration: 0.4))
        .onAppear { withAnimation(Motion.ios(0.6).delay(0.12 + 0.045 * Double(order))) { grown = true } }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("\(name), \(day.low ?? 0)° to \(day.high ?? 0)°" + (day.rain.map { $0 >= 30 ? ", \($0)% chance of rain" : "" } ?? ""))
    }
}

/// The canvas's wxup: in from 8 pt below, on its own delay.
private struct WxUp: ViewModifier {
    let delay: Double
    var duration = 0.45
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var shown = false

    func body(content: Content) -> some View {
        content
            .opacity(shown || reduceMotion ? 1 : 0)
            .offset(y: shown || reduceMotion ? 0 : 8)
            .onAppear { withAnimation(Motion.ios(duration).delay(delay)) { shown = true } }
    }
}

private struct WxFade: ViewModifier {
    let delay: Double
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var shown = false

    func body(content: Content) -> some View {
        content
            .opacity(shown || reduceMotion ? 1 : 0)
            .onAppear { withAnimation(.easeOut(duration: 0.3).delay(delay)) { shown = true } }
    }
}

/// A city site, inside the app.
struct SafariView: UIViewControllerRepresentable {
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
            if symbol == "composer" {
                // Composer's own mark: the spark on ink, ringed in its colours.
                Image(.composerSpark).resizable().frame(width: 16, height: 16).foregroundStyle(.white)
                    .frame(width: 36, height: 36)
                    .background(ComposerLook.ink, in: .circle)
                    .overlay { Circle().strokeBorder(ComposerLook.ring(), lineWidth: 2) }
            } else {
                Image(systemName: symbol).font(.system(size: 15, weight: .semibold))
                    .frame(width: 36, height: 36).background(Palette.quiet, in: .circle)
            }
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

struct Tight: LabelStyle {
    func makeBody(configuration: Configuration) -> some View {
        HStack(spacing: 7) { configuration.icon; configuration.title }
    }
}

private struct TrailingIcon: LabelStyle {
    func makeBody(configuration: Configuration) -> some View {
        HStack(spacing: 4) { configuration.title; configuration.icon.font(.system(size: 12, weight: .bold)) }
    }
}

extension View {
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

    /// The canvas's four: sun amber, moon sand, rain blue, cloud grey.
    static func tint(_ icon: String) -> Color {
        switch icon {
        case "clear-day", "partly-cloudy-day": Color(hex: 0xF2A516)
        case "clear-night", "partly-cloudy-night": Color(hex: 0xC9B98A)
        case "rain", "sleet", "hail", "thunderstorm", "snow": Color(hex: 0x3F86D6)
        default: Color(hex: 0x8F97A6)
        }
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
        self.place = place
        do throws(APIFailure) {
            page = try await api.today(latitude: place?.latitude, longitude: place?.longitude)
            if phase != .ready { waveSince = .now }
            phase = .ready
            reportShown(api)
            // No trip: the Leave card's countdown in the island when it's close; a trip ended elsewhere, gone.
            if page?.trip == nil { TripActivity.shared.leave(page?.leave) }
        } catch {
            if page != nil, quietly { return }
            phase = .problem(error == .signedOut ? "You’ve been signed out." : "Couldn’t reach Expadu. Check your connection.")
        }
    }

    /// What just changed, for the note; `undo` puts it back.
    struct Note: Equatable, Identifiable {
        enum Undo: Equatable { case change(String), restore(id: String, date: String), unsave(TodayPage.Idea), signal(TodayPage.Idea), way(String) }

        let id = UUID()
        let text: String
        let symbol: String
        var undo: Undo?
        var problem = false
    }

    private(set) var note: Note?
    /// The Leave card's own note (the canvas's .lv-say): what happened to the trip or its way, with Undo.
    private(set) var cardNote: Note?
    /// Each change to the card's way: its values turn over and it glows (lvflip, lvglow).
    private(set) var cardFlips = 0
    private var cardTimer: Task<Void, Never>?

    private func sayInCard(_ next: Note) {
        withAnimation(Motion.spring) { cardNote = next }
        cardTimer?.cancel()
        cardTimer = Task { [weak self] in
            try? await Task.sleep(for: .seconds(7))
            guard !Task.isCancelled else { return }
            withAnimation(.easeOut(duration: 0.3)) { self?.cardNote = nil }
        }
    }
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

    func editPlan(_ api: AppAPI, plan: TodayPage.Plan, date: String, name: String, start: String, end: String, place: String) async -> Bool {
        let where_ = place.trimmingCharacters(in: .whitespaces)
        await change(api, action: "edit", id: plan.id, date: date, symbol: "pencil", name: name.trimmingCharacters(in: .whitespaces),
                     start: start, end: end, location: where_.isEmpty ? nil : where_)
        return note?.problem != true
    }

    func addPlan(_ api: AppAPI, name: String, date: String, start: String, end: String, place: String) async -> Bool {
        let where_ = place.trimmingCharacters(in: .whitespaces)
        await change(api, action: "appointment", date: date, symbol: "calendar.badge.plus", name: name.trimmingCharacters(in: .whitespaces),
                     start: start, end: end, location: where_.isEmpty ? nil : where_)
        return note?.problem != true
    }

    /// Not interested, from an event's details: off Today too, until Undo.
    func hideIdea(_ id: Int, _ hide: Bool) {
        withAnimation(.snappy) { if hide { hidden.insert(id) } else { hidden.remove(id) } }
    }

    /// Add to my day from What's on: the same add Today's own button sends.
    func isAdded(_ candidate: String) -> Bool { added.contains(candidate) }

    /// A save made in What's on shows on Today's own idea too.
    func markSaved(_ candidate: String, saved: Bool) {
        guard let index = page?.ideas.firstIndex(where: { $0.candidate == candidate }) else { return }
        page?.ideas[index].saved = saved
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

    /// Undo from the card's note: the way the plan had before.
    func undoCard(_ api: AppAPI) async {
        guard case let .way(undo)? = cardNote?.undo else { return }
        withAnimation(.easeOut(duration: 0.2)) { cardNote = nil }
        do throws(APIFailure) {
            try await api.undoWay(undo)
            await load(api, quietly: true)
            cardFlips += 1
            sayInCard(Note(text: "Back to the way you had.", symbol: "arrow.uturn.backward"))
        } catch {
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
        case let .way(undo):
            do throws(APIFailure) {
                try await api.undoWay(undo)
                say(Note(text: "Back to the way you had", symbol: "arrow.uturn.backward"))
                await load(api, quietly: true)
            } catch {
                say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
            }
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

    /// Where the phone was when Today was last read: trips start there, as on Today.
    /// Where the phone was when Today was last read; Getting there plans from here too.
    private(set) var place: CLLocationCoordinate2D?

    /// I'm on my way. True when another trip is running and they should be asked first.
    func startTrip(_ api: AppAPI, way: TodayPage.Way, replace: Bool) async -> Bool {
        do throws(APIFailure) {
            let started = try await api.startTrip(way: way, latitude: place?.latitude, longitude: place?.longitude, replace: replace)
            if started == .running { return true }
            cardNote = nil
            await load(api, quietly: true)
        } catch {
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
            // The way it asked for has gone (that tram left): the card shows the next one at once.
            await load(api, quietly: true)
        }
        return false
    }

    /// I'm on my way on a way Getting there planned: Today shows the trip live after it.
    func startWay(_ api: AppAPI, plan: WayPlan, way: Int, replace: Bool) async -> (running: Bool, problem: String?) {
        do throws(APIFailure) {
            let started = try await api.startWay(plan: plan.plan, way: way, replace: replace)
            if started == .running { return (true, nil) }
            await load(api, quietly: true)
            return (false, nil)
        } catch {
            return (false, Self.words(error))
        }
    }

    /// Off the way or the ride gone: our planner again, from where the phone is to the same place,
    /// and the trip goes on with the first way. Nil when it did, else why not.
    func newWay(_ api: AppAPI, trip: TodayPage.Trip, from: TodayPage.Point) async -> String? {
        guard let end = trip.legs.last?.end else { return "This trip has no place to go to." }
        do throws(APIFailure) {
            let plan = try await api.planWay(to: trip.to, latitude: end.latitude, longitude: end.longitude,
                                             fromLatitude: from.latitude, fromLongitude: from.longitude, startsAt: nil)
            guard let way = plan.ways.first else { return "No way from here right now." }
            _ = try await api.startWay(plan: plan.plan, way: 0, replace: true)
            await load(api, quietly: true)
            cardFlips += 1
            let how = way.lines.isEmpty ? "on foot" : way.legs.filter(\.rides).map(\.name).joined(separator: ", then ")
            sayInCard(Note(text: "New way: leave \(way.leaveTime), \(how), there \(way.arriveTime).", symbol: "arrow.triangle.branch"))
            return nil
        } catch {
            return Self.words(error)
        }
    }

    func endTrip(_ api: AppAPI, arrived: Bool) async {
        do throws(APIFailure) {
            try await api.endTrip()
            TripActivity.shared.end()
            await load(api, quietly: true)
            cardFlips += 1
            if arrived {
                sayInCard(Note(text: "You made it.", symbol: "checkmark"))
            } else {
                sayInCard(Note(text: page?.leave?.leaveTime.map { "Trip ended. Leave at \($0) still stands." } ?? "Trip ended.", symbol: "checkmark"))
            }
        } catch {
            say(Note(text: Self.words(error), symbol: "exclamationmark.circle", problem: true))
        }
    }

    /// Use this: the card shows that way from now on, with Undo. Nil when it went through, else why not.
    func keepWay(_ api: AppAPI, way: TodayPage.Way) async -> String? {
        do throws(APIFailure) {
            let undo = try await api.keepWay(way, latitude: place?.latitude, longitude: place?.longitude)
            await load(api, quietly: true)
            // "tram 1", "tram 1, then bus 146": the rides as the canvas names them.
            let rides = way.legs.filter(\.rides).map { $0.name.prefix(1).lowercased() + $0.name.dropFirst() }
            let how = way.lines.isEmpty ? (way.mode == "bike" ? "by bike" : "on foot") : rides.isEmpty ? way.lines.map { "line \($0.line)" }.joined(separator: ", then ") : rides.joined(separator: ", then ")
            cardFlips += 1
            sayInCard(Note(text: "Changed: leave at \(way.leaveTime), \(how).", symbol: "arrow.triangle.branch", undo: .way(undo)))
            return nil
        } catch {
            await load(api, quietly: true)
            return Self.words(error)
        }
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
