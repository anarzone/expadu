import API
import Core
import DesignSystem
import SwiftUI

/// My Plans and Saved, on the paper in place of the conversation (the canvas's .paper.pv): what
/// they add comes back to the conversation as a done line with Undo.
struct ComposerCollectionPaper: View {
    let collection: ComposerModel.Collection
    let open: (ComposerSheet) -> Void
    let ask: (String) -> Void

    var body: some View {
        ScrollView {
            Group {
                switch collection {
                case .plans: PlansView(open: open, ask: ask)
                case .saved: SavedView(open: open)
                }
            }
            .padding(.horizontal, 18).padding(.top, 20).padding(.bottom, 44)
        }
        .scrollIndicators(.hidden)
        .background(Palette.canvas, in: UnevenRoundedRectangle(topLeadingRadius: 32, topTrailingRadius: 32, style: .continuous))
        .shadow(color: Color(hex: 0x19140C, opacity: 0.3), radius: 20, y: -10)
        .ignoresSafeArea(edges: .bottom)
    }
}

/// What the box's + and the views open, each as its own sheet.
enum ComposerSheet: Identifiable, Equatable {
    case appointment(String)
    case add(String)
    case start
    case place(ComposerBlock.Place)

    var id: String {
        switch self {
        case let .appointment(day): "appointment-\(day)"
        case let .add(day): "add-\(day)"
        case .start: "start"
        case let .place(place): "place-\(place.key)"
        }
    }
}

// MARK: - My Plans

private struct PlansView: View {
    let open: (ComposerSheet) -> Void
    let ask: (String) -> Void
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        let day = model.day
        let plan = model.workspace?.plans[day]
        let slots = (plan?.slots ?? []).sorted { ($0.startAt ?? "") < ($1.startAt ?? "") }
        VStack(alignment: .leading, spacing: 16) {
            Text("My Plans").font(.posterGrotesque(30)).tracking(-0.4).foregroundStyle(Palette.text)
                .accessibilityAddTraits(.isHeader)
            WeekStrip()
            if !slots.isEmpty {
                Text("In your plans · \(ComposerModel.long(day))").font(.geist(14.5, .semibold)).foregroundStyle(Palette.textSoft)
                VStack(spacing: 0) {
                    ForEach(Array(slots.enumerated()), id: \.element.id) { index, slot in
                        if index > 0 { PaperworkDivider() }
                        SlotRow(slot: slot)
                    }
                }
                .card(radius: 22)
                .transition(.opacity.combined(with: .offset(y: 8)))
                Text("Routes and current availability need checking.").font(.geist(13)).foregroundStyle(Palette.textSoft)
                FlowButtons {
                    PaperworkButton(title: "Change it with Composer", symbol: "sparkle", quiet: true, small: true, working: model.busy == "open-plan") {
                        Task { await model.openPlan(day, api: session.api) }
                    }
                    PaperworkButton(title: "Add appointment", symbol: "plus", quiet: true, small: true) { open(.appointment(day)) }
                    PaperworkButton(title: "Cancel day", quiet: true, small: true, working: model.busy == "cancel-\(day)") {
                        Task { await model.cancelDay(day, api: session.api) }
                    }
                }
            } else {
                VStack(alignment: .leading, spacing: 12) {
                    Text("Nothing planned. Room for anything.").font(.geist(18, .bold)).foregroundStyle(Palette.text)
                    Text("Start with an idea, or ask Composer to help shape this day.").font(.geist(14.5)).foregroundStyle(Palette.textSoft)
                        .fixedSize(horizontal: false, vertical: true)
                    PaperworkButton(title: "Plan \(ComposerModel.short(day) == "today" ? "today" : ComposerModel.short(day)) with Composer", symbol: "sparkle", small: true) {
                        ask("Plan \(ComposerModel.long(day))")
                    }
                    FlowButtons {
                        PaperworkButton(title: "Choose from saved ideas", quiet: true, small: true) {
                            model.push(.saved)
                        }
                        PaperworkButton(title: "Add appointment", symbol: "plus", quiet: true, small: true) { open(.appointment(day)) }
                    }
                }
                .padding(16).frame(maxWidth: .infinity, alignment: .leading).card(radius: 22)
                .transition(.opacity.combined(with: .offset(y: 8)))
            }
        }
        .animation(reduceMotion ? nil : Motion.ios(0.35), value: day)
        .animation(reduceMotion ? nil : Motion.ios(0.35), value: slots.map(\.id))
    }
}

/// The week the day is in, Monday first; a dot under each planned day. Swipe for another week.
private struct WeekStrip: View {
    @Environment(ComposerModel.self) private var model
    @Environment(TodayModel.self) private var today

    var body: some View {
        let days = Self.week(of: model.day)
        HStack(spacing: 5) {
            ForEach(days, id: \.self) { day in
                let on = day == model.day
                Button {
                    withAnimation(Motion.ios(0.3)) { model.day = day }
                } label: {
                    VStack(spacing: 2) {
                        Text(Self.weekday(day)).font(.geist(11.5)).foregroundStyle(on ? Palette.navTeal.opacity(0.8) : Palette.textSoft).lineLimit(1)
                        Text(String(Int(day.suffix(2)) ?? 0)).font(.geist(16, .bold)).monospacedDigit().foregroundStyle(on ? Palette.navTeal : Palette.text)
                        Circle().fill(model.planned(day, todayHas: !(today.page?.plans.isEmpty ?? true)) ? Palette.action : .clear)
                            .frame(width: 5, height: 5)
                    }
                    .frame(maxWidth: .infinity).padding(.vertical, 8)
                    .background(on ? Palette.selectBackground : Palette.surface, in: .rect(cornerRadius: 14, style: .continuous))
                    .overlay { if !on { RoundedRectangle(cornerRadius: 14, style: .continuous).strokeBorder(Palette.border, lineWidth: 1) } }
                    .contentShape(.rect)
                }
                .buttonStyle(PressStyle())
                .accessibilityLabel("\(ComposerModel.long(day))\(model.planned(day, todayHas: false) ? ", has a plan" : "")")
                .accessibilityAddTraits(on ? .isSelected : [])
            }
        }
        .gesture(
            DragGesture(minimumDistance: 24).onEnded { drag in
                guard abs(drag.translation.width) > 60, abs(drag.translation.width) > abs(drag.translation.height) else { return }
                step(drag.translation.width < 0 ? 7 : -7)
            }
        )
        .accessibilityElement(children: .contain)
        .accessibilityAdjustableAction { direction in step(direction == .increment ? 7 : -7) }
        .sensoryFeedback(.selection, trigger: model.day)
    }

    private func step(_ days: Int) {
        guard let date = ComposerModel.date(model.day), let next = Calendar.current.date(byAdding: .day, value: days, to: date) else { return }
        withAnimation(Motion.ios(0.35)) { model.day = ComposerModel.day(next) }
    }

    static func week(of day: String) -> [String] {
        guard let date = ComposerModel.date(day) else { return [day] }
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TimeZone(identifier: "Europe/Berlin") ?? .current
        let weekday = (calendar.component(.weekday, from: date) + 5) % 7
        return (0..<7).compactMap { calendar.date(byAdding: .day, value: $0 - weekday, to: date).map(ComposerModel.day) }
    }

    static func weekday(_ day: String) -> String {
        guard let date = ComposerModel.date(day) else { return "" }
        let format = DateFormatter()
        format.locale = Locale(identifier: "en_GB")
        format.timeZone = TimeZone(identifier: "Europe/Berlin")
        format.dateFormat = "EEE"
        return format.string(from: date)
    }
}

/// One thing in the day's plan: its time, its name and what it is.
private struct SlotRow: View {
    let slot: ComposerSlot

    var body: some View {
        let process = slot.type == "bureaucracy"
        let fixed = slot.type == "event" || process
        let appointment = slot.type == "appointment" || slot.isAppointment == true
        let what: String? = process ? "Recorded appointment" : fixed ? "Fixed event" : appointment ? "Personal appointment" : nil
        let place = slot.location ?? slot.veedel ?? (slot.type == "task" ? "Preparation time" : "Location to be added")
        HStack(alignment: .top, spacing: 12) {
            VStack(alignment: .leading, spacing: 1) {
                Text(slot.startTime ?? "").font(.geist(15.5, .bold)).monospacedDigit().foregroundStyle(Palette.text)
                if slot.endListed != false, let end = slot.endTime, !end.isEmpty {
                    Text(end).font(.geist(13.5)).monospacedDigit().foregroundStyle(Palette.textSoft)
                }
            }
            .frame(width: 52, alignment: .leading)
            .accessibilityElement(children: .ignore)
            .accessibilityLabel(slot.span.replacingOccurrences(of: "–", with: " to "))
            RoundedRectangle(cornerRadius: 2).fill(process ? PaperworkLook.purple : fixed || appointment ? Palette.action : Palette.border)
                .frame(width: 4).frame(minHeight: 34)
            VStack(alignment: .leading, spacing: 2) {
                Text(slot.name).font(.geist(16, .semibold)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                Text([what, place].compactMap { $0 }.joined(separator: " · ")).font(.geist(13.5)).foregroundStyle(Palette.textSoft)
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 14).padding(.vertical, 12)
        .accessibilityElement(children: .combine)
    }
}

/// Buttons that wrap onto a second line when they don't fit.
struct FlowButtons<Content: View>: View {
    @ViewBuilder let content: Content

    var body: some View {
        FlowLayout(spacing: 8) { content }
    }
}

// MARK: - Saved

/// Saved ideas wait here, unscheduled, until added to a day: saved places from Around here and
/// Composer, saved events from Events.
private struct SavedView: View {
    let open: (ComposerSheet) -> Void
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var events: [ComposerSavedEvent]?
    @State private var places: [ComposerSlot]?
    @State private var problem: String?

    var body: some View {
        let count = (events?.count ?? 0) + (places?.count ?? 0)
        VStack(alignment: .leading, spacing: 14) {
            VStack(alignment: .leading, spacing: 2) {
                Text("Saved").font(.posterGrotesque(30)).tracking(-0.4).foregroundStyle(Palette.text)
                    .accessibilityAddTraits(.isHeader)
                if events != nil, places != nil, count > 0 {
                    Text(count == 1 ? "1 idea" : "\(count) ideas").font(.geist(14.5)).foregroundStyle(Palette.textSoft)
                        .contentTransition(.numericText())
                }
            }
            if let problem {
                HStack(spacing: 10) {
                    Text(problem).font(.geist(14.5, .semibold)).foregroundStyle(Palette.conflictText)
                    Spacer(minLength: 0)
                    PaperworkButton(title: "Try again", quiet: true, small: true) { Task { await load() } }
                }
            }
            if events == nil || places == nil {
                HStack(spacing: 10) { ProgressView(); Text("Loading saved ideas…").font(.geist(15)).foregroundStyle(Palette.textSoft) }
                    .padding(.vertical, 20)
            } else if count == 0 {
                VStack(alignment: .leading, spacing: 10) {
                    Text("Keep something that catches your eye.").font(.geist(18, .bold)).foregroundStyle(Palette.text)
                    Text("Save a place or event here, in Around here or in Events. It waits here until you add it to a day.")
                        .font(.geist(14.5)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                    PaperworkButton(title: "Find a place or event", quiet: true, small: true) { open(.add(model.day)) }
                }
                .padding(16).frame(maxWidth: .infinity, alignment: .leading).card(radius: 22)
            } else {
                ForEach(events ?? []) { event in eventCard(event).transition(.opacity.combined(with: .scale(scale: 0.96))) }
                ForEach(places ?? []) { place in placeCard(place).transition(.opacity.combined(with: .scale(scale: 0.96))) }
            }
        }
        .animation(reduceMotion ? nil : Motion.ios(0.35), value: (events ?? []).map(\.id) + (places ?? []).map(\.id))
        .task(id: model.day) { await load() }
    }

    private func load() async {
        async let saved = try? session.api.savedEvents()
        async let kept = try? session.api.composerCatalogue(date: model.day, saved: true, latitude: today.place?.latitude, longitude: today.place?.longitude)
        let (a, b) = await (saved, kept)
        events = a ?? []
        places = b ?? []
        problem = a == nil || b == nil ? "Some saved ideas couldn’t be loaded." : nil
    }

    private func eventCard(_ event: ComposerSavedEvent) -> some View {
        let meta = [event.event?.categoryLabel ?? "Event", Self.when(event.occurrenceStart), event.event?.venue?.veedel ?? event.event?.venue?.name]
            .compactMap { $0 }.joined(separator: " · ")
        return card(symbol: "calendar", title: event.title, meta: event.available == false ? "No longer on" : meta) {
            if event.available == false {
                Text("No longer available to plan.").font(.geist(13.5)).foregroundStyle(Palette.textSoft)
            }
            Spacer(minLength: 0)
            PaperworkButton(title: "Unsave", quiet: true, small: true) { Task { await unsave(event) } }
        }
    }

    private func placeCard(_ place: ComposerSlot) -> some View {
        let label = place.label ?? place.category?.replacingOccurrences(of: "_", with: " ").capitalized
        let meta = [label, place.veedel, place.available == false ? "Closed on this day" : place.hours].compactMap { $0 }.joined(separator: " · ")
        return card(symbol: PlaceSymbol.name(place.kind ?? place.category ?? ""), title: place.name, meta: meta) {
            if place.available == false {
                Text("No longer available to plan.").font(.geist(13.5)).foregroundStyle(Palette.textSoft)
            } else {
                PaperworkButton(title: "Add to \(ComposerModel.short(model.day))", symbol: "plus", quiet: true, small: true, working: model.busy == "add-\(place.id)") {
                    Task { await model.add(place.id, date: model.day, api: session.api) }
                }
            }
            Spacer(minLength: 0)
            PaperworkButton(title: "Unsave", quiet: true, small: true) { Task { await unsave(place) } }
        }
    }

    private func card(symbol: String, title: String, meta: String, @ViewBuilder actions: () -> some View) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(alignment: .top, spacing: 12) {
                Image(systemName: symbol).font(.system(size: 16, weight: .semibold)).foregroundStyle(TodayLink.ink)
                    .frame(width: 40, height: 40).background(Palette.selectBackground, in: .rect(cornerRadius: 12, style: .continuous))
                VStack(alignment: .leading, spacing: 2) {
                    Text(title).font(.geist(16.5, .bold)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                    if !meta.isEmpty { Text(meta).font(.geist(13.5)).foregroundStyle(Palette.textSoft) }
                }
                Spacer(minLength: 0)
            }
            HStack(spacing: 8) { actions() }
        }
        .padding(14).frame(maxWidth: .infinity, alignment: .leading).card(radius: 22)
    }

    /// Unsaved at once; put back when it fails.
    private func unsave(_ event: ComposerSavedEvent) async {
        let before = events
        events?.removeAll { $0.id == event.id }
        guard let start = TodayPage.parse(event.occurrenceStart) else { return }
        do { _ = try await session.api.saveEvent(id: event.eventId, startsAt: start, saved: false); model.say("Unsaved.") } catch {
            events = before
            model.say("That didn’t go through. Try again.", problem: true)
        }
    }

    private func unsave(_ place: ComposerSlot) async {
        let before = places
        places?.removeAll { $0.id == place.id }
        guard let spot = Int(place.id.replacingOccurrences(of: "spot:", with: "")) else { return }
        do { try await session.api.placeFeedback(spot, action: "clear"); model.say("Unsaved.") } catch {
            places = before
            model.say("That didn’t go through. Try again.", problem: true)
        }
    }

    /// "Sat 14 Nov · 19:30".
    static func when(_ iso: String) -> String? {
        guard let date = TodayPage.parse(iso) else { return nil }
        let format = DateFormatter()
        format.locale = Locale(identifier: "en_GB")
        format.timeZone = TimeZone(identifier: "Europe/Berlin")
        format.dateFormat = "EEE d MMM · HH:mm"
        return format.string(from: date)
    }
}
