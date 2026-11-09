import API
import Core
import DesignSystem
import SwiftUI

/// The bell, as the canvas draws it: the complete record. What Today shows sits under On Today; the
/// rest stays here, under Not on Today. Chips narrow it to lines, weather, Paperwork or the city.
struct AlertsSheet: View {
    let page: TodayPage?
    @Environment(Session.self) private var session
    @Environment(\.dismiss) private var dismiss
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var alerts: [BellCard]?
    @State private var problem: String?
    @State private var filter = "all"
    @State private var open: Int?
    @State private var path: [String] = []
    @State private var link: WebPage?

    private static let chips = [("all", "All"), ("lines", "Your lines"), ("weather", "Weather"), ("paper", "Paperwork"), ("city", "City")]

    private var needs: [TodayPage.Need] { (page?.needs ?? []).filter { filter == "all" || Self.category($0) == filter } }
    /// The cards Today's notes already stand for aren't listed twice.
    private var rest: [BellCard] {
        let shown = Set((page?.needs ?? []).compactMap(\.alert))
        return (alerts ?? []).filter { !shown.contains($0.id) && (filter == "all" || $0.category == filter) }
    }

    var body: some View {
        NavigationStack(path: $path) {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    HStack {
                        Text("Alerts").font(.posterGrotesque(26)).foregroundStyle(Palette.text)
                        Spacer()
                        CloseButton { dismiss() }
                    }
                    ScrollView(.horizontal) {
                        HStack(spacing: 8) {
                            ForEach(Self.chips, id: \.0) { id, title in
                                Button { withAnimation(reduceMotion ? nil : .snappy) { filter = id } } label: {
                                    Text(title).font(.geist(14, .semibold))
                                        .foregroundStyle(filter == id ? Palette.canvas : Palette.text)
                                        .padding(.horizontal, 14).frame(height: 36)
                                        .background(filter == id ? Palette.text : Palette.quiet, in: .capsule)
                                }
                                .buttonStyle(PressStyle())
                                .accessibilityAddTraits(filter == id ? .isSelected : [])
                            }
                        }
                        .padding(.horizontal, 16)
                    }
                    .scrollIndicators(.hidden)
                    .padding(.horizontal, -16)

                    if !needs.isEmpty {
                        group("On Today") {
                            ForEach(Array(needs.enumerated()), id: \.element.id) { index, need in
                                if index > 0 { Divider().overlay(Palette.border) }
                                Button {
                                    if need.isLine { path.append(need.id) }
                                } label: {
                                    AlertRow(symbol: Self.symbol(Self.category(need)), tone: NeedTone.color(need.severity), head: Self.plain(need.headline),
                                             sub: need.detail.map(Self.plain), time: nil, more: need.isLine)
                                }
                                .buttonStyle(PressStyle())
                                .disabled(!need.isLine)
                            }
                        }
                    }
                    if !rest.isEmpty {
                        group("Not on Today") {
                            ForEach(Array(rest.enumerated()), id: \.element.id) { index, alert in
                                if index > 0 { Divider().overlay(Palette.border) }
                                Button { toggle(alert) } label: {
                                    VStack(alignment: .leading, spacing: 0) {
                                        AlertRow(symbol: Self.symbol(alert.category), tone: Self.tone(alert.severity), head: alert.title,
                                                 sub: alert.source, time: alert.when(now: page?.now ?? .now), more: false, unread: !alert.read)
                                        if open == alert.id { AlertBody(alert: alert) { link = WebPage(url: $0) }.padding(.leading, 60).padding(.trailing, 14).padding(.bottom, 12) }
                                    }
                                }
                                .buttonStyle(PressStyle())
                            }
                        }
                    }
                    if alerts == nil, problem == nil {
                        ProgressView().frame(maxWidth: .infinity).padding(.vertical, 20)
                    } else if let problem {
                        Text(problem).font(.geist(14)).foregroundStyle(Palette.textSoft).padding(.horizontal, 4)
                    } else if needs.isEmpty, rest.isEmpty {
                        Text(filter == "all" ? "Nothing needs you right now." : "Nothing here right now.")
                            .font(.geist(15)).foregroundStyle(Palette.textSoft).padding(.horizontal, 4).padding(.top, 6)
                    }
                    Text("Today shows only what touches your plans. Everything else stays here.")
                        .font(.geist(12)).foregroundStyle(Palette.textSoft).padding(.horizontal, 4).padding(.top, 6)
                }
                .padding(.horizontal, 16).padding(.top, 18).padding(.bottom, 40)
                .animation(reduceMotion ? nil : .snappy, value: filter)
            }
            .scrollIndicators(.hidden)
            .background(Palette.canvas)
            .toolbarVisibility(.hidden, for: .navigationBar)
            .navigationDestination(for: String.self) { id in
                if let page, let need = page.needs.first(where: { $0.id == id }) {
                    LineTrouble(need: need, page: page, alerts: alerts ?? [], pushed: true)
                }
            }
        }
        .task { await load() }
        .sheet(item: $link) { SafariView(url: $0.url).ignoresSafeArea() }
        .flexibleSheet()
        .presentationBackground(Palette.canvas)
    }

    private func group(_ title: String, @ViewBuilder rows: () -> some View) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(title).font(.geist(13, .bold)).foregroundStyle(Palette.textSoft).padding(.horizontal, 4).padding(.top, 4)
            VStack(spacing: 0) { rows() }
                .clipShape(.rect(cornerRadius: 22))
                .card(radius: 22)
        }
        .transition(.opacity)
    }

    private func load() async {
        do {
            let loaded = try await session.api.alerts()
            withAnimation(reduceMotion ? nil : .snappy) { alerts = loaded }
        } catch {
            problem = "Couldn’t check the rest just now. Pull Today down to try again."
        }
    }

    /// Opens under the row; opened counts as read.
    private func toggle(_ alert: BellCard) {
        withAnimation(reduceMotion ? nil : Motion.ios(0.35)) { open = open == alert.id ? nil : alert.id }
        guard !alert.read, let index = alerts?.firstIndex(where: { $0.id == alert.id }) else { return }
        alerts?[index].read = true
        Task { try? await session.api.readAlert(alert.id) }
    }

    static func category(_ need: TodayPage.Need) -> String {
        switch need.kind {
        case "disruption", "delay", "trip_change", "leave_now": "lines"
        case "weather", "rain_outdoor", "plan_hazard": "weather"
        case "paperwork": "paper"
        default: "city"
        }
    }

    static func symbol(_ category: String) -> String {
        switch category {
        case "lines": "tram.fill"
        case "weather": "cloud.bolt.rain.fill"
        case "paper": "doc.text.fill"
        default: "building.2.fill"
        }
    }

    static func tone(_ severity: String) -> Color {
        switch severity {
        case "danger": NeedTone.color("conflict")
        case "warn": NeedTone.color("attention")
        case "success": Motion.green
        default: NeedTone.color("info")
        }
    }

    /// The canvas drops a note's full stop in the list.
    static func plain(_ text: String) -> String { text.hasSuffix(".") ? String(text.dropLast()) : text }
}

/// A row in the bell's list (.al-row): the kind in its tone, what it says, and when.
private struct AlertRow: View {
    let symbol: String
    let tone: Color
    let head: String
    let sub: String?
    let time: String?
    let more: Bool
    var unread = false

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            Image(systemName: symbol).font(.system(size: 14, weight: .semibold)).foregroundStyle(tone)
                .frame(width: 34, height: 34)
                .background(tone.opacity(0.16), in: .rect(cornerRadius: 10))
            VStack(alignment: .leading, spacing: 2) {
                Text(head).font(.geist(15.5, unread ? .bold : .semibold)).foregroundStyle(Palette.text)
                    .fixedSize(horizontal: false, vertical: true)
                if let sub { Text(sub).font(.geist(13)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true) }
            }
            Spacer(minLength: 0)
            if let time, !time.isEmpty { Text(time).font(.geist(12.5)).foregroundStyle(Palette.textSoft) }
            if more { Image(systemName: "chevron.right").font(.system(size: 12, weight: .semibold)).foregroundStyle(Palette.textSoft).padding(.top, 10) }
        }
        .padding(.horizontal, 14).padding(.vertical, 12)
        .multilineTextAlignment(.leading)
        .contentShape(.rect)
        .accessibilityElement(children: .combine)
    }
}

/// What the card says in full, and the source's own page when it has one.
private struct AlertBody: View {
    let alert: BellCard
    let show: (URL) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            if let body = alert.body, !body.isEmpty {
                Text(body).font(.geist(14)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
            }
            if let url = alert.url {
                Button { show(url) } label: {
                    Label("\(alert.source == "KVB live" ? "KVB’s" : "The") page", systemImage: "arrow.up.right").labelStyle(TrailingIcon())
                        .font(.geist(14, .semibold)).foregroundStyle(TodayLink.ink).frame(minHeight: 36)
                }
                .buttonStyle(PressStyle())
            }
        }
        .multilineTextAlignment(.leading)
        .transition(.opacity.combined(with: .offset(y: -6)))
    }
}

/// A line's number on its KVB colour.
struct LineBadge: View {
    let line: String
    let color: String?
    var height: CGFloat = 28

    var body: some View {
        let look = LineLook(hex: color ?? "#1A4CD4")
        Text(Board.badge(line)).font(.geist(height * 0.55, .bold)).foregroundStyle(look.ink)
            .lineLimit(1).minimumScaleFactor(0.6)
            .padding(.horizontal, 7).frame(minWidth: height + 6).frame(height: height)
            .background(look.fill, in: .rect(cornerRadius: 7))
    }
}

/// Cologne today's line row (.ct-line): one of their lines in trouble, one tap from what it means.
struct LineRow: View {
    let need: TodayPage.Need

    var body: some View {
        HStack(spacing: 12) {
            LineBadge(line: need.line ?? "", color: need.color, height: 28)
            VStack(alignment: .leading, spacing: 1) {
                Text(LineTrouble.words(need)).font(.geist(15, .semibold)).foregroundStyle(Palette.text).lineLimit(2)
                if let detail = need.detail { Text(AlertsSheet.plain(detail)).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1) }
            }
            Spacer(minLength: 0)
            Image(systemName: "chevron.right").font(.system(size: 12, weight: .semibold)).foregroundStyle(Palette.textSoft)
        }
        .padding(.horizontal, 14).padding(.vertical, 12)
        .contentShape(.rect)
        .accessibilityElement(children: .combine)
        .accessibilityHint("What it means for you")
    }
}

/// A line late or disrupted, in place (the canvas's line sheet): whether today's ways use it, what
/// KVB says, and where. Only what's known is drawn.
struct LineTrouble: View {
    let need: TodayPage.Need
    let page: TodayPage
    var alerts: [BellCard] = []
    var pushed = false
    @Environment(Session.self) private var session
    @Environment(\.dismiss) private var dismiss
    @State private var card: BellCard?
    @State private var link: WebPage?

    private var line: String { need.line ?? "" }

    /// "Line 12 running 6 min late at Ebertplatz." reads "Running 6 min late at Ebertplatz" beside the badge.
    static func words(_ need: TodayPage.Need) -> String {
        var text = AlertsSheet.plain(need.headline)
        if let line = need.line {
            for lead in ["Line \(line): ", "Line \(line) "] where text.hasPrefix(lead) {
                text = String(text.dropFirst(lead.count))
                text = text.prefix(1).uppercased() + text.dropFirst()
            }
        }
        return text
    }

    /// The lines today's ways still ride; a ride already over doesn't count.
    private var todaysLines: [String] {
        var lines: [String] = []
        for leg in page.trip?.legs ?? [] where leg.arriveAt > page.now { if let line = leg.line { lines.append(line) } }
        if let leave = page.leave {
            if let line = leave.line { lines.append(line) }
            for way in leave.ways where way.inPlan { lines += way.lines.map(\.line) }
        }
        return lines.reduce(into: []) { if !$0.contains($1) { $0.append($1) } }
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                HStack(alignment: .center, spacing: 12) {
                    if pushed {
                        Button { dismiss() } label: {
                            Image(systemName: "chevron.left").font(.system(size: 16, weight: .semibold)).foregroundStyle(Palette.text)
                                .frame(width: 44, height: 44).glassEffect(.regular.interactive(), in: .circle)
                        }
                        .buttonStyle(PressStyle())
                        .accessibilityLabel("Back to Alerts")
                    }
                    LineBadge(line: line, color: need.color, height: 30)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(Self.words(need)).font(.posterGrotesque(24)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                        if let since = card?.when(now: page.now), !since.isEmpty {
                            Text("\(card?.source ?? "KVB") · since \(since)").font(.geist(13)).foregroundStyle(Palette.textSoft)
                        }
                    }
                    Spacer(minLength: 0)
                    if !pushed { CloseButton { dismiss() } }
                }

                yours.padding(.top, 16)

                if let body = card?.body, !body.isEmpty {
                    Text("What KVB says").font(.posterGrotesque(20)).foregroundStyle(Palette.text).padding(.top, 26).padding(.bottom, 10).padding(.leading, 4)
                    Text(body).font(.geist(15)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                        .padding(14).frame(maxWidth: .infinity, alignment: .leading)
                        .card(radius: 22)
                }

                if let stops = card?.stops, !stops.isEmpty {
                    Text("Where").font(.posterGrotesque(20)).foregroundStyle(Palette.text).padding(.top, 26).padding(.bottom, 10).padding(.leading, 4)
                    StopStrip(stops: stops, color: LineLook(hex: need.color ?? "#1A4CD4").deep).card(radius: 22)
                }

                if let url = card?.url {
                    Button { link = WebPage(url: url) } label: {
                        Label("KVB’s page", systemImage: "arrow.up.right").labelStyle(TrailingIcon())
                            .font(.geist(15, .semibold)).foregroundStyle(TodayLink.ink).frame(maxWidth: .infinity).frame(height: 48)
                    }
                    .buttonStyle(PressStyle())
                    .padding(.top, 14)
                }

                Text("From KVB.").font(.geist(12)).foregroundStyle(Palette.textSoft).padding(.top, 14).padding(.horizontal, 4)
            }
            .padding(.horizontal, 16).padding(.top, 20).padding(.bottom, 40)
        }
        .scrollIndicators(.hidden)
        .background(Palette.canvas)
        .toolbarVisibility(.hidden, for: .navigationBar)
        .task { await find() }
        .sheet(item: $link) { SafariView(url: $0.url).ignoresSafeArea() }
        .flexibleSheet()
        .presentationBackground(Palette.canvas)
    }

    /// Whether it touches them today, from their own ways.
    private var yours: some View {
        let lines = todaysLines
        let onIt = lines.contains(line)
        let others = lines.filter { $0 != line }
        let tone = onIt ? NeedTone.color("attention") : Motion.green
        return HStack(spacing: 13) {
            Image(systemName: onIt ? "exclamationmark" : "checkmark").font(.system(size: 14, weight: .bold)).foregroundStyle(.white)
                .frame(width: 32, height: 32).background(tone, in: .rect(cornerRadius: 9))
            VStack(alignment: .leading, spacing: 1) {
                Text(onIt ? "On your way today" : "Not on your way today").font(.geist(16, .semibold)).foregroundStyle(Palette.text)
                Text(onIt ? "One of today’s ways rides line \(line)."
                     : others.isEmpty ? "None of today’s ways ride it." : "Today’s ways ride \(others.count == 1 ? "line" : "lines") \(others.joined(separator: ", ")).")
                    .font(.geist(13.5)).foregroundStyle(Palette.textSoft)
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 14).padding(.vertical, 12)
        .card(radius: 22)
        .accessibilityElement(children: .combine)
    }

    /// The bell's card behind the note: KVB's words, the stops and its page. Opened counts as read.
    private func find() async {
        guard let id = need.alert else { return }
        var list = alerts
        if list.isEmpty { list = (try? await session.api.alerts()) ?? [] }
        guard let found = list.first(where: { $0.id == id }) else { return }
        withAnimation(.snappy) { card = found }
        if !found.read { try? await session.api.readAlert(id) }
    }
}

/// The stops where it's happening, on the line's colour (.ln-strip).
private struct StopStrip: View {
    let stops: [String]
    let color: Color

    var body: some View {
        VStack(spacing: 0) {
            ForEach(Array(stops.enumerated()), id: \.offset) { index, stop in
                HStack(spacing: 12) {
                    Circle().strokeBorder(color, lineWidth: 3).background(Circle().fill(Palette.surface)).frame(width: 14, height: 14)
                    Text(stop).font(.geist(15, index == 0 || index == stops.count - 1 ? .bold : .regular)).foregroundStyle(Palette.text)
                    Spacer(minLength: 0)
                }
                .frame(minHeight: 40)
                .padding(.leading, 18).padding(.trailing, 16)
                .background(alignment: .leading) {
                    Rectangle().fill(color).frame(width: 4)
                        .padding(.top, index == 0 ? 20 : 0).padding(.bottom, index == stops.count - 1 ? 20 : 0)
                        .padding(.leading, 23)
                }
            }
        }
        .padding(.vertical, 6)
    }
}

/// The round close button the canvas's sheets carry (.xb).
struct CloseButton: View {
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: "xmark").font(.system(size: 16, weight: .semibold)).foregroundStyle(Palette.text)
                .frame(width: 44, height: 44)
                .glassEffect(.regular.interactive(), in: .circle)
        }
        .buttonStyle(PressStyle())
        .accessibilityLabel("Close")
    }
}

/// A page of the source's own, opened in place.
struct WebPage: Identifiable {
    let url: URL
    var id: String { url.absoluteString }
}
