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
    /// The line note opened in place.
    @State private var openNeed: String?
    @State private var link: WebPage?

    private static let chips = [("all", "All"), ("lines", "Your lines"), ("weather", "Weather"), ("paper", "Paperwork"), ("city", "City")]

    private var needs: [TodayPage.Need] { (page?.needs ?? []).filter { filter == "all" || Self.category($0) == filter } }
    /// The cards Today's notes already stand for aren't listed twice.
    private var rest: [BellCard] {
        let shown = Set((page?.needs ?? []).compactMap(\.alert))
        return (alerts ?? []).filter { !shown.contains($0.id) && (filter == "all" || $0.category == filter) }
    }

    var body: some View {
        NavigationStack {
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
                                        .background(filter == id ? Palette.text : Palette.quiet, in: .rect(cornerRadius: 10, style: .continuous))
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
                                    withAnimation(reduceMotion ? nil : .easeInOut(duration: 0.3)) { openNeed = openNeed == need.id ? nil : need.id }
                                } label: {
                                    AlertRow(symbol: Self.symbol(Self.category(need)), tone: NeedTone.color(need.severity), head: Self.plain(need.headline),
                                             sub: need.detail.map(Self.plain), time: nil, more: need.isLine)
                                }
                                .buttonStyle(PressStyle())
                                .disabled(!need.isLine)
                                if need.isLine, openNeed == need.id, let page {
                                    LineTroubleDetail(need: need, page: page)
                                }
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
            if more { Image(systemName: "chevron.down").font(.system(size: 12, weight: .semibold)).foregroundStyle(Palette.textSoft).padding(.top, 10) }
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

/// Cologne today's line row (.ct-line): one of their lines in trouble. A tap opens what it means
/// right under it; nothing slides over the page.
struct LineRow: View {
    let need: TodayPage.Need
    var open = false

    var body: some View {
        HStack(alignment: open ? .top : .center, spacing: 12) {
            LineBadge(line: need.line ?? "", color: need.color, height: 28)
            VStack(alignment: .leading, spacing: 1) {
                Text(LineTroubleDetail.words(need)).font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                    .lineLimit(open ? nil : 2).fixedSize(horizontal: false, vertical: open)
                if let detail = need.detail { Text(AlertsSheet.plain(detail)).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1) }
            }
            Spacer(minLength: 0)
            Image(systemName: "chevron.down").font(.system(size: 12, weight: .semibold)).foregroundStyle(Palette.textSoft)
                .rotationEffect(.degrees(open ? 180 : 0))
                .padding(.top, open ? 6 : 0)
        }
        .padding(.horizontal, 14).padding(.vertical, 12)
        .multilineTextAlignment(.leading)
        .contentShape(.rect)
        .accessibilityElement(children: .combine)
        .accessibilityHint(open ? "Hides what it means for you" : "What it means for you")
    }
}

/// What a line in trouble means for them, in place under its row: whether today's ways ride it,
/// what KVB says and where (when the bell has its card), and the line's next departures near them.
/// Only what's known is drawn.
struct LineTroubleDetail: View {
    let need: TodayPage.Need
    let page: TodayPage
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
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

    private var nearby: [Board.Departure] { (model.board?.departures ?? []).filter { $0.line == line } }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            yours
            if let body = card?.body, !body.isEmpty {
                Text(body).font(.geist(14.5)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
            }
            if let stops = card?.stops, !stops.isEmpty {
                Label { Text(stops.joined(separator: " · ")).fixedSize(horizontal: false, vertical: true) } icon: { Image(systemName: "mappin.and.ellipse") }
                    .font(.geist(13.5)).foregroundStyle(Palette.textSoft)
            }
            if !nearby.isEmpty {
                VStack(alignment: .leading, spacing: 6) {
                    Text("Next \(Board.badge(line)) near you").font(.geist(12.5, .semibold)).foregroundStyle(Palette.textSoft)
                    ForEach(nearby.prefix(2)) { departure in
                        HStack(spacing: 6) {
                            Image(systemName: "arrow.right").font(.system(size: 11, weight: .bold))
                            Text(departure.direction).font(.geist(14.5, .semibold)).lineLimit(1)
                            Text("from \(departure.stop)").font(.geist(13)).foregroundStyle(Palette.textSoft).lineLimit(1)
                            Spacer(minLength: 4)
                            Text(DepartureLine.times(departure.minutes)).font(.geist(14, .semibold)).monospacedDigit()
                        }
                        .foregroundStyle(Palette.text)
                    }
                }
            }
            HStack(spacing: 16) {
                if let url = card?.url {
                    Button { link = WebPage(url: url) } label: {
                        Label("KVB’s page", systemImage: "arrow.up.right").labelStyle(TrailingIcon())
                    }
                }
                Spacer(minLength: 0)
                Text("From KVB").foregroundStyle(Palette.textSoft).font(.geist(12))
            }
            .font(.geist(14, .semibold)).foregroundStyle(TodayLink.ink)
        }
        .padding(.leading, 54).padding(.trailing, 14).padding(.bottom, 14)
        .transition(.opacity.combined(with: .offset(y: -6)))
        .task {
            if model.board == nil { await model.loadBoard(session.api) }
            await find()
        }
        .sheet(item: $link) { SafariView(url: $0.url).ignoresSafeArea() }
    }

    /// Whether it touches them today, from their own ways: one line, its sign in its tone.
    private var yours: some View {
        let lines = todaysLines
        let onIt = lines.contains(line)
        let others = lines.filter { $0 != line }
        return Label {
            Text(onIt ? "On your way today: one of today’s ways rides line \(Board.badge(line))."
                 : others.isEmpty ? "Not on your way today." : "Not on your way today: you ride \(others.count == 1 ? "line" : "lines") \(others.map { Board.badge($0) }.joined(separator: ", ")).")
                .fixedSize(horizontal: false, vertical: true)
        } icon: {
            Image(systemName: onIt ? "exclamationmark.circle.fill" : "checkmark.circle.fill")
                .foregroundStyle(onIt ? NeedTone.color("attention") : Motion.green)
        }
        .font(.geist(14.5, .semibold)).foregroundStyle(Palette.text)
    }

    /// The bell's card behind the note: KVB's words, the stops and its page. Opened counts as read.
    private func find() async {
        guard let id = need.alert, card == nil else { return }
        guard let found = ((try? await session.api.alerts()) ?? []).first(where: { $0.id == id }) else { return }
        withAnimation(.easeOut(duration: 0.25)) { card = found }
        if !found.read { try? await session.api.readAlert(id) }
    }
}

/// The round close button the canvas's sheets carry (.xb).
struct CloseButton: View {
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: "xmark").font(.system(size: 16, weight: .semibold)).foregroundStyle(Palette.text)
                .frame(width: 44, height: 44)
        }
        .buttonStyle(IconTileStyle(fill: Palette.surface, size: 44))
        .accessibilityLabel("Close")
    }
}

/// A page of the source's own, opened in place.
struct WebPage: Identifiable {
    let url: URL
    var id: String { url.absoluteString }
}
