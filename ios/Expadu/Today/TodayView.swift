import API
import Core
import DesignSystem
import SwiftUI

/// Today, as the canvas has it (Today.dc.html): a greeting, the day as a line from now through the
/// plans with the Leave card on the next one, the notes that need you, ideas, and tomorrow.
/// Every part comes from the server; a part with nothing real in it isn't drawn.
struct TodayView: View {
    @Environment(Session.self) private var session
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var model = TodayModel()

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                switch model.phase {
                case .loading:
                    header(nil)
                    ProgressView().frame(maxWidth: .infinity).padding(.top, 60)
                case let .problem(text):
                    header(nil)
                    VStack(spacing: 14) {
                        Text(text).font(.geist(16, .medium)).foregroundStyle(Palette.textSoft).multilineTextAlignment(.center)
                        Button("Try again") { Task { await model.load(session.api) } }
                            .font(.geist(16, .semibold)).foregroundStyle(Palette.text)
                            .padding(.horizontal, 18).frame(height: 44)
                            .background(Palette.quiet, in: .capsule)
                    }
                    .frame(maxWidth: .infinity).padding(.top, 40)
                case let .ready(page):
                    header(page)
                    if !page.needs.isEmpty { NeedsCard(needs: page.needs) }
                    YourDay(page: page)
                    if !page.ideas.isEmpty { Ideas(page: page) }
                    if !page.tomorrow.isEmpty { Tomorrow(plans: page.tomorrow) }
                }
            }
            .padding(.horizontal, 16)
            .padding(.top, 12)
            .padding(.bottom, 120)
            .animation(reduceMotion ? nil : .snappy(duration: 0.35), value: model.phase)
        }
        .scrollIndicators(.hidden)
        .background(Palette.canvas)
        .refreshable { await model.load(session.api) }
        .task { await model.start(session.api) }
    }

    @ViewBuilder
    private func header(_ page: TodayPage?) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(TodayPage.greeting(at: page?.now ?? .now, name: session.name))
                .font(.posterGrotesque(34)).tracking(-0.5)
                .foregroundStyle(Palette.text)
                .accessibilityAddTraits(.isHeader)
            if let page {
                Text(page.dateLine).font(.geist(15, .medium)).foregroundStyle(Palette.textSoft)
            }
        }
        .padding(.horizontal, 4)
        if let page, page.weather != nil || !page.ideas.isEmpty {
            HStack(spacing: 8) {
                if let weather = page.weather {
                    Chip(symbol: Self.symbol(weather.icon), tint: Color(hex: 0xF2A516), title: "\(weather.temperature)°", detail: weather.condition)
                }
                if !page.ideas.isEmpty {
                    Chip(symbol: "calendar", tint: Color(hex: 0x4A2BD0), title: "\(page.ideas.count) ideas", detail: page.ideasTitle.replacingOccurrences(of: "Ideas ", with: "").capitalizedFirst)
                }
            }
        }
    }

    private static func symbol(_ icon: String) -> String {
        switch icon {
        case "sunny", "clear": "sun.max.fill"
        case "partly-cloudy", "partly_cloudy": "cloud.sun.fill"
        case "rain", "drizzle", "showers": "cloud.rain.fill"
        case "snow": "snowflake"
        case "thunder", "storm": "cloud.bolt.rain.fill"
        case "fog": "cloud.fog.fill"
        case "night", "clear-night": "moon.stars.fill"
        default: "cloud.fill"
        }
    }
}

/// A header chip: a tinted icon tile, a number, a few words.
private struct Chip: View {
    let symbol: String
    let tint: Color
    let title: String
    let detail: String

    var body: some View {
        HStack(spacing: 9) {
            Image(systemName: symbol)
                .symbolRenderingMode(.multicolor)
                .font(.system(size: 18, weight: .semibold))
                .foregroundStyle(tint)
                .frame(width: 40, height: 40)
                .background(tint.opacity(0.15), in: .rect(cornerRadius: 14))
            VStack(alignment: .leading, spacing: 1) {
                Text(title).font(.posterGrotesque(16)).foregroundStyle(Palette.text)
                Text(detail).font(.geist(12, .medium)).foregroundStyle(Palette.textSoft).lineLimit(1)
            }
            Spacer(minLength: 0)
        }
        .padding(.leading, 8).padding(.trailing, 10)
        .frame(maxWidth: .infinity).frame(height: 58)
        .background(Palette.surface, in: .rect(cornerRadius: 20))
        .overlay { RoundedRectangle(cornerRadius: 20).strokeBorder(Palette.border) }
        .accessibilityElement(children: .combine)
    }
}

/// The notes that need the person, in place, each in its own tone.
private struct NeedsCard: View {
    let needs: [TodayPage.Need]

    var body: some View {
        VStack(spacing: 0) {
            ForEach(Array(needs.enumerated()), id: \.element.id) { index, need in
                let tone = Self.tone(need.severity)
                HStack(alignment: .top, spacing: 12) {
                    Image(systemName: need.severity == "conflict" ? "exclamationmark.triangle.fill" : "info.circle.fill")
                        .font(.system(size: 15, weight: .semibold)).foregroundStyle(tone)
                        .frame(width: 32, height: 32)
                        .background(tone.opacity(0.14), in: .rect(cornerRadius: 10))
                    VStack(alignment: .leading, spacing: 2) {
                        Text(need.headline).font(.geist(15, .bold)).foregroundStyle(Palette.text)
                        if let detail = need.detail {
                            Text(detail).font(.geist(13.5)).foregroundStyle(Palette.textSoft)
                        }
                    }
                    Spacer(minLength: 0)
                }
                .padding(.vertical, 12).padding(.horizontal, 14)
                .overlay(alignment: .top) {
                    if index > 0 { Rectangle().fill(Palette.border).frame(height: 1) }
                }
            }
        }
        .background(Palette.surface, in: .rect(cornerRadius: 22))
        .overlay { RoundedRectangle(cornerRadius: 22).strokeBorder(Palette.border) }
    }

    static func tone(_ severity: String) -> Color {
        switch severity {
        case "conflict", "critical": Color(hex: 0xD64528)
        case "attention", "warning": Color(hex: 0xC98208)
        default: Color(hex: 0x2F78C8)
        }
    }
}

/// The day as a line from now down through the plans; the next one is big and carries the Leave card.
private struct YourDay: View {
    let page: TodayPage

    private var ahead: [TodayPage.Plan] { page.plans.filter { !$0.isPast } }
    private var earlier: Int { page.plans.count - ahead.count }

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            SectionTitle(text: "Your day", detail: earlier > 0 ? "\(earlier) earlier" : nil)
            if ahead.isEmpty {
                VStack(alignment: .leading, spacing: 4) {
                    Text(page.plans.isEmpty ? "No plans added for today" : "No more plans today").font(.geist(16, .semibold))
                    Text("Your day is open. Ask Composer to plan it.").font(.geist(13.5)).foregroundStyle(Palette.textSoft)
                }
                .foregroundStyle(Palette.text)
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(16)
                .background(Palette.surface, in: .rect(cornerRadius: 22))
                .overlay { RoundedRectangle(cornerRadius: 22).strokeBorder(Palette.border) }
            } else {
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 8) {
                        PulseDot()
                        Text("Now · \(Self.clock(page.now))").font(.geist(13, .bold)).foregroundStyle(Self.line)
                    }
                    .frame(height: 22).padding(.bottom, 6)
                    ForEach(Array(ahead.enumerated()), id: \.element.id) { index, plan in
                        Stop(plan: plan, next: index == 0, leave: index == 0 ? page.leave : nil)
                    }
                }
                .padding(.top, 14).padding(.trailing, 14).padding(.bottom, 6).padding(.leading, 40)
                .background(alignment: .leading) {
                    Rectangle().fill(Self.line).frame(width: 3).clipShape(.capsule)
                        .padding(.top, 26).padding(.bottom, 30).padding(.leading, 21)
                }
                .background(Palette.surface, in: .rect(cornerRadius: 22))
                .overlay { RoundedRectangle(cornerRadius: 22).strokeBorder(Palette.border) }
            }
        }
    }

    static let line = Color(hex: 0xE6421F)

    static func clock(_ date: Date) -> String {
        var style = Date.FormatStyle(date: .omitted, time: .shortened)
        style.timeZone = TimeZone(identifier: "Europe/Berlin")!
        style.locale = Locale(identifier: "de_DE")
        return date.formatted(style)
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

/// A plan on the line. The next one is big, and gets the Leave card when a way is known.
private struct Stop: View {
    let plan: TodayPage.Plan
    let next: Bool
    let leave: TodayPage.Leave?

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(plan.start ?? "Today")
                .font(.posterGrotesque(next ? 46 : 19)).tracking(next ? -1 : 0).monospacedDigit()
            Text(plan.title)
                .font(next ? .posterGrotesque(22) : .geist(16, .semibold))
                .padding(.top, next ? 6 : 2)
            if let place = plan.place ?? plan.subtitle {
                Text([place, plan.end.map { "until \($0)" }].compactMap { $0 }.joined(separator: " · "))
                    .font(.geist(13.5)).foregroundStyle(Palette.textSoft).padding(.top, 2)
            }
            if let leave, leave.isKnown {
                LeaveCard(leave: leave, place: plan.place?.split(separator: ",").first.map(String.init)).padding(.top, 12)
            }
        }
        .foregroundStyle(Palette.text)
        .padding(.top, 6).padding(.bottom, 14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .overlay(alignment: .topLeading) {
            Circle().fill(Palette.surface).frame(width: 11, height: 11)
                .overlay { Circle().strokeBorder(YourDay.line, lineWidth: 3) }
                .offset(x: -24, y: next ? 20 : 12)
        }
        .accessibilityElement(children: .combine)
    }
}

/// When to leave: a ring counting down, the line, when you're there, and the minutes to spare.
private struct LeaveCard: View {
    let leave: TodayPage.Leave
    /// The plan's place, short: "Stadtgarten".
    let place: String?
    private let warn = Color(hex: 0xC98208)

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 12) {
                ZStack {
                    Circle().stroke(Palette.quiet, lineWidth: 4)
                    Circle().trim(from: 0, to: ringFill).stroke(warn, style: StrokeStyle(lineWidth: 4, lineCap: .round)).rotationEffect(.degrees(-90))
                    VStack(spacing: 0) {
                        Text("\(leave.leaveInMinutes ?? 0)").font(.posterGrotesque(15))
                        Text("min").font(.geist(9, .semibold)).foregroundStyle(Palette.textSoft)
                    }
                }
                .frame(width: 46, height: 46)
                VStack(alignment: .leading, spacing: 3) {
                    Text("Leave at \(leave.leaveTime ?? "")").font(.geist(17, .bold))
                    if leave.live {
                        HStack(spacing: 5) {
                            Circle().frame(width: 7, height: 7)
                            Text("Live").font(.geist(12.5, .bold))
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
                if let spare = leave.spareMinutes {
                    tile(label: "Spare", badge: nil, color: .clear, value: "\(spare) min", note: leave.start.map { "before \($0)" } ?? "")
                }
            }
        }
        .padding(14)
        .background(Palette.surface, in: .rect(cornerRadius: 20))
        .overlay { RoundedRectangle(cornerRadius: 20).strokeBorder(warn.opacity(0.55), lineWidth: 1.5) }
    }

    /// Fuller as the time to leave comes closer, over the last quarter hour.
    private var ringFill: Double {
        max(0.04, 1 - Double(min(leave.leaveInMinutes ?? 15, 15)) / 15)
    }

    private func tile(label: String?, badge: String?, color: Color, value: String, note: String) -> some View {
        VStack(alignment: .leading, spacing: 1) {
            if let badge {
                Label(badge, systemImage: "tram.fill").labelStyle(.titleAndIcon)
                    .font(.geist(12, .bold)).foregroundStyle(color.onColor)
                    .padding(.horizontal, 6).frame(height: 20)
                    .background(color, in: .rect(cornerRadius: 5))
            } else if let label {
                Text(label.uppercased()).font(.geist(10.5, .bold)).tracking(0.4).foregroundStyle(Palette.textSoft)
            }
            Text(value).font(.posterGrotesque(19)).monospacedDigit()
            Text(note).font(.geist(12)).foregroundStyle(Palette.textSoft).lineLimit(1).minimumScaleFactor(0.85)
        }
        .padding(.vertical, 9).padding(.horizontal, 10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Palette.quiet, in: .rect(cornerRadius: 12))
    }
}

/// Ideas for the time they have: events now, each a small tilted poster thumb and a line.
private struct Ideas: View {
    let page: TodayPage

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            SectionTitle(text: page.ideasTitle, detail: nil)
            VStack(spacing: 0) {
                ForEach(Array(page.ideas.prefix(4).enumerated()), id: \.element.id) { index, idea in
                    HStack(spacing: 12) {
                        Text(idea.emoji ?? "✦")
                            .font(.system(size: 22))
                            .frame(width: 46, height: 56)
                            .background(index.isMultiple(of: 2) ? Palette.posterYellow : Palette.posterPurple.opacity(0.85), in: .rect(cornerRadius: 7))
                            .rotationEffect(.degrees(index.isMultiple(of: 2) ? -2 : 1.5))
                        VStack(alignment: .leading, spacing: 3) {
                            Text(idea.title).font(.geist(15.5, .semibold)).foregroundStyle(Palette.text).lineLimit(2)
                            Text(idea.meta).font(.geist(13)).foregroundStyle(Palette.textSoft).lineLimit(1)
                            if let price = idea.price {
                                Text(price).font(.geist(12, .semibold)).foregroundStyle(Palette.textSoft)
                            }
                        }
                        Spacer(minLength: 0)
                    }
                    .padding(.vertical, 10).padding(.horizontal, 14)
                    .overlay(alignment: .top) {
                        if index > 0 { Rectangle().fill(Palette.border).frame(height: 1) }
                    }
                    .accessibilityElement(children: .combine)
                }
            }
            .background(Palette.surface, in: .rect(cornerRadius: 22))
            .overlay { RoundedRectangle(cornerRadius: 22).strokeBorder(Palette.border) }
        }
    }
}

private struct Tomorrow: View {
    let plans: [TodayPage.Later]

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            SectionTitle(text: "Tomorrow", detail: nil)
            VStack(spacing: 0) {
                ForEach(Array(plans.enumerated()), id: \.offset) { index, plan in
                    HStack(spacing: 12) {
                        Text(plan.start ?? "").font(.posterGrotesque(17)).monospacedDigit().frame(width: 52, alignment: .leading)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(plan.title).font(.geist(15.5, .semibold))
                            if let place = plan.place { Text(place).font(.geist(13)).foregroundStyle(Palette.textSoft) }
                        }
                        Spacer(minLength: 0)
                    }
                    .foregroundStyle(Palette.text)
                    .padding(.vertical, 12).padding(.horizontal, 14)
                    .overlay(alignment: .top) {
                        if index > 0 { Rectangle().fill(Palette.border).frame(height: 1) }
                    }
                }
            }
            .background(Palette.surface, in: .rect(cornerRadius: 22))
            .overlay { RoundedRectangle(cornerRadius: 22).strokeBorder(Palette.border) }
        }
    }
}

private struct SectionTitle: View {
    let text: String
    let detail: String?

    var body: some View {
        HStack(alignment: .firstTextBaseline, spacing: 8) {
            Text(text).font(.posterGrotesque(23)).tracking(-0.3).foregroundStyle(Palette.text)
                .accessibilityAddTraits(.isHeader)
            if let detail { Text(detail).font(.geist(13.5, .medium)).foregroundStyle(Palette.textSoft) }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 4).padding(.top, 8)
    }
}

@Observable
@MainActor
final class TodayModel {
    enum Phase: Equatable { case loading, ready(TodayPage), problem(String) }

    private(set) var phase = Phase.loading
    private let locator = OneLocation()
    private var refreshing: Task<Void, Never>?

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
            phase = .ready(try await api.today(latitude: place?.latitude, longitude: place?.longitude))
        } catch {
            if case .ready = phase, quietly { return }
            phase = .problem(error == .signedOut ? "You’ve been signed out." : "Couldn’t reach Expadu. Check your connection.")
        }
    }
}

extension String {
    var capitalizedFirst: String { prefix(1).uppercased() + dropFirst() }
}
