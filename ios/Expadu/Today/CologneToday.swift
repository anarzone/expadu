import API
import Core
import CoreLocation
import Maps
import DesignSystem
import SafariServices
import SwiftUI

// MARK: - Cologne today

/// The weather for the hours ahead, and the departures nearest you when you want them.
struct CologneToday: View {
    let page: TodayPage
    @Binding var sheet: TodaySheet?
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.shell) private var shell
    /// nil until they choose: open on a day with no plans left (the canvas's free day), folded otherwise.
    @State private var chosen: Bool?
    private var open: Bool { chosen ?? page.ahead.isEmpty }
    @State private var lineOpen = false
    /// The departure opened to its next times.
    @State private var departure: String?
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            SectionHead(title: "Cologne today") { EmptyView() }
            VStack(spacing: 0) {
                if let weather = page.weather {
                    Button { sheet = .weather(page) } label: { WeatherRow(weather: weather) }
                        .buttonStyle(PressStyle())
                    Divider().overlay(Palette.border)
                }
                // One of their lines late or disrupted (.ct-line), one tap from what it means for them.
                if let need = page.needs.first(where: \.isLine) {
                    Button {
                        withAnimation(reduceMotion ? nil : .easeInOut(duration: 0.3)) { lineOpen.toggle() }
                    } label: { LineRow(need: need, open: lineOpen) }
                        .buttonStyle(PressStyle())
                        .transition(.opacity.combined(with: .move(edge: .top)))
                    if lineOpen { LineTroubleDetail(need: need, page: page) }
                    Divider().overlay(Palette.border)
                }
                Button {
                    let next = !open
                    withAnimation(.snappy) { chosen = next }
                    if next { Task { await model.loadBoard(session.api) } }
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
                            // Only what they can still reach on foot: a train leaving now 14 min away is no use.
                            let reachable = board.departures.filter { row in
                                let walk = board.stops.first { $0.name == row.stop }?.walkMinutes ?? 0
                                return row.minutes.contains { $0 >= walk }
                            }
                            ForEach(Array(reachable.prefix(5).enumerated()), id: \.element.id) { index, row in
                                if index > 0 { Rectangle().fill(Palette.border).frame(height: 0.5).padding(.leading, 62) }
                                Button {
                                    withAnimation(reduceMotion ? nil : .easeInOut(duration: 0.25)) { departure = departure == row.id ? nil : row.id }
                                } label: {
                                    DepartureLine(departure: row, walk: board.stops.first { $0.name == row.stop }?.walkMinutes,
                                                  open: departure == row.id)
                                }
                                .buttonStyle(PressStyle())
                            }
                            if reachable.isEmpty {
                                Text("Nothing leaves nearby in the next hour.").font(.geist(14)).foregroundStyle(Palette.textSoft).padding(14)
                            }
                        } else {
                            ProgressView().padding(20)
                        }
                        Rectangle().fill(Palette.border).frame(height: 0.5)
                        Button { shell.show(.travel) } label: {
                            Label("All departures in Travel", systemImage: "chevron.right").labelStyle(TrailingIcon())
                                .font(.geist(14.5, .semibold)).foregroundStyle(TodayLink.ink)
                                .frame(maxWidth: .infinity).frame(height: 46)
                        }
                        .buttonStyle(PressStyle())
                    }
                    .transition(.opacity.combined(with: .move(edge: .top)))
                }
            }
            .clipShape(.rect(cornerRadius: 22))
            .card(radius: 22)
        }
        .task(id: open) { if open, model.board == nil { await model.loadBoard(session.api) } }
    }
}

struct WeatherRow: View {
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

struct PaperworkCard: View {
    let paperwork: TodayPage.Paperwork?
    var status = "none"
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
                        Image(systemName: "doc.text.fill").font(.system(size: 20)).frame(width: 58, height: 58).background(Palette.quiet, in: .rect(cornerRadius: 16, style: .continuous))
                    }
                    VStack(alignment: .leading, spacing: 3) {
                        if let paperwork {
                            if let process = paperwork.processTitle {
                                Text(process.uppercased()).font(.geist(12.5, .semibold)).tracking(0.4).foregroundStyle(Palette.textSoft)
                            }
                            Text("Next: \(paperwork.title.prefix(1).lowercased() + paperwork.title.dropFirst())").font(.geist(16, .semibold)).multilineTextAlignment(.leading)
                            Text("Your situation · Review details").font(.geist(13)).foregroundStyle(Palette.textSoft)
                        } else {
                            // What the record really says: "Get started" only with no record at all.
                            let words: (String, String, String) = switch status {
                            case "clear": ("YOUR PAPERWORK", "Nothing to do right now", "Your tasks and documents are in Paperwork.")
                            case "paused": ("YOUR PAPERWORK", "Paperwork is paused", "You stopped it using your record. Open Paperwork to turn it back on.")
                            case "unconfirmed": ("YOUR PAPERWORK", "Confirm your email first", "Paperwork opens your record once your address is confirmed.")
                            default: ("SETTLING IN?", "Get started when you’re ready", "A few questions, then your steps for Cologne.")
                            }
                            Text(words.0).font(.geist(12.5, .semibold)).tracking(0.4).foregroundStyle(Palette.textSoft)
                            Text(words.1).font(.geist(16, .semibold))
                            Text(words.2).font(.geist(13)).foregroundStyle(Palette.textSoft)
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

struct PickUp: View {
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

struct SavedForLater: View {
    let saved: TodayPage.Saved
    let date: String
    var plans: [TodayPage.Plan] = []
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            SectionHead(title: "Saved for later") {
                // There's no list of them all yet: only say how many when more are saved than shown.
                if saved.count > saved.items.count {
                    Text("\(saved.count) saved").font(.geist(13.5, .medium)).foregroundStyle(Palette.textSoft)
                }
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

struct ComingUp: View {
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

struct CityLinks: View {
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
                                .buttonFace(Palette.quiet)
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

/// A departure on Today, as a quiet row: the line on its colour, where it goes and from which stop,
/// the minutes, and when to leave to catch it (the stop's walk). A tap shows the next times.
struct DepartureLine: View {
    let departure: Board.Departure
    let walk: Int?
    var open = false

    var body: some View {
        let look = LineLook(hex: departure.color)
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 12) {
                Text(departure.badge).font(.geist(departure.badge.count > 2 ? 13 : 16, .bold)).foregroundStyle(look.ink)
                    .lineLimit(1).minimumScaleFactor(0.6)
                    .frame(width: 36, height: 30)
                    .background(look.fill, in: .rect(cornerRadius: 8))
                VStack(alignment: .leading, spacing: 1) {
                    Text(departure.direction).font(.geist(15.5, .semibold)).foregroundStyle(Palette.text).lineLimit(1)
                    Text(catchWords).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1)
                }
                Spacer(minLength: 6)
                VStack(alignment: .trailing, spacing: 0) {
                    Text(Self.when(departure.minutes.first)).font(.posterGrotesque(20)).foregroundStyle(departure.cancelled ? Palette.textSoft : Palette.text)
                        .strikethrough(departure.cancelled).monospacedDigit()
                    if late {
                        Text(departure.cancelled ? "cancelled" : "+\(departure.delay) late").font(.geist(11.5, .semibold)).foregroundStyle(Palette.conflictText)
                    }
                }
            }
            if open, departure.minutes.count > 1 {
                Text("Then in \(Self.times(Array(departure.minutes.dropFirst())))\(departure.disrupted ? " · disruption on this line" : "")")
                    .font(.geist(13)).foregroundStyle(Palette.textSoft).padding(.leading, 48)
                    .transition(.opacity)
            }
        }
        .padding(.horizontal, 14).padding(.vertical, 10)
        .contentShape(.rect)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Line \(departure.badge) to \(departure.direction), \(Self.when(departure.minutes.first)). \(catchWords)")
    }

    private var late: Bool { departure.cancelled || departure.delay > 0 }

    /// "Neumarkt · leave in 2 min", "Rudolfplatz · catch the one in 6 min" (a stop too far for this one).
    private var catchWords: String {
        guard let walk, walk > 0, !departure.cancelled else { return departure.stop }
        if let next = departure.minutes.first, next >= walk {
            return "\(departure.stop) · " + (next - walk == 0 ? "leave now" : "leave in \(next - walk) min")
        }
        if let later = departure.minutes.first(where: { $0 >= walk }) { return "\(departure.stop) · catch the one in \(later) min" }
        return "\(departure.stop) · \(walk) min walk"
    }

    static func when(_ minutes: Int?) -> String {
        guard let minutes else { return "" }
        return minutes == 0 ? "now" : "\(minutes) min"
    }

    /// "1, 11, 21 min".
    static func times(_ minutes: [Int]) -> String {
        minutes.isEmpty ? "" : minutes.prefix(3).map { "\($0)" }.joined(separator: ", ") + " min"
    }
}
