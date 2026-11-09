import API
import Core
import DesignSystem
import SwiftUI

/// A sheet's own top: its title and the close button.
private struct SheetHead: View {
    let title: String
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        HStack(alignment: .top) {
            Text(title).font(.posterGrotesque(26)).foregroundStyle(Palette.text).accessibilityAddTraits(.isHeader)
            Spacer()
            CloseButton { dismiss() }
        }
        .padding(.horizontal, 20).padding(.top, 22).padding(.bottom, 12)
    }
}

/// A field as the sheets draw them: its label over a quiet rounded box.
private struct Field<Content: View>: View {
    let label: String
    var optional = false
    @ViewBuilder let content: Content

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 6) {
                Text(label).font(.geist(14, .semibold)).foregroundStyle(Palette.text)
                if optional { Text("Optional").font(.geist(13)).foregroundStyle(Palette.textSoft) }
            }
            content
                .font(.geist(16.5)).foregroundStyle(Palette.text)
                .padding(.horizontal, 14).frame(minHeight: 50)
                .background(Palette.quiet, in: .rect(cornerRadius: 14, style: .continuous))
        }
    }
}

// MARK: - Add appointment

/// A personal activity in a day's plan. Nothing is chosen for them: the times stay empty until
/// they pick them.
struct AppointmentSheet: View {
    let day: String
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session
    @Environment(\.dismiss) private var dismiss
    @State private var name = ""
    @State private var location = ""
    @State private var date = Date()
    @State private var from: Date?
    @State private var until: Date?
    @FocusState private var naming: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            SheetHead(title: "Add appointment")
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    Field(label: "What’s the appointment?") {
                        TextField("e.g. Dentist", text: $name).focused($naming).submitLabel(.next)
                            .onChange(of: name) { if name.count > 120 { name = String(name.prefix(120)) } }
                    }
                    Field(label: "Where?", optional: true) {
                        TextField("Place or neighbourhood", text: $location)
                            .onChange(of: location) { if location.count > 160 { location = String(location.prefix(160)) } }
                    }
                    HStack(alignment: .top, spacing: 10) {
                        Field(label: "Day") {
                            DatePicker("Day", selection: $date, displayedComponents: .date).labelsHidden()
                                .frame(maxWidth: .infinity, alignment: .leading)
                        }
                        TimeField(label: "From", value: $from, suggested: Self.nextHour())
                        TimeField(label: "Until", value: $until, suggested: (from ?? Self.nextHour()).addingTimeInterval(3600))
                    }
                    Text("Adds a personal activity to your plan. It does not book an appointment or schedule a reminder.")
                        .font(.geist(13.5)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                    PaperworkButton(title: "Add to plan", symbol: "plus", grow: true, working: model.busy == "appointment") {
                        Task { await add() }
                    }
                    .disabled(!ready)
                    .opacity(ready ? 1 : 0.5)
                }
                .padding(.horizontal, 20).padding(.bottom, 20)
            }
            .scrollIndicators(.hidden)
        }
        .background(Palette.canvas)
        .presentationDetents([.medium, .large])
        .onAppear {
            date = ComposerModel.date(day) ?? Date()
            naming = true
        }
    }

    private var ready: Bool {
        guard let from, let until else { return false }
        return !name.trimmingCharacters(in: .whitespaces).isEmpty && until > from
    }

    private func add() async {
        guard let from, let until else { return }
        let time = DateFormatter()
        time.locale = Locale(identifier: "en_US_POSIX")
        time.timeZone = TimeZone(identifier: "Europe/Berlin")
        time.dateFormat = "HH:mm"
        let done = await model.appointment(name: name.trimmingCharacters(in: .whitespaces), location: location.trimmingCharacters(in: .whitespaces),
                                           date: ComposerModel.day(date), start: time.string(from: from), end: time.string(from: until), api: session.api)
        if done { dismiss() }
    }

    /// The next full hour: a starting suggestion once they tap Choose.
    static func nextHour() -> Date {
        (Calendar.current.dateInterval(of: .hour, for: Date())?.start ?? Date()).addingTimeInterval(3600)
    }
}

/// A time that starts empty: tap to choose one.
private struct TimeField: View {
    let label: String
    @Binding var value: Date?
    let suggested: Date

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(label).font(.geist(14, .semibold)).foregroundStyle(Palette.text)
            Group {
                if let current = value {
                    DatePicker(label, selection: Binding(get: { current }, set: { value = $0 }), displayedComponents: .hourAndMinute)
                        .labelsHidden()
                        .transition(.opacity)
                } else {
                    Button { withAnimation(Motion.ios(0.25)) { value = suggested } } label: {
                        Text("Choose").font(.geist(16, .semibold)).foregroundStyle(TodayLink.ink)
                            .frame(maxWidth: .infinity, minHeight: 50)
                            .background(Palette.quiet, in: .rect(cornerRadius: 14, style: .continuous))
                    }
                    .buttonStyle(PressStyle())
                    .accessibilityLabel("Choose \(label.lowercased()) time")
                }
            }
            .frame(minHeight: 50)
        }
        .frame(width: 96)
    }
}

// MARK: - Add a place or event

/// The day's places and events, each named as Around here names it, with its listed hours (or its
/// time), Save and Add.
struct AddPlaceSheet: View {
    let day: String
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(\.dismiss) private var dismiss
    @State private var date = Date()
    @State private var items: [ComposerSlot]?
    @State private var problem = false
    @State private var query = ""
    @State private var group = "All"
    @State private var saved: [String: Bool] = [:]

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            SheetHead(title: "Add a place or event")
            VStack(spacing: 10) {
                HStack(spacing: 8) {
                    Image(systemName: "magnifyingglass").font(.system(size: 15, weight: .semibold)).foregroundStyle(Palette.textSoft)
                    TextField("Places, events or neighbourhoods", text: $query).font(.geist(16.5)).foregroundStyle(Palette.text)
                    if !query.isEmpty {
                        Button { query = "" } label: { Image(systemName: "xmark.circle.fill").foregroundStyle(Palette.textSoft) }
                            .buttonStyle(.plain).accessibilityLabel("Clear search")
                    }
                }
                .padding(.horizontal, 14).frame(height: 46)
                .background(Palette.quiet, in: .rect(cornerRadius: 14, style: .continuous))
                HStack(spacing: 10) {
                    Label("Add to day", systemImage: "calendar").labelStyle(Tight()).font(.geist(14.5, .semibold)).foregroundStyle(Palette.text)
                    DatePicker("Add to day", selection: $date, in: Date()..., displayedComponents: .date).labelsHidden()
                    Spacer()
                    if groups.count > 1 {
                        Picker("Kind", selection: $group) {
                            ForEach(["All"] + groups, id: \.self) { Text($0).tag($0) }
                        }
                        .pickerStyle(.menu)
                        .tint(Palette.text)
                    }
                }
            }
            .padding(.horizontal, 20).padding(.bottom, 10)
            ScrollView {
                VStack(alignment: .leading, spacing: 10) {
                    if problem {
                        HStack {
                            Text("The places couldn’t be loaded.").font(.geist(15, .semibold)).foregroundStyle(Palette.conflictText)
                            Spacer()
                            PaperworkButton(title: "Try again", quiet: true, small: true) { Task { await load() } }
                        }
                    } else if items == nil {
                        HStack(spacing: 10) { ProgressView(); Text("Finding options…").font(.geist(15)).foregroundStyle(Palette.textSoft) }
                            .padding(.vertical, 20)
                    } else {
                        VStack(spacing: 0) {
                            ForEach(Array(shown.enumerated()), id: \.element.id) { index, item in
                                if index > 0 { PaperworkDivider() }
                                row(item)
                            }
                        }
                        .card(radius: 22)
                        if shown.isEmpty {
                            Text("No available matches for this day. Try another kind or date.").font(.geist(14)).foregroundStyle(Palette.textSoft)
                        }
                        Text("\(shown.count == 1 ? "1 option" : "\(shown.count) options") · Availability is checked again when you add one.")
                            .font(.geist(13)).foregroundStyle(Palette.textSoft)
                    }
                }
                .padding(.horizontal, 20).padding(.bottom, 24)
            }
            .scrollIndicators(.hidden)
            .scrollDismissesKeyboard(.immediately)
        }
        .background(Palette.canvas)
        .presentationDetents([.large])
        .onAppear { date = ComposerModel.date(day) ?? Date() }
        .task(id: ComposerModel.day(date)) { await load() }
    }

    private var available: [ComposerSlot] { (items ?? []).filter { $0.available != false } }
    private var groups: [String] { Array(NSOrderedSet(array: available.map { $0.group ?? "Places" })) as? [String] ?? [] }
    private var shown: [ComposerSlot] {
        available.filter { item in
            (group == "All" || (item.group ?? "Places") == group)
                && (query.isEmpty || "\(item.name) \(item.label ?? item.category ?? "") \(item.veedel ?? "")".localizedCaseInsensitiveContains(query))
        }
    }

    private func load() async {
        items = nil
        problem = false
        do { items = try await session.api.composerCatalogue(date: ComposerModel.day(date), saved: false, latitude: today.place?.latitude, longitude: today.place?.longitude) } catch {
            problem = true
        }
        if !groups.contains(group) { group = "All" }
    }

    private func row(_ item: ComposerSlot) -> some View {
        let planned = model.workspace?.plans[ComposerModel.day(date)]?.slots.contains { $0.id == item.id } ?? false
        let meta = [item.label ?? item.category?.replacingOccurrences(of: "_", with: " ").capitalized, item.type == "event" ? item.span : item.hours, item.veedel]
            .compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: " · ")
        let on = saved[item.id] ?? item.saved ?? false
        return HStack(alignment: .center, spacing: 10) {
            VStack(alignment: .leading, spacing: 2) {
                Text(item.name).font(.geist(16, .semibold)).foregroundStyle(Palette.text).lineLimit(2)
                Text(meta).font(.geist(13)).foregroundStyle(Palette.textSoft).lineLimit(2)
            }
            Spacer(minLength: 0)
            if item.type == "spot" {
                Button { Task { await save(item, on: !on) } } label: {
                    Image(systemName: on ? "bookmark.fill" : "bookmark").font(.system(size: 16, weight: .semibold))
                        .foregroundStyle(on ? Palette.action : Palette.text)
                        .contentTransition(.symbolEffect(.replace))
                        .frame(width: 40, height: 40)
                        .buttonFace(Palette.quiet)
                }
                .buttonStyle(PressStyle())
                .accessibilityLabel(on ? "Unsave \(item.name)" : "Save \(item.name)")
            }
            PaperworkButton(title: planned ? "In your plans" : "Add to \(ComposerModel.short(ComposerModel.day(date)))", quiet: true, small: true,
                            working: model.busy == "add-\(item.id)") {
                Task {
                    if await model.add(item.id, date: ComposerModel.day(date), api: session.api) { dismiss() }
                }
            }
            .disabled(planned)
        }
        .padding(.horizontal, 14).padding(.vertical, 11)
    }

    /// As Around here saves it: at once, put back when it fails.
    private func save(_ item: ComposerSlot, on: Bool) async {
        guard let spot = Int(item.id.replacingOccurrences(of: "spot:", with: "")) else { return }
        withAnimation(Motion.ios(0.25)) { saved[item.id] = on }
        do { try await session.api.placeFeedback(spot, action: on ? "saved" : "clear") } catch {
            withAnimation(Motion.ios(0.25)) { saved[item.id] = !on }
            model.say("That place wasn’t saved. Please try again.", problem: true)
        }
    }
}

// MARK: - Starting point

/// Where Composer plans new days from: here, one of their own places, or an address. Plans they
/// have stay as they are; a place in their message still counts.
struct StartSheet: View {
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(\.dismiss) private var dismiss
    @State private var query = ""
    @State private var hits: [ComposerGeoHit] = []
    @State private var own: [ComposerOrigin] = []
    @State private var finding = false
    @State private var problem: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            SheetHead(title: "Starting point")
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    Text("Where Composer plans new days from. Plans you have stay as they are; a place in your message still counts.")
                        .font(.geist(14)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                    HStack(spacing: 10) {
                        Image(systemName: "mappin").font(.system(size: 15, weight: .semibold)).foregroundStyle(Palette.action)
                        Text("Now: \(Text(model.start?.label ?? "Where you are").font(.geist(15, .semibold)).foregroundStyle(Palette.text))")
                            .font(.geist(15)).foregroundStyle(Palette.textSoft)
                    }
                    HStack(spacing: 8) {
                        Image(systemName: "magnifyingglass").font(.system(size: 15, weight: .semibold)).foregroundStyle(Palette.textSoft)
                        TextField("Search an address or place…", text: $query).font(.geist(16.5)).foregroundStyle(Palette.text)
                            .autocorrectionDisabled()
                            .onChange(of: query) { if query.count > 100 { query = String(query.prefix(100)) } }
                        if finding { ProgressView().controlSize(.small) }
                    }
                    .padding(.horizontal, 14).frame(height: 46)
                    .background(Palette.quiet, in: .rect(cornerRadius: 14, style: .continuous))
                    if let problem { Text(problem).font(.geist(14.5, .semibold)).foregroundStyle(Palette.conflictText) }
                    VStack(spacing: 0) {
                        if query.trimmingCharacters(in: .whitespaces).count >= 3 {
                            ForEach(Array(hits.enumerated()), id: \.offset) { index, hit in
                                if index > 0 { PaperworkDivider() }
                                row("mappin", hit.name, hit.address) { pick(ComposerStart(label: hit.name, lat: hit.lat, lng: hit.lng)) }
                            }
                        } else {
                            if let here = today.place {
                                row("location", "Use my current location", nil) {
                                    pick(ComposerStart(label: "Your location", lat: here.latitude, lng: here.longitude))
                                }
                            }
                            ForEach(Array(own.enumerated()), id: \.element.id) { index, place in
                                if index > 0 || today.place != nil { PaperworkDivider() }
                                row(place.category == "home" ? "house" : place.category == "work" ? "briefcase" : "mappin", place.name, place.address) {
                                    pick(ComposerStart(label: place.name, place: place.id))
                                }
                            }
                        }
                    }
                    .card(radius: 22)
                    if model.start != nil {
                        Button { pick(nil) } label: {
                            Text("Use where you are again").font(.geist(15, .semibold)).foregroundStyle(TodayLink.ink).frame(minHeight: 44)
                        }
                        .buttonStyle(PressStyle())
                    }
                }
                .padding(.horizontal, 20).padding(.bottom, 24)
            }
            .scrollIndicators(.hidden)
            .scrollDismissesKeyboard(.immediately)
        }
        .background(Palette.canvas)
        .presentationDetents([.medium, .large])
        .task { own = (try? await session.api.composerPlaces()) ?? [] }
        .task(id: query) { await search() }
    }

    private func search() async {
        let q = query.trimmingCharacters(in: .whitespaces)
        problem = nil
        guard q.count >= 3 else { hits = []; finding = false; return }
        try? await Task.sleep(for: .milliseconds(250))
        guard !Task.isCancelled else { return }
        finding = true
        defer { finding = false }
        do { hits = try await session.api.geocode(q) } catch {
            if !Task.isCancelled { problem = "Nothing could be searched just now. Try again in a moment." }
        }
    }

    private func pick(_ start: ComposerStart?) {
        withAnimation(Motion.ios(0.3)) { model.start = start }
        model.say(start.map { $0.place == nil && $0.label == "Your location" ? "Composer plans from your location now." : "Composer plans from \($0.label) now." }
            ?? "Composer plans from where you are.")
        dismiss()
    }

    private func row(_ symbol: String, _ title: String, _ meta: String?, pick: @escaping () -> Void) -> some View {
        Button(action: pick) {
            HStack(spacing: 12) {
                Image(systemName: symbol).font(.system(size: 15, weight: .semibold)).foregroundStyle(TodayLink.ink)
                    .frame(width: 36, height: 36).background(Palette.selectBackground, in: .rect(cornerRadius: 11, style: .continuous))
                VStack(alignment: .leading, spacing: 1) {
                    Text(title).font(.geist(16, .semibold)).foregroundStyle(Palette.text).lineLimit(1)
                    if let meta { Text(meta).font(.geist(13)).foregroundStyle(Palette.textSoft).lineLimit(1) }
                }
                Spacer(minLength: 0)
            }
            .padding(.horizontal, 14).padding(.vertical, 10)
            .contentShape(.rect)
        }
        .buttonStyle(PressStyle())
    }
}
