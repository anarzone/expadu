import API
import Core
import DesignSystem
import SwiftUI

/// What's on (All events on Today), as the canvas draws it: the day's pill, search, Free and
/// Saved, the kinds of event, list or grid; picks for the day as big posters, then every listing
/// under its start time. The listings are the city's real ones; search and filters run here.
struct WhatsOnSheet: View {
    /// A listing to open straight away (an idea tapped on Today), by its candidate id.
    var open: String?
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(\.dismiss) private var dismiss
    @Environment(\.shell) private var shell
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var day: WhatsOn?
    @State private var date: String?
    @State private var path: [String] = []
    /// The idea tapped on Today opens once; back from it stays on the list.
    @State private var openedIdea = false
    /// Not interested: off the list at once; the signal goes when Undo has had its moment.
    @State private var hidden: Set<String> = []
    @State private var hiding: (listing: WhatsOn.Listing, send: Task<Void, Never>)?
    @State private var problem: String?
    @State private var query = ""
    @State private var free = false
    @State private var savedOnly = false
    @State private var category: String?
    @State private var grid = false
    @State private var finding = false
    /// Each change of day or kind plays the rows in again (the canvas's in-a / in-b).
    @State private var round = 0
    @FocusState private var searching: Bool

    init(open: String? = nil, date: String? = nil) {
        self.open = open
        _date = State(initialValue: date)
    }

    var body: some View {
        NavigationStack(path: $path) {
            list
                .toolbar(.hidden, for: .navigationBar)
                .navigationDestination(for: String.self) { id in
                    if let loaded = day, let listing = loaded.listings.first(where: { $0.id == id }) {
                        EventDetail(day: Binding(get: { day ?? loaded }, set: { day = $0 }), opened: listing, page: today.page,
                                    save: save, notInterested: notInterested) {
                            withAnimation(Motion.ios(0.4)) { if !path.isEmpty { path.removeLast() } }
                        }
                    }
                }
        }
        .flexibleSheet()
        .presentationBackground(Palette.canvas)
        .task(id: date) { await load() }
        .onChange(of: searching) { _, on in if on { withAnimation(Motion.ios(0.44)) { finding = true } } }
    }

    private var list: some View {
        VStack(spacing: 0) {
            top
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    if let day { content(day) } else if let problem { trouble(problem) } else { ProgressView().frame(maxWidth: .infinity).padding(.top, 60) }
                }
                .padding(.horizontal, 16).padding(.top, 4).padding(.bottom, 40)
            }
            .scrollIndicators(.hidden)
            .scrollDismissesKeyboard(.immediately)
        }
        .background(Palette.canvas)
    }

    // MARK: Top

    private var top: some View {
        VStack(alignment: .leading, spacing: 12) {
            if !finding {
                HStack(alignment: .top) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("What's on").font(.posterGrotesque(28)).tracking(-0.4).foregroundStyle(Palette.text)
                        Text("Cologne · \(countLine)").font(.geist(13)).foregroundStyle(Palette.textSoft)
                            .contentTransition(.numericText())
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
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
            controls
            kinds
        }
        .padding(.horizontal, 16).padding(.top, 22).padding(.bottom, 12)
        .background(Palette.surface)
        .overlay(alignment: .bottom) { Rectangle().fill(Palette.border).frame(height: 1) }
    }

    private var controls: some View {
        HStack(spacing: 8) {
            if !finding {
                datePill.transition(.scale(scale: 0.4).combined(with: .opacity))
            }
            HStack(spacing: 7) {
                Image(systemName: "magnifyingglass").font(.system(size: 15, weight: .medium)).foregroundStyle(Palette.textSoft)
                TextField(finding ? "Events, venues, areas" : "Search", text: $query)
                    .font(.geist(17)).foregroundStyle(Palette.text)
                    .focused($searching)
                    .submitLabel(.search)
                    .autocorrectionDisabled()
                    .textInputAutocapitalization(.never)
                if !query.isEmpty {
                    Button { query = "" } label: {
                        Image(systemName: "xmark.circle.fill").font(.system(size: 17)).foregroundStyle(Palette.textSoft.opacity(0.7))
                            .frame(width: 28, height: 28).contentShape(.rect)
                    }
                    .accessibilityLabel("Clear search")
                }
            }
            .padding(.leading, 13).padding(.trailing, 8).frame(height: 44)
            .background(finding ? Palette.surface : Palette.quiet, in: .capsule)
            .overlay { if finding { Capsule().strokeBorder(Palette.border) } }
            .shadow(color: Color(hex: 0x211D15, opacity: finding ? 0.12 : 0), radius: 9, y: 6)
            .contentShape(.capsule)
            .onTapGesture { searching = true }
            if !finding {
                toggle("tag", on: free, label: "Free only") { free.toggle() }
                    .transition(.scale(scale: 0.4).combined(with: .opacity))
                toggle(savedOnly ? "bookmark.fill" : "bookmark", on: savedOnly, label: "Saved only") { savedOnly.toggle() }
                    .transition(.scale(scale: 0.4).combined(with: .opacity))
            } else {
                Button {
                    searching = false
                    query = ""
                    withAnimation(Motion.ios(0.44)) { finding = false }
                } label: {
                    Image(systemName: "xmark").font(.system(size: 16, weight: .semibold)).foregroundStyle(Palette.text)
                        .frame(width: 44, height: 44).background(Palette.quiet, in: .circle)
                }
                .buttonStyle(PressStyle())
                .accessibilityLabel("Close search")
                .transition(.scale(scale: 0.4).combined(with: .opacity))
            }
        }
    }

    /// The day as a taped-on label; its menu lists the days ahead with how many events each has.
    private var datePill: some View {
        Menu {
            ForEach(day?.days ?? []) { option in
                Button {
                    date = option.date
                } label: {
                    Label {
                        Text(day.map { $0.label(for: option.date) } ?? option.date)
                        Text("\(option.count) events")
                    } icon: {
                        if option.date == day?.date { Image(systemName: "checkmark") }
                    }
                }
            }
        } label: {
            HStack(spacing: 4) {
                Text((day.map { $0.label(for: $0.date) } ?? "Today").uppercased()).font(.posterMarker(15))
                    .lineLimit(1).fixedSize()
                Image(systemName: "chevron.down").font(.system(size: 11, weight: .bold))
            }
            .foregroundStyle(Color(hex: 0x211D15))
            .padding(.horizontal, 9).frame(height: 44)
            .background(Color(hex: 0xFFE363), in: .rect(cornerRadius: 4))
            .shadow(color: Color(hex: 0x211D15, opacity: 0.15), radius: 3, y: 2)
            .rotationEffect(.degrees(-1.5))
        }
        .accessibilityLabel("Date: \(day.map { $0.label(for: $0.date) } ?? "Today"). Choose another date")
    }

    private func toggle(_ symbol: String, on: Bool, label: String, action: @escaping () -> Void) -> some View {
        Button {
            withAnimation(.spring(duration: 0.35, bounce: 0.3)) { action() }
        } label: {
            Image(systemName: symbol).font(.system(size: 17, weight: .medium))
                .foregroundStyle(on ? Color(light: 0x005F70, dark: 0x73D3E2) : Palette.text)
                .contentTransition(.symbolEffect(.replace))
                .frame(width: 44, height: 44)
                .background(on ? Color(light: 0xDEF5F8, dark: 0x1D3F47) : Palette.quiet, in: .circle)
        }
        .buttonStyle(PressStyle())
        .accessibilityLabel(label)
        .accessibilityAddTraits(on ? [.isSelected] : [])
        .sensoryFeedback(.selection, trigger: on)
    }

    /// The kinds on this day, then List or Grid.
    private var kinds: some View {
        HStack(spacing: 8) {
            ScrollView(.horizontal) {
                HStack(spacing: 4) {
                    kind(nil, "All events")
                    ForEach(day?.categories ?? []) { kind($0.id, $0.label) }
                }
                .padding(.vertical, 4).padding(.leading, 16).padding(.trailing, 8)
            }
            .scrollIndicators(.hidden)
            .mask(LinearGradient(stops: [.init(color: .black, location: 0.9), .init(color: .clear, location: 1)], startPoint: .leading, endPoint: .trailing))
            HStack(spacing: 0) {
                showAs("list.bullet", on: !grid, label: "List") { grid = false }
                showAs("square.grid.2x2", on: grid, label: "Grid") { grid = true }
            }
            .padding(2).frame(height: 36)
            .background(Palette.quiet, in: .capsule)
        }
        .padding(.leading, -16)
    }

    private func kind(_ id: String?, _ label: String) -> some View {
        let on = category == id
        return Button {
            withAnimation(.snappy) { category = id }
            round += 1
        } label: {
            Text(label).font(.geist(15, on ? .semibold : .medium)).foregroundStyle(Palette.text)
                .padding(.horizontal, 14).frame(height: 36)
                .background(on ? Color(light: 0xDEF5F8, dark: 0x1D3F47) : .clear, in: .capsule)
                .contentShape(.capsule)
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(on ? [.isSelected] : [])
    }

    private func showAs(_ symbol: String, on: Bool, label: String, action: @escaping () -> Void) -> some View {
        Button {
            withAnimation(.spring(duration: 0.35, bounce: 0.25)) { action() }
            round += 1
        } label: {
            Image(systemName: symbol).font(.system(size: 15, weight: .semibold))
                .foregroundStyle(on ? Palette.text : Palette.textSoft)
                .frame(width: 44, height: 32)
                .background(on ? Palette.surface : .clear, in: .capsule)
                .shadow(color: Color(hex: 0x211D15, opacity: on ? 0.16 : 0), radius: 2, y: 1)
                .contentShape(.capsule)
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label)
        .accessibilityAddTraits(on ? [.isSelected] : [])
    }

    // MARK: Body

    private var shown: [WhatsOn.Listing] {
        (day?.listings ?? []).filter { listing in
            !hidden.contains(listing.id) && (category == nil || listing.category == category) && (!free || listing.free) && (!savedOnly || listing.idea.saved) && listing.matches(query)
        }
    }

    private var countLine: String {
        guard let day else { return "loading" }
        let count = shown.count
        return "\(count) \(count == 1 ? "event" : "events")" + (day.isToday ? " from now" : "")
    }

    @ViewBuilder
    private func content(_ day: WhatsOn) -> some View {
        let now = Date.now
        let shown = shown
        if finding && query.isEmpty {
            suggestions(day)
        } else {
            // Soonest first: what is still to start, so a pick is something to go to.
            let picks = shown.filter { listing in listing.idea.timed && (listing.idea.startsAt.map { $0 > now } ?? false) }.prefix(4)
            if picks.count > 1 && query.isEmpty && category == nil && !savedOnly {
                VStack(alignment: .leading, spacing: 0) {
                    HStack(alignment: .firstTextBaseline, spacing: 8) {
                        Text(day.isToday ? "Picked for today" : "Picked for \(TodayPage.shortDay(day.date).split(separator: " ").first ?? "")")
                            .font(.posterGrotesque(20)).foregroundStyle(Palette.text)
                        Text("Soonest first").font(.geist(13)).foregroundStyle(Palette.textSoft)
                    }
                    .padding(.top, 14).padding(.bottom, 8)
                    ScrollView(.horizontal) {
                        HStack(spacing: 12) {
                            ForEach(Array(picks)) { listing in PickCard(listing: listing, day: day, now: now, save: save) { path.append(listing.id) } }
                        }
                        .scrollTargetLayout()
                        .padding(.horizontal, 16).padding(.top, 2).padding(.bottom, 10)
                    }
                    .scrollTargetBehavior(.viewAligned)
                    .scrollIndicators(.hidden)
                    .padding(.horizontal, -16)
                }
            }
            if let hiding {
                HStack(spacing: 10) {
                    Image(systemName: "eye.slash").font(.system(size: 14, weight: .semibold))
                    Text("\(hiding.listing.idea.title) is hidden. You'll see fewer like it.").font(.geist(14)).frame(maxWidth: .infinity, alignment: .leading)
                    Button("Undo") { undoHide() }
                        .font(.geist(14, .bold)).foregroundStyle(Palette.text)
                        .padding(.horizontal, 14).frame(height: 32)
                        .background(Palette.surface, in: .capsule)
                        .overlay { Capsule().strokeBorder(Palette.border) }
                }
                .foregroundStyle(Palette.text)
                .padding(.leading, 14).padding(.trailing, 10).padding(.vertical, 10)
                .background(Palette.quiet, in: .rect(cornerRadius: 16))
                .padding(.top, 10).padding(.bottom, 2)
                .transition(.offset(y: -12).combined(with: .opacity))
            }
            if day.isToday && day.ended > 0 {
                Text("Earlier today · \(day.ended) ended").font(.geist(13)).foregroundStyle(Palette.textSoft).padding(.top, 10).padding(.bottom, 2)
            }
            let groups = day.groups(shown, at: now)
            let order = Dictionary(uniqueKeysWithValues: groups.flatMap(\.listings).enumerated().map { ($1.id, $0) })
            ForEach(groups) { group in
                VStack(alignment: .leading, spacing: 0) {
                    Text(group.title).font(.geist(12, .bold)).monospacedDigit()
                        .foregroundStyle(Color(light: 0xFFFDF8, dark: group.now ? 0xFFFFFF : 0x15130E))
                        .padding(.horizontal, 8).padding(.vertical, 3)
                        .background(group.now ? Color(light: 0x00768B, dark: 0x1D9DB3) : Color(light: 0x211D15, dark: 0xF4EFE4), in: .rect(cornerRadius: 6))
                    if grid {
                        LazyVGrid(columns: [GridItem(.flexible(), spacing: 10), GridItem(.flexible(), spacing: 10)], spacing: 10) {
                            ForEach(group.listings) { listing in
                                EventTile(listing: listing, day: day, now: now, save: save) { path.append(listing.id) }
                                    .modifier(RowIn(order: order[listing.id] ?? 0, round: round))
                            }
                        }
                        .padding(.top, 10).padding(.bottom, 6)
                    } else {
                        ForEach(group.listings) { listing in
                            EventRow(listing: listing, day: day, now: now, save: save) { path.append(listing.id) }
                                .modifier(RowIn(order: order[listing.id] ?? 0, round: round))
                        }
                    }
                }
                .padding(.top, 12)
            }
            if shown.isEmpty {
                Text("Nothing like that on this day. Try another day, or ask Composer.")
                    .font(.geist(13)).foregroundStyle(Palette.textSoft).padding(.top, 10)
            }
            Button {
                dismiss()
                shell.openComposer()
            } label: {
                AskComposerLabel(title: "Something else? Ask Composer")
            }
            .buttonStyle(PressStyle())
            .padding(.top, 18).padding(.bottom, 10)
            Text("From the city's public listings. Saving one isn't a booking.")
                .font(.geist(12)).foregroundStyle(Palette.textSoft).frame(maxWidth: .infinity)
        }
    }

    /// Searching with nothing typed: what to try, from this day's own kinds.
    private func suggestions(_ day: WhatsOn) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Text("Try").font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft).padding(.top, 14)
            let tries = ["Free", "English"] + day.categories.map(\.label)
            FlowLayout(spacing: 8) {
                ForEach(tries, id: \.self) { word in
                    Button { query = word.lowercased() } label: {
                        Text(word).font(.geist(15, .medium)).foregroundStyle(Palette.text)
                            .padding(.horizontal, 14).frame(height: 36)
                            .background(Palette.surface, in: .capsule)
                            .overlay { Capsule().strokeBorder(Palette.border) }
                    }
                    .buttonStyle(PressStyle())
                }
            }
        }
        .transition(.opacity)
    }

    private func trouble(_ text: String) -> some View {
        VStack(spacing: 12) {
            Text(text).font(.geist(15)).foregroundStyle(Palette.textSoft).multilineTextAlignment(.center)
            Button("Try again") { Task { await load() } }.font(.geist(15, .semibold)).foregroundStyle(TodayLink.ink)
        }
        .frame(maxWidth: .infinity).padding(.top, 60)
    }

    // MARK: Actions

    private func load() async {
        do throws(APIFailure) {
            let next = try await session.api.whatsOn(date: date)
            withAnimation(reduceMotion ? nil : .snappy) {
                day = next
                problem = nil
                if let category, !next.categories.contains(where: { $0.id == category }) { self.category = nil }
            }
            round += 1
            if let open, !openedIdea, path.isEmpty, next.listings.contains(where: { $0.id == open }) {
                openedIdea = true
                path = [open]
            }
        } catch {
            if day == nil { problem = Self.words(error) }
        }
    }

    /// The bookmark flips at once; the server's answer settles it, and Today's own idea follows.
    private func save(_ listing: WhatsOn.Listing) {
        guard let index = day?.listings.firstIndex(where: { $0.id == listing.id }), let startsAt = listing.idea.startsAt else { return }
        let saving = !listing.idea.saved
        withAnimation(.spring(duration: 0.45, bounce: 0.4)) { day?.listings[index].idea.saved = saving }
        Task {
            do throws(APIFailure) {
                _ = try await session.api.saveEvent(id: listing.idea.id, startsAt: startsAt, saved: saving)
                today.markSaved(listing.idea.candidate, saved: saving)
            } catch {
                withAnimation { day?.listings[index].idea.saved = !saving }
            }
        }
    }

    /// Not interested: back to the list, the listing gone from it and from Today, a line to undo it.
    private func notInterested(_ listing: WhatsOn.Listing) {
        hiding?.send.cancel()
        let api = session.api
        let send = Task {
            try? await Task.sleep(for: .seconds(8))
            guard !Task.isCancelled else { return }
            try? await api.send([Signal(action: .notForMe, event: listing.idea.id)])
            withAnimation(.snappy) { if hiding?.listing.id == listing.id { hiding = nil } }
        }
        path.removeAll()
        withAnimation(.spring(duration: 0.45, bounce: 0.3).delay(0.35)) {
            hidden.insert(listing.id)
            hiding = (listing, send)
        }
        today.hideIdea(listing.idea.id, true)
    }

    private func undoHide() {
        guard let hiding else { return }
        hiding.send.cancel()
        withAnimation(.spring(duration: 0.45, bounce: 0.3)) {
            hidden.remove(hiding.listing.id)
            self.hiding = nil
        }
        today.hideIdea(hiding.listing.idea.id, false)
        round += 1
    }

    private static func words(_ error: APIFailure) -> String {
        switch error {
        case let .refused(why): why
        case .signedOut: "You’ve been signed out."
        case .tooManyTries: "Too many tries. Wait a moment."
        case .unreachable: "Couldn’t reach Expadu. Check your connection."
        }
    }
}

// MARK: - Pieces

/// The canvas's poster colours: yellow by day, indigo in the evening.
enum WhatsOnLook {
    static let yellow = Color(hex: 0xFFE363)
    static let indigo = Color(hex: 0x3D2F9E)
    static let saveInk = Color(light: 0x6A459B, dark: 0xC4A6F0)

    static func night(_ idea: TodayPage.Idea, now: Date) -> Bool {
        guard idea.timed, let start = idea.startsAt, start > now else { return false }
        return TodayPage.clock(start) >= "17:00"
    }

    static func onNow(_ idea: TodayPage.Idea, now: Date) -> Bool {
        guard idea.timed, let start = idea.startsAt else { return false }
        return start <= now && (idea.endsAt ?? start.addingTimeInterval(3600)) > now
    }

    /// The tape: "On now", "Tonight · 19:00", "Today · 14:00", "Sun 8 · 19:00".
    static func stamp(_ idea: TodayPage.Idea, day: WhatsOn, now: Date) -> String {
        if onNow(idea, now: now) { return "On now" }
        let when = night(idea, now: now) && day.isToday ? "Tonight" : day.isToday ? "Today" : (TodayPage.shortDay(day.date).split(separator: " ").prefix(2).joined(separator: " "))
        guard idea.timed, let start = idea.startsAt else { return when }
        return "\(when) · \(TodayPage.clock(start))"
    }

    static func tilt(_ listing: WhatsOn.Listing) -> Double {
        [-2, 1.5, -1, 2][listing.idea.id % 4]
    }

    static func time(_ idea: TodayPage.Idea) -> String {
        guard idea.timed, let start = idea.startsAt else { return "All day" }
        return TodayPage.clock(start)
    }
}

/// A chip under a listing: "No German needed" (the canvas's lang chip).
private struct EventChip: View {
    let text: String

    var body: some View {
        Text(text).font(.geist(12, .semibold)).lineLimit(1)
            .foregroundStyle(Color(light: 0x5A3887, dark: 0xCDB4F5))
            .padding(.horizontal, 8).frame(height: 22)
            .background(Color(light: 0xEFE7FB, dark: 0x2E2440), in: .capsule)
    }
}

struct SaveButton: View {
    let listing: WhatsOn.Listing
    let save: (WhatsOn.Listing) -> Void

    var body: some View {
        let saved = listing.idea.saved
        Button { save(listing) } label: {
            Image(systemName: saved ? "bookmark.fill" : "bookmark").font(.system(size: 17, weight: .medium))
                .foregroundStyle(WhatsOnLook.saveInk)
                .contentTransition(.symbolEffect(.replace))
                .symbolEffect(.bounce, value: saved)
                .frame(width: 44, height: 44)
                .background(saved ? Color(light: 0xF0EAFA, dark: 0x2E2440) : Palette.surface, in: .circle)
                .overlay { Circle().strokeBorder(saved ? Color(light: 0xD9C9EF, dark: 0x4A3A66) : Palette.border) }
        }
        .buttonStyle(PressStyle())
        .accessibilityLabel(saved ? "Saved: \(listing.idea.title)" : "Save \(listing.idea.title)")
        .sensoryFeedback(.selection, trigger: saved)
    }
}

/// A listing in the list: its poster thumb, title, where, when and price, and Save.
private struct EventRow: View {
    let listing: WhatsOn.Listing
    let day: WhatsOn
    let now: Date
    let save: (WhatsOn.Listing) -> Void
    let open: () -> Void

    var body: some View {
        let idea = listing.idea
        HStack(spacing: 12) {
            Button(action: open) {
            HStack(spacing: 12) {
            Poster(label: WhatsOnLook.time(idea), night: WhatsOnLook.night(idea, now: now), tilt: WhatsOnLook.tilt(listing))
            VStack(alignment: .leading, spacing: 3) {
                Text(idea.title).font(.geist(15, .semibold)).foregroundStyle(Palette.text).lineLimit(3)
                let where_ = [idea.place, idea.area].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: " · ")
                if !where_.isEmpty { Text(where_).font(.geist(13)).foregroundStyle(Palette.textSoft).lineLimit(1) }
                HStack(spacing: 6) {
                    Text([when(idea), idea.price].compactMap { $0 }.joined(separator: " · "))
                        .font(.geist(13)).foregroundStyle(Palette.text.opacity(0.75)).lineLimit(1)
                    if let words = listing.noGerman { EventChip(text: words) }
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            }
            .contentShape(.rect)
            }
            .buttonStyle(.plain)
            .accessibilityHint("Opens its details")
            SaveButton(listing: listing, save: save)
        }
        .padding(.vertical, 12)
        .overlay(alignment: .bottom) { Rectangle().fill(Palette.border).frame(height: 1) }
        .accessibilityElement(children: .combine)
    }

    private func when(_ idea: TodayPage.Idea) -> String {
        guard idea.timed, let start = idea.startsAt else { return idea.untimed ?? "All day" }
        if WhatsOnLook.onNow(idea, now: now) { return idea.endsAt.map { "On now until \(TodayPage.clock($0))" } ?? "On now" }
        return idea.endsAt.map { "\(TodayPage.clock(start))–\(TodayPage.clock($0))" } ?? TodayPage.clock(start)
    }
}

/// A listing in the grid: a poster with its tape, title and time, then where, price and Save.
private struct EventTile: View {
    let listing: WhatsOn.Listing
    let day: WhatsOn
    let now: Date
    let save: (WhatsOn.Listing) -> Void
    let open: () -> Void

    var body: some View {
        let idea = listing.idea
        let night = WhatsOnLook.night(idea, now: now)
        let ink = night ? WhatsOnLook.yellow : WhatsOnLook.indigo
        VStack(spacing: 0) {
            Button(action: open) {
            VStack(alignment: .leading, spacing: 8) {
                Text(WhatsOnLook.stamp(idea, day: day, now: now).uppercased()).font(.posterMarker(11))
                    .foregroundStyle(Color(hex: 0x211D15))
                    .padding(.horizontal, 6).padding(.vertical, 1)
                    .background(WhatsOnLook.yellow)
                    .rotationEffect(.degrees(-2))
                Spacer(minLength: 0)
                Text(idea.title.uppercased()).font(.posterGrotesque(15)).lineSpacing(-2).lineLimit(4).foregroundStyle(ink)
                OutlinedText(text: WhatsOnLook.time(idea), size: 22, ink: ink)
            }
            .frame(maxWidth: .infinity, minHeight: 150, alignment: .topLeading)
            .padding(10)
            .background(night ? WhatsOnLook.indigo : WhatsOnLook.yellow)
            }
            .buttonStyle(.plain)
            .accessibilityLabel("\(idea.title). Open details")
            HStack(alignment: .top, spacing: 6) {
                VStack(alignment: .leading, spacing: 2) {
                    if let place = idea.place ?? idea.area { Text(place).font(.geist(12)).foregroundStyle(Palette.textSoft).lineLimit(1) }
                    if let price = idea.price { Text(price).font(.geist(12)).foregroundStyle(Palette.textSoft).lineLimit(1) }
                    if let words = listing.noGerman { EventChip(text: words).padding(.top, 4) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                SaveButton(listing: listing, save: save)
            }
            .padding(.leading, 10).padding(.trailing, 8).padding(.top, 8).padding(.bottom, 10)
        }
        .background(Palette.surface)
        .clipShape(.rect(cornerRadius: 14))
        .overlay { RoundedRectangle(cornerRadius: 14).strokeBorder(Palette.border) }
    }
}

/// A pick: a big poster (ring, kind, tape, title, time), then its chip, Save and Add.
private struct PickCard: View {
    let listing: WhatsOn.Listing
    let day: WhatsOn
    let now: Date
    let save: (WhatsOn.Listing) -> Void
    let open: () -> Void

    var body: some View {
        let idea = listing.idea
        let night = WhatsOnLook.night(idea, now: now)
        let ink = night ? WhatsOnLook.yellow : WhatsOnLook.indigo
        VStack(spacing: 0) {
            Button(action: open) {
            ZStack(alignment: .topLeading) {
                Circle().strokeBorder(ink, lineWidth: 22).frame(width: 220, height: 220).opacity(0.85)
                    .offset(x: 272 - 220 + 70, y: -60)
                VStack(alignment: .leading, spacing: 8) {
                    Text(idea.category).font(.geist(12, .semibold)).foregroundStyle(ink)
                        .padding(.horizontal, 9).padding(.vertical, 3)
                        .background(.white.opacity(0.2), in: .capsule)
                        .overlay { Capsule().strokeBorder(.white.opacity(0.45)) }
                    Text(WhatsOnLook.stamp(idea, day: day, now: now).uppercased()).font(.posterMarker(14))
                        .foregroundStyle(Color(hex: 0x211D15))
                        .padding(.horizontal, 8).padding(.vertical, 2)
                        .background(WhatsOnLook.yellow)
                        .rotationEffect(.degrees(-2))
                    Spacer(minLength: 0)
                    HStack(alignment: .bottom, spacing: 10) {
                        Text(idea.title.uppercased()).font(.posterGrotesque(26)).lineSpacing(-4).lineLimit(3).foregroundStyle(ink)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        OutlinedText(text: WhatsOnLook.time(idea), size: 30, ink: ink)
                    }
                }
                .padding(14)
            }
            .frame(width: 272, height: 220, alignment: .topLeading)
            .background(night ? WhatsOnLook.indigo : WhatsOnLook.yellow)
            .clipped()
            }
            .buttonStyle(.plain)
            .accessibilityLabel("\(idea.title). Open details")
            HStack(spacing: 8) {
                if let words = listing.noGerman {
                    EventChip(text: words)
                } else if let price = idea.price {
                    Text(price).font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft).lineLimit(1)
                }
                Spacer(minLength: 0)
                SaveButton(listing: listing, save: save)
                AddButton(candidate: idea.candidate, date: day.date, title: idea.title)
                    .frame(width: 44, height: 44)
            }
            .padding(.leading, 12).padding(.trailing, 10).padding(.vertical, 10)
        }
        .frame(width: 272)
        .background(Palette.surface)
        .clipShape(.rect(cornerRadius: 18))
        .overlay { RoundedRectangle(cornerRadius: 18).strokeBorder(Palette.border) }
        .shadow(color: Color(hex: 0x19140C, opacity: 0.25), radius: 12, y: 8)
    }
}

/// The canvas's outlined time (-webkit-text-stroke): the numbers drawn as an outline in the ink.
struct OutlinedText: View {
    let text: String
    let size: CGFloat
    let ink: Color

    var body: some View {
        ZStack {
            ForEach(Array([(-1.0, 0.0), (1, 0), (0, -1), (0, 1), (-0.7, -0.7), (0.7, 0.7), (-0.7, 0.7), (0.7, -0.7)].enumerated()), id: \.offset) { _, shift in
                Text(text).font(.posterGrotesque(size)).foregroundStyle(ink).offset(x: shift.0 * 1.2, y: shift.1 * 1.2)
            }
            Text(text).font(.posterGrotesque(size)).foregroundStyle(.clear)
        }
        .fixedSize()
        .compositingGroup()
        .mask {
            ZStack {
                Rectangle()
                Text(text).font(.posterGrotesque(size)).blendMode(.destinationOut)
            }
            .compositingGroup()
        }
        .accessibilityLabel(text)
    }
}

/// rowin: each row in from 10 pt below on the spring, 30 ms after the one before; again on each change.
private struct RowIn: ViewModifier {
    let order: Int
    let round: Int
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var shown = false

    func body(content: Content) -> some View {
        content
            .opacity(shown || reduceMotion ? 1 : 0)
            .offset(y: shown || reduceMotion ? 0 : 10)
            .onAppear { play() }
            .onChange(of: round) { shown = false; play() }
    }

    private func play() {
        withAnimation(.spring(duration: 0.45, bounce: 0.3).delay(0.03 * Double(min(order, 12)))) { shown = true }
    }
}

/// Words that wrap onto the next line when the row is full.
private struct FlowLayout: Layout {
    var spacing: CGFloat = 8

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let width = proposal.width ?? .infinity
        var x: CGFloat = 0, y: CGFloat = 0, line: CGFloat = 0
        for view in subviews {
            let size = view.sizeThatFits(.unspecified)
            if x > 0, x + size.width > width { x = 0; y += line + spacing; line = 0 }
            x += size.width + spacing
            line = max(line, size.height)
        }
        return CGSize(width: width.isFinite ? width : x, height: y + line)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var x = bounds.minX, y = bounds.minY, line: CGFloat = 0
        for view in subviews {
            let size = view.sizeThatFits(.unspecified)
            if x > bounds.minX, x + size.width > bounds.maxX { x = bounds.minX; y += line + spacing; line = 0 }
            view.place(at: CGPoint(x: x, y: y), proposal: ProposedViewSize(size))
            x += size.width + spacing
            line = max(line, size.height)
        }
    }
}
