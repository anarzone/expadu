import API
import Core
import DesignSystem
import SwiftUI

/// An event's details, pushed over What's on as the canvas draws it: the poster, what to know
/// before you go (only what's real: a clash or a fit with today's plans, rain at its start, the
/// listing's own tip), when, where, price and language, what it is, Not interested, and a bar
/// at the bottom to add it to the day.
struct EventDetail: View {
    /// What's on's own day, read live, so a save here shows here at once.
    @Binding var day: WhatsOn
    /// The listing as it was opened, in case the day reloads without it.
    let opened: WhatsOn.Listing
    let page: TodayPage?
    let save: (WhatsOn.Listing) -> Void
    let notInterested: (WhatsOn.Listing) -> Void
    /// Back to the list that pushed it.
    let back: () -> Void
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    /// The title in a bar once the poster has scrolled away (.wd.bar).
    @State private var bar = false
    /// How far the page has scrolled: the poster's kind and tape fade before they reach the buttons.
    @State private var scrolled: CGFloat = 0
    @State private var going = false
    /// Getting there's ways, planned when the details open; the Where row shows the first one's time.
    @State private var plan: WayPlan?
    @State private var planProblem: String?
    @State private var listingOpen = false
    /// Why Add to my day didn't go in, said here: Today's own note is under this sheet.
    @State private var refusal: String?

    private var listing: WhatsOn.Listing { day.listings.first { $0.id == opened.id } ?? opened }
    private var idea: TodayPage.Idea { listing.idea }
    private var now: Date { .now }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                hero
                let cases = cases
                if !cases.isEmpty {
                    heading("Before you go")
                    VStack(spacing: 0) {
                        ForEach(Array(cases.enumerated()), id: \.offset) { index, item in
                            if index > 0 { Rectangle().fill(Palette.border).frame(height: 1) }
                            CaseRow(item: item)
                        }
                    }
                    .clipShape(.rect(cornerRadius: 22))
                    .card(radius: 22)
                }
                heading("Details")
                details
                if let summary = listing.summary, !summary.isEmpty {
                    heading("About")
                    Text(summary).font(.geist(16)).lineSpacing(5).foregroundStyle(Palette.text)
                        .padding(.horizontal, 4)
                        .fixedSize(horizontal: false, vertical: true)
                    if listing.summaryLang != nil {
                        Text("In the listing's own words").font(.geist(13)).foregroundStyle(Palette.textSoft).padding(.horizontal, 4).padding(.top, 6)
                    }
                }
                if listing.source != nil {
                    Button { listingOpen = true } label: {
                        HStack(spacing: 6) {
                            Text("See the listing")
                            Image(systemName: "arrow.up.right").font(.system(size: 12, weight: .bold))
                        }
                        .font(.geist(15, .semibold)).foregroundStyle(TodayLink.ink)
                        .fixedSize()
                        .frame(minHeight: 44)
                    }
                    .buttonStyle(PressStyle())
                    .padding(.horizontal, 4).padding(.top, 6)
                }
                Button { notInterested(listing) } label: {
                    Label("Not interested", systemImage: "eye.slash")
                        .font(.geist(16, .medium)).foregroundStyle(Color(light: 0xD22D1E, dark: 0xFF8D72))
                        .frame(maxWidth: .infinity).frame(minHeight: 52)
                        .contentShape(.rect)
                }
                .buttonStyle(PressStyle())
                .clipShape(.rect(cornerRadius: 22))
                .card(radius: 22)
                .padding(.top, 28)
                Text("You'll see fewer events like this. You can undo it straight away.")
                    .font(.geist(13)).foregroundStyle(Palette.textSoft).lineSpacing(2)
                    .padding(.horizontal, 16).padding(.top, 8)
            }
            .padding(.horizontal, 16).padding(.bottom, 128)
        }
        .scrollIndicators(.hidden)
        // The poster runs to the top; no system fade over it.
        .scrollEdgeEffectHidden(true, for: .top)
        .ignoresSafeArea(edges: .top)
        .onScrollGeometryChange(for: CGFloat.self) { max(0, $0.contentOffset.y + $0.contentInsets.top) } action: { _, now in scrolled = now }
        .onScrollGeometryChange(for: Bool.self) { $0.contentOffset.y + $0.contentInsets.top > 250 } action: { _, on in
            withAnimation(Motion.ios(0.3)) { bar = on }
        }
        .background(Palette.canvas.ignoresSafeArea())
        .overlay(alignment: .top) { nav }
        .overlay(alignment: .bottom) { foot }
        .toolbar { buttons }
        .toolbarBackgroundVisibility(.hidden, for: .navigationBar)
        .navigationDestination(isPresented: $going) {
            GettingThere(listing: listing, day: day, plan: $plan, problem: $planProblem, replan: replan)
        }
        .task { if plan == nil { await replan() } }
        .sheet(isPresented: $listingOpen) { if let source = listing.source { SafariView(url: source).ignoresSafeArea() } }
    }

    // MARK: Top

    /// The title in a bar once the poster has scrolled away (.wd.bar); the buttons are the toolbar's.
    private var nav: some View {
        ZStack(alignment: .top) {
            if bar {
                Text(idea.title).font(.geist(17, .semibold)).foregroundStyle(Palette.text).lineLimit(1)
                    .padding(.horizontal, 124).padding(.top, 14)
                    .frame(maxWidth: .infinity).frame(height: 72)
                    .background(.ultraThinMaterial)
                    .overlay(alignment: .bottom) { Rectangle().fill(Palette.border).frame(height: 0.5) }
                    .transition(.offset(y: -20).combined(with: .opacity))
            }
        }
        .allowsHitTesting(false)
    }

    /// Share and save in the toolbar, drawn by the system in Liquid Glass (iOS 26); back is the
    /// system's own, so the edge swipe goes back too.
    @ToolbarContentBuilder
    private var buttons: some ToolbarContent {
        ToolbarItem(placement: .topBarTrailing) {
            ShareLink(item: listing.source ?? URL(string: "https://expadu.com")!, subject: Text(idea.title), message: Text(shareLine)) {
                Image(systemName: "square.and.arrow.up").font(.system(size: 17, weight: .semibold)).foregroundStyle(Palette.text)
            }
            .accessibilityLabel("Share")
        }
        ToolbarItem(placement: .topBarTrailing) {
            // The same save as everywhere else: the fill rises from the bottom, no bounce.
            Button { save(listing) } label: {
                FillingBookmark(filled: idea.saved, size: 18, weight: .semibold)
                    .foregroundStyle(WhatsOnLook.saveInk)
            }
            .accessibilityLabel(idea.saved ? "Saved: \(idea.title)" : "Save \(idea.title)")
            .sensoryFeedback(.selection, trigger: idea.saved)
        }
    }

    private var shareLine: String {
        [idea.title, whenLine, idea.place].compactMap { $0 }.joined(separator: " · ")
    }

    /// The poster: the ring, the kind, the tape, the title and its time.
    private var hero: some View {
        let night = WhatsOnLook.night(idea, now: now)
        let ink = night ? WhatsOnLook.yellow : WhatsOnLook.indigo
        return VStack(alignment: .leading, spacing: 0) {
            VStack(alignment: .leading, spacing: 8) {
                Group {
                    Text(idea.category).font(.geist(12, .semibold)).foregroundStyle(ink)
                        .padding(.horizontal, 9).padding(.vertical, 3)
                        .background(.white.opacity(0.2), in: .rect(cornerRadius: 6, style: .continuous))
                        .overlay { RoundedRectangle(cornerRadius: 6, style: .continuous).strokeBorder(.white.opacity(0.45)) }
                    Text(WhatsOnLook.stamp(idea, day: day, now: now).uppercased()).font(.posterMarker(14))
                        .foregroundStyle(Color(hex: 0x211D15))
                        .padding(.horizontal, 8).padding(.vertical, 2)
                        .background(WhatsOnLook.yellow)
                        .rotationEffect(.degrees(-2))
                }
                .opacity(max(0, 1 - scrolled / 50))
                Spacer(minLength: 0)
                HStack(alignment: .bottom, spacing: 10) {
                    Text(idea.title.uppercased()).font(.posterGrotesque(30)).lineSpacing(-5).foregroundStyle(ink)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .fixedSize(horizontal: false, vertical: true)
                    OutlinedText(text: WhatsOnLook.time(idea), size: 34, ink: ink)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .frame(maxWidth: .infinity, minHeight: 340 - 98, alignment: .topLeading)
        .padding(.top, 76).padding(.horizontal, 20).padding(.bottom, 22)
        // .pk-ring: off the top right corner, as on the canvas.
        .background(alignment: .topTrailing) {
            Circle().strokeBorder(ink, lineWidth: 22).frame(width: 220, height: 220).opacity(0.85)
                .offset(x: 70, y: -60)
        }
        .background(night ? WhatsOnLook.indigo : WhatsOnLook.yellow)
        .clipped()
        .padding(.horizontal, -16)
        .accessibilityElement(children: .combine)
    }

    private func heading(_ title: String) -> some View {
        Text(title).font(.posterGrotesque(20)).foregroundStyle(Palette.text)
            .padding(.top, 26).padding(.bottom, 10).padding(.horizontal, 4)
            .accessibilityAddTraits(.isHeader)
    }

    // MARK: Before you go

    /// Only what's real: today's plans against its time, rain at its start, the listing's tip.
    private var cases: [CaseRow.Item] {
        var items: [CaseRow.Item] = []
        if day.isToday, idea.timed, let start = idea.startsAt, let page {
            let end = idea.endsAt ?? start.addingTimeInterval(3600)
            let plans = page.plans.filter { !$0.completed && $0.start != nil }
            let clash = plans.first { plan in
                guard let from = Self.today(plan.start) else { return false }
                let to = Self.today(plan.end) ?? from.addingTimeInterval(3600)
                return from < end && to > start
            }
            // A plan starting soon after it ends leaves little time to get there.
            let next = plans.first { plan in
                guard let from = Self.today(plan.start) else { return false }
                return from >= end && from.timeIntervalSince(end) < 45 * 60
            }
            let span = idea.endsAt.map { "\(TodayPage.clock(start))–\(TodayPage.clock($0))" } ?? "at \(TodayPage.clock(start))"
            if let clash, let from = clash.start {
                items.append(.init(tone: .attention, symbol: "exclamationmark.triangle.fill", head: "Clashes with \(clash.title)",
                                   why: "Your \(clash.title) is at \(from)" + (clash.end.map { "–\($0)" } ?? "") + "."))
            } else if let next, let from = next.start {
                items.append(.init(tone: .attention, symbol: "clock.fill", head: "Close to your \(next.title)",
                                   why: "It ends at \(TodayPage.clock(end)) and your \(next.title) starts at \(from)."))
            } else if !plans.isEmpty {
                items.append(.init(tone: .good, symbol: "checkmark", head: "Fits your day", why: "Nothing in your plan \(span.hasPrefix("at") ? span : "between " + span.replacingOccurrences(of: "–", with: " and "))."))
            }
            if let hour = page.weather?.at(TodayPage.clock(start)), let rain = hour.rain, rain >= 50 {
                items.append(.init(tone: .heads, symbol: "cloud.rain.fill", head: "Rain likely around \(TodayPage.clock(start))",
                                   why: "\(rain)% chance of rain when it starts."))
            }
        }
        if let tip = listing.tip, !tip.isEmpty, Self.speaksOf(day.date, tip) {
            items.append(.init(tone: .heads, symbol: "lightbulb.fill", head: "Worth knowing", why: tip))
        }
        return items
    }

    /// A tip written for another date of a series ("starts Sunday 1 November") isn't about this one.
    private static func speaksOf(_ date: String, _ text: String) -> Bool {
        let months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"]
        guard let pattern = try? NSRegularExpression(pattern: "\\b(\\d{1,2})\\.? (" + months.joined(separator: "|") + ")\\b") else { return true }
        let parts = date.split(separator: "-").compactMap { Int($0) }
        guard parts.count == 3 else { return true }
        let found = pattern.matches(in: text, range: NSRange(text.startIndex..., in: text))
        return found.allSatisfy { match in
            guard let day = Range(match.range(at: 1), in: text).flatMap({ Int(text[$0]) }),
                  let month = Range(match.range(at: 2), in: text).flatMap({ months.firstIndex(of: String(text[$0])) }) else { return true }
            return day == parts[2] && month + 1 == parts[1]
        }
    }

    /// A plan's "18:30" today as a time.
    private static func today(_ clock: String?) -> Date? {
        guard let clock else { return nil }
        let parts = clock.split(separator: ":").compactMap { Int($0) }
        guard parts.count == 2 else { return nil }
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TimeZone(identifier: "Europe/Berlin")!
        return calendar.date(bySettingHour: parts[0], minute: parts[1], second: 0, of: .now)
    }

    // MARK: Details

    private var whenLine: String {
        let dayWord = day.isToday ? "Today" : TodayPage.shortDay(day.date)
        guard idea.timed, let start = idea.startsAt else { return "\(dayWord) · \(idea.untimed ?? "all day")" }
        return "\(dayWord) · " + (idea.endsAt.map { "\(TodayPage.clock(start))–\(TodayPage.clock($0))" } ?? TodayPage.clock(start))
    }

    private var whenNote: String {
        if WhatsOnLook.onNow(idea, now: now) { return idea.endsAt.map { "On now, until \(TodayPage.clock($0))" } ?? "On now" }
        return idea.timed ? "Listed times" : (idea.untimed == nil ? "No time listed" : "Listed dates")
    }

    private var details: some View {
        VStack(spacing: 0) {
            DetailRow(symbol: "clock.fill", tint: Color(hex: 0xE6421F), title: whenLine, note: whenNote)
            divider
            let hasPlace = listing.latitude != nil && listing.longitude != nil
            Button { going = true } label: {
                DetailRow(symbol: "mappin", tint: Color(hex: 0x2F6FD0), title: idea.place ?? idea.area ?? "Place not listed",
                          note: idea.place == nil ? nil : idea.area, chevron: hasPlace, go: goChip)
            }
            .buttonStyle(.plain)
            .disabled(!hasPlace)
            .accessibilityHint(hasPlace ? "Getting there" : "")
            divider
            DetailRow(symbol: "ticket.fill", tint: Color(hex: 0x2F9E5B), title: idea.price ?? "Price not listed", note: "Admission")
            if let language = listing.languageLine {
                divider
                DetailRow(symbol: "bubble.left.fill", tint: Color(hex: 0x6A459B), title: language, note: "Language")
            }
        }
        .clipShape(.rect(cornerRadius: 22))
        .card(radius: 22)
    }

    /// The first way's time on the Where row (.ig-go): "14 min" with how.
    private var goChip: (symbol: String, text: String)? {
        guard let way = plan?.ways.first else { return nil }
        return (way.lines.isEmpty ? (way.mode == "bike" ? "bicycle" : "figure.walk") : "tram.fill", "\(way.minutes) min")
    }

    /// The ways from where the phone is, to be there five minutes before it starts.
    private func replan() async {
        guard let latitude = listing.latitude, let longitude = listing.longitude else { return }
        let from = today.place
        do throws(APIFailure) {
            let next = try await session.api.planWay(to: idea.title, latitude: latitude, longitude: longitude, fromLatitude: from?.latitude, fromLongitude: from?.longitude,
                                                     startsAt: idea.aimFor())
            withAnimation(.snappy) { plan = next; planProblem = next.ways.isEmpty ? "No way there was found for this time. Try again in a moment." : nil }
        } catch {
            withAnimation(.snappy) { planProblem = Self.words(error) }
        }
    }

    private static func words(_ error: APIFailure) -> String {
        switch error {
        case let .refused(why): why
        case .signedOut: "You’ve been signed out."
        case .tooManyTries: "Too many plans in a row. Try again in a minute."
        case .unreachable: "Couldn’t reach Expadu. Check your connection."
        }
    }

    private var divider: some View {
        Rectangle().fill(Palette.border).frame(height: 1).padding(.leading, 59)
    }

    // MARK: Bottom bar

    /// The action floats (the tab bar's way): a glass pill set in from the edges, the price and
    /// when on its left, Add to my day filling the rest. The page scrolls under it, no strip, no line.
    private var foot: some View {
        let added = today.isAdded(idea.candidate)
        return VStack(alignment: .leading, spacing: 10) {
            if let refusal {
                Label(refusal, systemImage: "exclamationmark.circle.fill")
                    .font(.geist(13.5, .semibold)).foregroundStyle(Palette.conflictText)
                    .fixedSize(horizontal: false, vertical: true)
                    .padding(.horizontal, 14).padding(.vertical, 10)
                    .background(Palette.surface, in: .rect(cornerRadius: 16))
                    .transition(.offset(y: 8).combined(with: .opacity))
            }
            HStack(spacing: 10) {
                VStack(alignment: .leading, spacing: 1) {
                    Text(idea.price ?? "Price not listed").font(.geist(16, .bold)).foregroundStyle(Palette.text).lineLimit(1)
                    Text(whenLine).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1)
                }
                .padding(.leading, 14)
                .frame(maxWidth: .infinity, alignment: .leading)
                Button {
                    Task {
                        await today.change(session.api, action: "add", date: day.date, candidate: idea.candidate, symbol: "calendar.badge.plus")
                        if let note = today.note, note.problem {
                            withAnimation(.easeOut(duration: 0.3)) { refusal = note.text }
                            try? await Task.sleep(for: .seconds(6))
                            withAnimation(.easeOut(duration: 0.25)) { refusal = nil }
                        }
                    }
                } label: {
                    Text(added ? "✓ In your plan" : "Add to my day").font(.geist(17, .semibold))
                        .foregroundStyle(added ? Palette.addedText : .white)
                        .contentTransition(.interpolate)
                        .padding(.horizontal, 22).frame(height: 50)
                        .buttonFace(added ? Palette.addedBackground : Palette.action)
                }
                .buttonStyle(PressStyle())
                .disabled(added || idea.candidate.isEmpty)
                .animation(.easeInOut(duration: 0.35), value: added)
                .sensoryFeedback(.success, trigger: added)
            }
            .padding(6)
            .glassEffect(.regular, in: .rect(cornerRadius: 28, style: .continuous))
            .shadow(color: .black.opacity(0.12), radius: 14, y: 6)
        }
        .padding(.horizontal, 16).padding(.bottom, 6)
    }
}

/// A Details row: a coloured tile, its line and the note under it (.ig-r with .ig-ic).
private struct DetailRow: View {
    let symbol: String
    let tint: Color
    let title: String
    let note: String?
    var chevron = false
    var go: (symbol: String, text: String)?

    var body: some View {
        HStack(spacing: 13) {
            Image(systemName: symbol).font(.system(size: 14, weight: .semibold)).foregroundStyle(.white)
                .frame(width: 32, height: 32).background(tint, in: .rect(cornerRadius: 9))
            VStack(alignment: .leading, spacing: 1) {
                Text(title).font(.geist(16, .semibold)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                if let note, !note.isEmpty { Text(note).font(.geist(13.5)).foregroundStyle(Palette.textSoft) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            if let go {
                Label(go.text, systemImage: go.symbol).labelStyle(Tight())
                    .font(.geist(14, .semibold)).foregroundStyle(Color(hex: 0x2F6FD0))
                    .padding(.horizontal, 10).frame(height: 28)
                    .background(Color(light: 0xEAF1F8, dark: 0x1E2C40), in: .rect(cornerRadius: 8, style: .continuous))
                    .transition(.scale(scale: 0.6).combined(with: .opacity))
            }
            if chevron {
                Image(systemName: "chevron.right").font(.system(size: 12, weight: .semibold)).foregroundStyle(Palette.textSoft)
            }
        }
        .padding(.horizontal, 14).padding(.vertical, 10).frame(minHeight: 58)
        .contentShape(.rect)
        .accessibilityElement(children: .combine)
    }
}

/// A Before you go case (.cs): its tone's tile, the head and why.
private struct CaseRow: View {
    struct Item {
        enum Tone { case attention, good, heads }
        let tone: Tone
        let symbol: String
        let head: String
        let why: String
    }

    let item: Item

    private var tint: Color {
        switch item.tone {
        case .attention: Color(hex: 0xC98208)
        case .good: Color(hex: 0x2F9E5B)
        case .heads: Color(hex: 0x2F78C8)
        }
    }

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            Image(systemName: item.symbol).font(.system(size: 15, weight: .semibold)).foregroundStyle(tint)
                .frame(width: 34, height: 34).background(tint.opacity(0.14), in: .rect(cornerRadius: 11))
            VStack(alignment: .leading, spacing: 2) {
                Text(item.head).font(.geist(15, .bold)).foregroundStyle(Palette.text)
                Text(item.why).font(.geist(13.5)).foregroundStyle(Palette.textSoft).lineSpacing(2).fixedSize(horizontal: false, vertical: true)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(.horizontal, 14).padding(.vertical, 13)
        .accessibilityElement(children: .combine)
    }
}
