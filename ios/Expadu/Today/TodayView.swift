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
                            .background(Palette.quiet, in: .capsule).raised(Capsule())
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
            case let .line(need, page): LineTrouble(need: need, page: page)
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
struct PullMark: View {
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
    case line(TodayPage.Need, TodayPage)

    var id: String {
        switch self {
        case .weather: "weather"
        case .whatsOn: "whats-on"
        case let .link(url): url.absoluteString
        case let .addPlan(date): "add-\(date)"
        case let .why(idea, _): "why-\(idea.id)"
        case .howWeRank: "how-we-rank"
        case .agree: "agree"
        case let .line(need, _): "line-\(need.id)"
        }
    }
}

/// What just changed, in words, with Undo when it can be put back (the canvas's note, never alone:
/// the change itself moves in place too).
struct NoteBar: View {
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

struct Greeting: View {
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
struct Chips: View {
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
